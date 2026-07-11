# MINETRA — Environment Migration Checklist

Reference for switching from local development to a confirmed production
target. The hosting/VPS decision is **not finalized yet** — this document is
deploy-target-agnostic; every item below is driven by `.env` values, not
hardcoded code paths (see Section 2 audit in the 2026-07-06 changelog entry
of `MINETRA_PROJECT_RESUME_COMPLETE.md` for the full audit trail).

## 1. Required `.env` differences (local → production)

| Variable | Local (`.env`) | Production (`.env.production`) | Notes |
|---|---|---|---|
| `APP_ENV` | `local` | `production` | |
| `APP_DEBUG` | `true` | `false` | Never `true` in production — leaks stack traces. |
| `APP_URL` | `http://localhost:8000` | `https://<CONFIRMED_DOMAIN>` | Drives `QrCodeService` QR payload URLs — see §2. |
| `DB_HOST` | `127.0.0.1` (host-side artisan) | `db` (Docker service name) | Never conflate the two — see Section 0 constraint. |
| `CACHE_STORE` | `redis` | `redis` | **Must be `CACHE_STORE`, not `CACHE_DRIVER`** — Laravel 11 config/cache.php ignores the latter. Fixed in `.env.production` this pass. |
| `SESSION_SECURE_COOKIE` | unset/false | `true` | Requires HTTPS to be actually terminated (see nginx §3). |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost,localhost:3000,localhost:8000,127.0.0.1,127.0.0.1:8000` | `<CONFIRMED_DOMAIN>` (+ `www.` variant if used) | Newly required — see §4 (Sanctum). |
| `RFID_API_TOKEN` | empty (open mode) | a real generated token | ESP32 firmware must be flashed with the matching token. |
| `RFID_DEFAULT_COMPANY_CODE` | `TIMAH` | confirm per deployment | Never assume `company_id=1`. |
| `CCTV_USE_REAL` | per-camera (`is_simulation` column is now the source of truth) | same | This flag is only a *seeding default*, not a runtime authority — see `CctvCamera::isSimulationCamera()`. |
| `CCTV_RTSP_URL` | dev camera credentials | **real production camera credentials** | Placeholder in `.env.production` — replace before go-live. |
| `CCTV_HLS_BASE_URL` | `http://localhost:8888` | `https://<CONFIRMED_DOMAIN>/hls` | Must be reverse-proxied through nginx (see §3) to stay under HTTPS — never point directly at the raw MediaMTX port over plain HTTP, or browsers will block it as mixed content. |
| `CCTV_MEDIAMTX_RTSP` / `CCTV_MEDIAMTX_API` | `rtsp://127.0.0.1:8554` / `http://localhost:9997` | `rtsp://mediamtx:8554` / `http://mediamtx:9997` | Internal Docker network hostnames — never publicly exposed. |

## 2. QR Code domain (highest priority — physically printed, unfixable after the fact)

`QrCodeService::buildPayload()` builds every QR payload exclusively from
`config('app.url')` at generation time — confirmed via code read, this is
already correct and deploy-target-agnostic.

**However:** if any batch's QR code was generated *before* `APP_URL` is set
to the final confirmed production domain, that QR's payload URL is baked in
permanently (`qrcodes/{company_id}/{batch_id}.svg` on disk) and cannot be
retroactively corrected without reprinting.

**Action required at cutover:**
1. Confirm the final production domain BEFORE generating/printing any batch
   QR codes in the new environment.
2. If any batches already have a `qr_code_path` generated under the wrong
   domain (e.g. generated during local testing with `APP_URL=http://localhost:8000`
   and then physically printed), run a reprint pass: call
   `QrCodeService::generateForBatch($batch)` again for every affected batch
   after `APP_URL` is corrected, and physically replace any printed labels.
3. As of this audit, no batches in the local dev database have been
   confirmed printed to a physical label — verify this assumption against
   actual field operations before the migration.

## 3. Nginx / Docker Compose

- `docker-compose.prod.yml` was missing the `mediamtx` service entirely —
  **fixed this pass**. Without it, CCTV (live-view + RFID snapshot capture)
  would fail completely in production. It intentionally publishes no ports
  to the host; HLS is reverse-proxied through nginx (`location /hls/` in
  `app.prod.conf`, added this pass) so it stays behind the same HTTPS
  termination as the rest of the app.
- `docker/nginx/conf.d/app.prod.conf` and `docker-compose.prod.yml` hardcode
  `mining-traceability.com` and its SSL certificate paths. This is expected
  for a deploy-time template, but **since the actual production domain is
  still under reconfirmation, treat `mining-traceability.com` as a
  placeholder** — swap `server_name`, `ssl_certificate`, and
  `ssl_certificate_key` paths to the real confirmed domain before first
  deploy, and re-run certbot against the correct domain.
- Verify `ffmpeg` is present in the `app`/`worker` production image (it is —
  `docker/php/Dockerfile` line 26) since `CaptureCctvSnapshotJob` shells out
  to it directly.

## 4. Sanctum (newly installed this pass)

`routes/api.php` has always referenced `auth:sanctum` middleware for
`api/v1/auth/*`, `api/v1/company/batches|checkpoints|hardware/*`, and
`api/v1/bim/*` — but `laravel/sanctum` was never actually installed
(confirmed via `composer.json`/`vendor/laravel/`), so any request to those
routes would throw `Auth guard [sanctum] is not defined`. Fixed this pass:

- `composer require laravel/sanctum` installed and locked.
- `config/sanctum.php` published (stock config, reads `SANCTUM_STATEFUL_DOMAINS`).
- `personal_access_tokens` migration published and run.
- `User` model now uses `Laravel\Sanctum\HasApiTokens`.
- `SANCTUM_STATEFUL_DOMAINS` added to `.env`, `.env.example`, confirmed
  already present in `.env.production`.

These routes are not currently called by the frontend (which uses
session-based `web`+`auth` middleware for all `/api/v1/company/rfid/*` and
`/cctv/*` calls) — they exist as scaffolding for a future mobile/API client.
**Action at cutover:** none required beyond what's already fixed, unless a
mobile client is built, in which case it will need to issue tokens via
`$user->createToken(...)`.

## 5. Post-cutover verification

- [ ] `php artisan migrate --force` runs clean against the production DB.
- [ ] `php artisan config:cache && php artisan route:cache` succeed (routes
      contain no closures that can't be cached — verify after any route
      file changes).
- [ ] Visit `/hardware/cctv` and confirm the live HLS stream loads over
      `https://<domain>/hls/...` with no mixed-content console errors.
- [ ] Trigger one real RFID scan and confirm a CCTV snapshot is captured
      and visible on the batch detail page.
- [ ] Confirm `queue:work` is supervised (the `worker` service in
      `docker-compose.prod.yml` already runs `--sleep=3 --tries=3
      --max-time=3600` under `restart: unless-stopped`, which self-recovers,
      but monitor for crash loops in the first 24h).
