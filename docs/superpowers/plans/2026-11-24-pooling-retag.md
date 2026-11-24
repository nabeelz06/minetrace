# Penghimpunan batch & tag baru (Wet/Dry) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Batch yang masuk Wet/Dry dilepas tag-nya dan dihimpun. Drum keluar dengan UID baru menjadi batch gabungan yang silsilahnya tercatat ke semua batch asal.

**Architecture:** Tabel `batch_pools`, `batch_pool_inputs`, `batch_lineage`, dan satu `PoolService` yang dipanggil dari `StageTransitionService::receive()`, gate RFID (`RfidScanService`), dan controller web. Penelusuran rekursif lewat `LineageService` (CTE rekursif).

**Tech Stack:** Laravel 11, PHP 8.3, PostgreSQL 16, Inertia + Vue 3, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-24-pooling-retag-design.md`

## Global Constraints

- Satu drum Wet selalu mengambil **seluruh** himpunan Wet saat itu.
- Dry: sampai 3 drum (MON/ZIR/ILM) per himpunan, satu produk satu drum, drum ke-4 ditolak.
- Status himpunan: `collecting` → `outputting` → `closed`. Per (company, stage) maks satu `collecting` dan satu `outputting`.
- Drum keluar hanya menerima UID tanpa riwayat. Kode tolak `tag_recycled`, `pool_empty`, `pool_full`.
- Tag bekas hanya untuk batch baru lewat registrasi biasa.
- "Batalkan drum" hanya selama `awaiting_weight`. Lineage/transformasi di-void, tidak dihapus.
- `batches.rfid_tag` UNIQUE (NULL boleh banyak).
- Teks UI ID/EN. Pesan server berbahasa Indonesia seperti kode yang ada.
- Push langsung ke `main` (keputusan pemilik). Cadangan: branch & tag `backup/pre-pooling-2026-11-24` = `30ccf42`.

## Review Focus

- Dua scan UID baru hampir bersamaan di Wet OUT: hanya satu drum terbentuk; yang kedua `pool_empty`. Tes di Task 3.
- Batch `in_transit` dari alur lama diterima di Dry: tetap bisa diterima dengan tag lama, lalu masuk himpunan. Tes di Task 2.
- Batch `awaiting_weight` dicoba dikirim/diterima lewat web atau RFID: ditolak "Drum belum ditimbang". Tes di Task 4.
- UID drum Dry dipindai ulang di Dry OUT (dedupe gate): tidak membuat drum kedua karena UID kini dikenal. Tes di Task 5.
- Batch `pooled` tidak muncul di daftar batch aktif / incoming. Tes di Task 6.

---

### Task 0: Lingkungan lokal

- [ ] PostgreSQL 16 portable di `C:\Users\kapro\tools\pgsql`, data di `C:\Users\kapro\tools\pgdata`, port 5432, user `postgres` trust lokal.
- [ ] Buat DB `minetra_local` dan `minetrace_test`. Buat `.env.testing` (tidak di-commit) dengan `DB_DATABASE=minetrace_test`.
- [ ] `php artisan test` di main sebagai baseline. Catat tes yang sudah gagal sebelum perubahan.

### Task 1: Skema & model

**Files:** Create `database/migrations/2026_11_24_000001_create_batch_pools_tables.php`, `app/Models/BatchPool.php`, `app/Models/BatchPoolInput.php`, `app/Models/BatchLineage.php`. Modify `app/Models/Batch.php`, `app/Support/ProcessFlow.php`, `app/Models/RfidTagEvent.php`. Test `tests/Feature/Pool/PoolSchemaTest.php`.

**Produces:**
- `BatchPool` (`STATUS_COLLECTING='collecting'`, `STATUS_OUTPUTTING='outputting'`, `STATUS_CLOSED='closed'`), relasi `inputs()`, `outputs()` (batches by `pool_id`).
- `Batch::pool()`, `Batch::poolInput()`, `Batch::lineageParents()`, `Batch::lineageChildren()` (abaikan `voided_at`).
- `ProcessFlow::STATUS_POOLED='pooled'`, `STATUS_AWAITING_WEIGHT='awaiting_weight'`, `pooled` di `CLOSED_STATUSES`.
- `RfidTagEvent::REASONS` + `'pooled'`.
- Migrasi pgsql: ganti CHECK `batches_status_check` (tambah `pooled`, `awaiting_weight`) dan `batch_transformations_transformation_type_check` (tambah `pooling`); kolom `voided_at`, `voided_by`, `void_reason` di `batch_transformations` dan `batch_lineage`; `batches.pool_id`; UNIQUE `rfid_tag` setelah cek duplikat (gagal dengan daftar UID duplikat).

- [ ] Tes: `rfid_tag_unique_is_enforced` (insert UID sama dua kali → `UniqueConstraintViolationException`), `status_accepts_pooled_and_awaiting_weight`, `two_null_tags_allowed`.
- [ ] Migrasi + model, tes lulus, commit.

### Task 2: Masuk Wet/Dry → himpunan

**Files:** Create `app/Services/Process/PoolService.php`. Modify `app/Services/Process/StageTransitionService.php::receive`, `app/Services/Hardware/RfidTagService.php::release` (`?User $user`). Test `tests/Feature/Pool/PoolJoinTest.php`.

**Produces:** `PoolService::join(Batch $batch, ?int $userId): BatchPool` (dipanggil di dalam transaksi `receive()` bila `$stage` ∈ {wet_process, dry_process}); `PoolService::collecting(int $companyId, string $stage): BatchPool` (firstOrCreate, `lockForUpdate`).

- [ ] Tes: `receive_into_wet_releases_tag_and_joins_pool` (tag null, `rfid_tag_events` reason `pooled`, `released_rfid_tag` tersimpan, `weight_at_join` = current_weight), `untagged_batch_still_joins`, `second_batch_joins_same_pool`, `receive_into_dry_from_legacy_in_transit_works`, `receive_into_warehouse_does_not_pool`.
- [ ] Implementasi, tes lulus, commit.

### Task 3: Drum keluar

**Files:** Modify `PoolService`. Test `tests/Feature/Pool/PoolOutputTest.php`.

**Produces:** `PoolService::createOutputDrum(int $companyId, string $stage, string $uid, ?int $userId, string $source = 'web'): array{ok:bool, code:string, message:string, batch?:Batch, pool?:BatchPool}`; `PoolService::uidHasHistory(int $companyId, string $uid): bool`.
- Wet: tutup himpunan langsung (`closed`), input → `pooled`, `batch_lineage` per input, `batch_transformations` tipe `pooling`, output `awaiting_weight` di `wet_process`, produk = produk input pertama.
- Dry: himpunan `outputting` bila ada, else `collecting` → `outputting`; output `awaiting_weight`, `product_code_id` null bila kolom mengizinkan, else produk input (lalu diganti saat timbang); maks 3.
- Kode batch dari `BatchCodeService::generate`. Checkpoint `forward_cp` tahap dengan `checkpoint_data.action='pool_output'`.

- [ ] Tes: `wet_drum_takes_whole_pool`, `empty_pool_rejected` (`pool_empty`), `recycled_uid_rejected` (`tag_recycled`), `uid_in_use_rejected`, `dry_drum_moves_pool_to_outputting_and_new_arrivals_go_to_new_pool`, `fourth_dry_drum_rejected` (`pool_full`), `concurrent_wet_drums_only_one_created` (dua panggilan berurutan dengan UID beda → kedua `pool_empty`).
- [ ] Implementasi, tes lulus, commit.

### Task 4: Timbang, selesai himpunan, batalkan drum

**Files:** Modify `PoolService`, `StageTransitionService::receiveError` & `dispatchError`. Test `tests/Feature/Pool/PoolWeighTest.php`.

**Produces:** `PoolService::recordDrumWeight(Batch $drum, float $weight, ?int $productCodeId, int $userId): Batch`; `PoolService::closeDryPool(BatchPool $pool, int $userId): BatchPool`; `PoolService::cancelDrum(Batch $drum, int $userId, string $reason): void`.
- Wet: berat → `initial_weight`/`current_weight`, status `in_transit`, neraca massa himpunan (`loss_pct = (input − output)/input × 100`, `has_discrepancy` dari `MassBalanceThreshold` tahap).
- Dry: berat + produk (MON/ZIR/ILM, unik per himpunan) disimpan, tetap `awaiting_weight` sampai `closeDryPool` → semua drum `in_transit`, input `pooled`, lineage dengan `contributed_weight` proporsional.
- `receiveError`/`dispatchError`: `awaiting_weight` → "Drum belum ditimbang".
- `cancelDrum`: output soft-delete, tag dilepas (event `released`, reason `other`), Wet: himpunan → `collecting`, input → `in_process`, lineage & transformasi di-void; Dry: bila tak ada drum tersisa → `collecting`.

- [ ] Tes: `wet_weight_finalizes_and_computes_loss`, `dry_close_requires_all_drums_weighed`, `dry_duplicate_product_rejected`, `dry_close_creates_proportional_lineage`, `awaiting_weight_cannot_be_received`, `cancel_wet_drum_reopens_pool`, `cancel_after_weight_rejected`.
- [ ] Implementasi, tes lulus, commit.

### Task 5: Gate RFID & registrasi

**Files:** Modify `app/Services/Hardware/RfidScanService.php::process`, `app/Services/Hardware/RfidRegistrationService.php::commitInternal`. Test `tests/Feature/Pool/PoolGateTest.php`, update `tests/Feature/Rfid/UhfGateChainTest.php`.

- UID tak dikenal + reader `stage` ∈ {wet_process, dry_process} + arah `out` + strategi bukan `audit_only` → `PoolService::createOutputDrum(..., source: 'rfid')`. Hasil ok → `status=success`, `code=pool_output_created`; tolak → `failed` dengan kode dari PoolService. Lainnya tetap `tag_unknown`.
- Registrasi: checkpoint memakai `record_cp` tahap batch, bukan selalu `lab_kimia_create`.

- [ ] Tes: `unknown_uid_at_wet_out_creates_drum`, `unknown_uid_at_wet_in_stays_tag_unknown`, `audit_only_mode_does_not_create_drum`, `rescan_of_new_drum_uid_does_not_create_second_drum`, rantai penuh di `UhfGateChainTest` disesuaikan (Receiving → Wet IN lepas tag → UID baru Wet OUT → timbang → Dry IN → 1 drum Dry OUT → timbang+produk → selesai → Gudang IN).
- [ ] Implementasi, tes lulus, commit.

### Task 6: Web Wet/Dry

**Files:** Modify `app/Http/Controllers/Company/ProcessController.php`, `routes/web.php`, `resources/js/Pages/Company/Process/Stage.vue`. Create `resources/js/components/process/PoolPanel.vue`. Test `tests/Feature/Pool/PoolWebTest.php`.

- Prop `pool` di `ProcessController::stage` untuk wet/dry: `{collecting:{id, inputs:[{batch_code, weight}], total_weight, opened_at}|null, outputting:{...}|null, drums:[{id,batch_code,rfid_tag,product_code,weight,status}]}`.
- Route (prefix & middleware sama dengan rute proses yang ada): `POST process/{stage}/pool/drum` (`uid`), `POST process/pool/drums/{batch}/weight` (`weight`, `product_code_id?`), `POST process/pool/{pool}/close`, `POST process/pool/drums/{batch}/cancel` (`reason`).
- Panel "Sedang dihimpun", daftar drum menunggu timbang + form, tombol "Drum keluar", "Selesai himpunan" (Dry), "Batalkan drum". Tombol Transform lama disembunyikan. Batch `pooled` tidak tampil di daftar aktif.

- [ ] Tes endpoint (otorisasi peran tahap, validasi, alur sukses) dan prop `pool`.
- [ ] Implementasi, `npm run build` lulus, commit.

### Task 7: Penelusuran

**Files:** Create `app/Services/Batch/LineageService.php`. Modify `BatchController::show`, `Public/VerifyController`, `resources/views/pdf/reports/batch-traceability.blade.php`, `Batches/Show.vue`, `Public/VerifyBatch.vue`. Test `tests/Feature/Pool/LineageTest.php`, update `tests/Feature/Public/VerifyBatchTest.php`.

**Produces:** `LineageService::ancestors(Batch $batch): Collection` (CTE rekursif atas `batch_lineage` non-void + `parent_batch_id` lama, dengan `depth`), `LineageService::descendants(Batch $batch): Collection`.

- [ ] Tes: drum Gudang menelusuri sampai batch Lab Kimia (3 tingkat), void lineage tidak ikut, QR publik menampilkan batch asal dan tidak menampilkan checkpoint void.
- [ ] Implementasi, tes lulus, commit.

### Task 8: Backfill & rilis

**Files:** Create `app/Console/Commands/PoolBackfillCommand.php` (`batches:pool-backfill {--dry-run}`). Test `tests/Feature/Pool/PoolBackfillTest.php`. Update `DEPLOYMENT.md`.

- [ ] Tes: hanya `in_process` di wet/dry yang dihimpun; `in_transit` tidak disentuh; `--dry-run` tidak menulis.
- [ ] Seluruh suite lulus (kecuali kegagalan baseline Task 0), `npm run build` lulus.
- [ ] Jalankan lokal (`php artisan serve` + `npm run dev`), cek manual alur Wet/Dry.
- [ ] Merge ke `main`, push.
