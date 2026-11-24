# Receiving + Wet digabung Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Satu halaman/reader "Receiving & Wet". Drum besi ditimbang dan dicek kadar Sn lalu langsung masuk himpunan Wet. Jenis wadah tercatat. Reader Dry membuat drum produk dari UID baru di arah apa pun.

**Architecture:** Data tetap dua tahap. `IntakeService` menggabungkan timbang Receiving dengan terima ke Wet. `RfidScanService` merutekan scan berdasarkan stage reader dan status tag. Halaman `receiving` merender panel Wet (`PoolPanel`, stage `wet_process`) dan panel intake baru.

**Tech Stack:** Laravel 11, PostgreSQL 16, Inertia + Vue 3, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-24-receiving-wet-merge-design.md`

## Global Constraints

- `container_type` ∈ `drum_besi` | `container` | `product_drum` | null (data lama).
- `sn_grade_pct` decimal(6,3), 0–100, wajib saat intake.
- Status baru `awaiting_intake`; guard sama dengan `awaiting_weight`.
- Susut melewati ambang: tetap masuk Wet, `has_discrepancy = true`.
- Kadar Sn tidak tampil di QR publik.
- Tag terdaftar lain di reader `receiving` → audit saja (tanpa perpindahan).
- Teks ID/EN.

## Review Focus

- Tag drum besi `awaiting_intake` terbaca berulang di reader Receiving: audit saja, tidak masuk Wet tanpa berat & kadar. Tes Task 3.
- Container `awaiting_weight` di timbangan Receiving terbaca reader: audit saja, tidak membuat drum kedua. Tes Task 3.
- Batch lama `receiving/in_process` tetap bisa intake. Tes Task 2.
- Batch lama `receiving/in_transit` tetap bisa diterima ke Wet lewat web. Tes Task 4.
- Mode `audit_only` untuk Receiving: tap drum besi tidak memindahkan. Tes Task 3.

---

### Task 1: Skema, model, jenis wadah

**Files:** Create `database/migrations/2026_11_24_000002_add_container_type_and_sn_grade_to_batches.php`. Modify `app/Models/Batch.php`, `app/Support/ProcessFlow.php`, `app/Services/Batch/BatchService.php::createBatch`, `app/Services/Process/PoolService.php::makeDrum`. Test `tests/Feature/Pool/ContainerTypeTest.php`.

**Produces:** `ProcessFlow::STATUS_AWAITING_INTAKE = 'awaiting_intake'`; `Batch::CONTAINER_TYPES = ['drum_besi','container','product_drum']`; `Batch::containerLabel(?string $type, string $lang = 'id'): string`.

- [ ] Tes: `lab_batch_is_drum_besi`, `wet_drum_is_container`, `dry_drum_is_product_drum`, `status_accepts_awaiting_intake`.
- [ ] Implementasi (CHECK status ditambah `awaiting_intake`), tes lulus, commit.

### Task 2: Intake (timbang + kadar Sn → Wet)

**Files:** Create `app/Services/Process/IntakeService.php`. Modify `StageTransitionService::receive` (tujuan `receiving` → status `awaiting_intake`), `receiveError`/`dispatchError` (awaiting_intake ditolak seperti awaiting_weight). Test `tests/Feature/Pool/IntakeTest.php`.

**Produces:** `IntakeService::record(Batch $batch, float $weight, float $snPct, ?int $userId, string $source = 'web'): Batch` — menerima batch `receiving` berstatus `awaiting_intake` atau `in_process`; checkpoint `receiving_verify` (`action=intake`, berat, susut, Sn); `current_weight` = berat; `sn_grade_pct`; `has_discrepancy` dari ambang `receiving`; status `in_process`; lalu `StageTransitionService::receive($batch,'wet_process')` (masuk himpunan Wet, tag dilepas).

- [ ] Tes: `receive_at_receiving_sets_awaiting_intake`, `intake_records_weight_sn_and_joins_wet_pool`, `intake_over_threshold_still_joins_and_flags`, `intake_rejects_sn_out_of_range`, `legacy_receiving_in_process_can_intake`, `awaiting_intake_cannot_be_dispatched`.
- [ ] Implementasi, tes lulus, commit.

### Task 3: Gate dua reader

**Files:** Modify `app/Services/Hardware/RfidScanService.php` (`process`, `tryPoolDrum`). Test `tests/Feature/Pool/TwoReaderGateTest.php`.

- Reader `receiving`: UID asing → `createOutputDrum(...,'wet_process')` (mode tahap `wet_process` ≠ audit_only), arah apa pun. Tag terdaftar: batch `lab_kimia/in_transit` → strategi tahap `receiving` Scan IN; lainnya → `AuditOnlyStrategy::handleScanIn`.
- Reader `dry_process`: UID asing → drum Dry, arah apa pun.
- Reader `wet_process`: tetap (UID asing hanya arah out).

- [ ] Tes: `drum_besi_tap_goes_to_awaiting_intake`, `repeat_tap_of_awaiting_intake_is_audit_only`, `unknown_uid_at_receiving_reader_creates_wet_container`, `container_on_receiving_scale_is_audit_only`, `unknown_uid_at_dry_reader_in_direction_creates_product_drum`, `receiving_audit_only_mode_does_not_move`.
- [ ] Implementasi, tes lulus, commit.

### Task 4: Halaman gabungan

**Files:** Modify `ProcessController::stage` (GET `wet_process` → redirect `receiving`; prop `pool` untuk `receiving` = ringkasan `wet_process`; prop `intake` = daftar `awaiting_intake` + `receiving/in_process`; prop `legacy_to_wet` = `receiving/in_transit`), label & nav (`all_stages` tanpa `wet_process`, label `receiving` "Receiving & Wet"), `routes/web.php` (`POST process/receiving/intake/{batch}` → `PoolController::intake`), `Stage.vue`, create `resources/js/components/process/IntakePanel.vue`. Test `tests/Feature/Pool/MergedPageTest.php`.

- [ ] Tes: `wet_page_redirects_to_receiving`, `receiving_page_has_intake_and_wet_pool`, `intake_endpoint_validates_and_joins_pool`, `legacy_in_transit_received_into_wet_via_web`.
- [ ] Implementasi, `npm run build`, tes lulus, commit.

### Task 5: Tampilan wadah & Sn

**Files:** Modify `BatchController::show` (`container_type`, `container_label`, `sn_grade_pct`), `Batches/Show.vue`, `Public/VerifyController` + `VerifyBatch.vue` (jenis wadah saja), `pdf/reports/batch-traceability.blade.php` (kolom Wadah, Sn %), `PoolPanel.vue` (label "Container" untuk Wet, "Drum produk" untuk Dry). Test `tests/Feature/Pool/ContainerDisplayTest.php`.

- [ ] Tes: detail batch memuat `sn_grade_pct` & `container_label`; QR publik memuat jenis wadah dan **tidak** memuat Sn.
- [ ] Implementasi, tes lulus, commit.

### Task 6: Regresi & rilis

- [ ] Jalankan tes Pool + Rfid + Process + Public (Ur4Link dikecualikan, gagal di Windows sejak awal).
- [ ] Sesuaikan tes lama yang mengandalkan Receiving `in_process` setelah terima.
- [ ] `DEPLOYMENT.md`: setel 2 reader (stage `receiving` & `dry_process`, arah `any`), mode Receiving/Wet/Dry bukan "Catat saja".
- [ ] Jalankan lokal, beri tahu pemilik.
