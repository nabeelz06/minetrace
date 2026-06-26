#!/bin/bash
set -e

echo '════════════════════════════════════════════════════════'
echo '  MINETRA — Local Development Environment Startup'
echo '  WSL2 Ubuntu / macOS + Docker Desktop'
echo '════════════════════════════════════════════════════════'
echo ''

# ─── 1. Setup .env dari .env.local ────────────────────────────────────────────
if [ ! -f .env ] || ! grep -q 'APP_KEY=base64' .env; then
  echo '[1/8] Setup .env dari .env.local...'
  cp .env.local .env
  php artisan key:generate --force
  echo '  ✓ .env dikonfigurasi dengan APP_KEY baru'
else
  echo '[1/8] .env sudah ada — skip copy'
fi

# ─── 2. Start Docker services ─────────────────────────────────────────────────
echo '[2/8] Start Docker services (postgres, redis, mediamtx)...'
docker compose up -d postgres redis mediamtx
echo '  ✓ Container dimulai'

# ─── 3. Wait for PostgreSQL ───────────────────────────────────────────────────
echo '[3/8] Menunggu PostgreSQL siap...'
MAX_TRIES=30
TRIES=0
until docker compose exec -T postgres pg_isready -U minetra -d minetra_local > /dev/null 2>&1; do
  TRIES=$((TRIES+1))
  if [ $TRIES -ge $MAX_TRIES ]; then
    echo '  ✗ PostgreSQL timeout! Cek: docker compose logs postgres'
    exit 1
  fi
  sleep 2
  printf '.'
done
echo ''
echo '  ✓ PostgreSQL siap'

# ─── 4. Jalankan migrations ───────────────────────────────────────────────────
echo '[4/8] Migrasi database...'
php artisan migrate --force
echo '  ✓ Migrations selesai'

# ─── 5. Jalankan seeders ──────────────────────────────────────────────────────
echo '[5/8] Seeder data (roles, company, users, kamera)...'
php artisan db:seed --force
echo '  ✓ Seeders selesai'

# ─── 6. Storage link ──────────────────────────────────────────────────────────
echo '[6/8] Storage link...'
php artisan storage:link 2>/dev/null || echo '  (link sudah ada, skip)'
echo '  ✓ Storage link aktif'

# ─── 7. Build frontend ────────────────────────────────────────────────────────
echo '[7/8] Install & build frontend (npm)...'
npm install --silent
npm run build
echo '  ✓ Frontend build selesai'

# ─── 8. Optimize Laravel ──────────────────────────────────────────────────────
echo '[8/8] Optimize Laravel cache...'
php artisan optimize:clear
php artisan optimize
echo '  ✓ Cache dioptimasi'

# ─── Summary ──────────────────────────────────────────────────────────────────
echo ''
echo '════════════════════════════════════════════════════════'
echo '  MINETRA siap! Jalankan di terminal terpisah:'
echo ''
echo '  php artisan serve              # App server :8000'
echo '  php artisan queue:work         # Job worker (RFID+CCTV)'
echo '  npm run dev                    # Vite HMR (opsional)'
echo ''
echo '  Akses: http://localhost:8000'
echo ''
echo '  CCTV Simulation Streams (mediamtx):'
echo '  HLS:  http://localhost:8888/cam_gate1/index.m3u8'
echo '  HLS:  http://localhost:8888/cam_wet/index.m3u8'
echo '  HLS:  http://localhost:8888/cam_warehouse/index.m3u8'
echo ''
echo '  MediaMTX API:'
echo '  curl http://localhost:9997/v3/paths/list'
echo '════════════════════════════════════════════════════════'
