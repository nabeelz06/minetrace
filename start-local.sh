#!/bin/bash
set -e

echo '════════════════════════════════════════════════════'
echo '  MINETRACE — Local Development Environment Startup  '
echo '════════════════════════════════════════════════════'

# ─── Step 1: Ensure .env exists and has correct LOCAL values ───────────
echo '[1/8] Konfigurasi .env untuk local development...'

if [ ! -f .env ]; then
  cp .env.example .env
  php artisan key:generate --force
  echo '  ✓ .env dibuat dari .env.example'
fi

update_env() {
  local key=$1
  local value=$2
  if grep -q "^${key}=" .env; then
    sed -i "s|^${key}=.*|${key}=${value}|" .env
  else
    echo "${key}=${value}" >> .env
  fi
}

update_env 'DB_HOST' '127.0.0.1'
update_env 'DB_PORT' '5432'
update_env 'DB_DATABASE' 'minetra_local'
update_env 'DB_USERNAME' 'minetra'
update_env 'DB_PASSWORD' 'minetra_local_pass'
update_env 'REDIS_HOST' '127.0.0.1'
update_env 'REDIS_PORT' '6379'
update_env 'REDIS_PASSWORD' 'null'
update_env 'CACHE_STORE' 'redis'
update_env 'CACHE_DRIVER' 'redis'
update_env 'QUEUE_CONNECTION' 'redis'
update_env 'SESSION_DRIVER' 'redis'
update_env 'CCTV_RTSP_URL' 'rtsp://127.0.0.1:8554/cam_gate1'
update_env 'CCTV_HLS_BASE_URL' 'http://localhost:8888'
update_env 'CCTV_MEDIAMTX_RTSP' 'rtsp://127.0.0.1:8554'
update_env 'CCTV_MEDIAMTX_API' 'http://localhost:9997'
update_env 'APP_URL' 'http://localhost:8000'
update_env 'FILESYSTEM_DISK' 'public'

php artisan config:clear --quiet
echo '  ✓ .env dikonfigurasi untuk local'

# ─── Step 2: Start Docker services ─────────────────────────────────────
echo '[2/8] Start Docker services (postgres, redis, mediamtx)...'
docker compose up -d postgres redis mediamtx --remove-orphans 2>&1 \
  | grep -v -E 'WARN|orphan|Container .+ Running|Container .+ Started' || true

echo -n '  Menunggu containers ready'
for i in {1..30}; do
  sleep 1
  printf '.'
  if docker exec minetra_postgres_local pg_isready -U minetra -d minetra_local -q 2>/dev/null; then
    break
  fi
done
echo ''

# Pastikan database ada
docker exec minetra_postgres_local psql -U minetra -tc \
  "SELECT 1 FROM pg_database WHERE datname='minetra_local'" 2>/dev/null \
  | grep -q 1 || \
  docker exec minetra_postgres_local psql -U minetra \
  -c "CREATE DATABASE minetra_local;" 2>/dev/null || true

echo '  ✓ Docker services ready'

# ─── Step 3: Test database connection ──────────────────────────────────
echo '[3/8] Test koneksi database...'
if php artisan db:show --quiet 2>/dev/null; then
  echo '  ✓ Database terhubung'
else
  echo '  ✗ Database connection gagal!'
  echo '    Debug: php artisan db:show'
  exit 1
fi

# ─── Step 4: Migrations ────────────────────────────────────────────────
echo '[4/8] Jalankan migrations...'
php artisan migrate --force
echo '  ✓ Migrations selesai'

# ─── Step 5: Storage link ──────────────────────────────────────────────
echo '[5/8] Storage link...'
php artisan storage:link 2>/dev/null || true
mkdir -p storage/app/public/cctv-snapshots
echo '  ✓ Storage ready'

# ─── Step 6: Seeders ───────────────────────────────────────────────────
echo '[6/8] Jalankan seeders...'
php artisan db:seed --force
echo '  ✓ Seeders selesai'

# ─── Step 7: Build frontend ────────────────────────────────────────────
echo '[7/8] Build frontend...'
npm run build 2>&1 | tail -5
echo '  ✓ Frontend built'

# ─── Step 8: Optimize ──────────────────────────────────────────────────
echo '[8/8] Optimize Laravel...'
php artisan optimize:clear --quiet
php artisan optimize --quiet
echo '  ✓ Laravel optimized'

echo ''
echo '════════════════════════════════════════════════════'
echo '  ✅ MINETRACE LOCAL siap dijalankan!'
echo ''
echo '  Buka 3 terminal baru dan jalankan:'
echo '  [1] php artisan serve'
echo '  [2] php artisan queue:work --tries=3 --timeout=60'
echo '  [3] npm run dev  (opsional, untuk hot reload)'
echo ''
echo '  CCTV Simulation Streams (buka di browser):'
echo '  Gate 1:    http://localhost:8888/cam_gate1/index.m3u8'
echo '  Wet Area:  http://localhost:8888/cam_wet/index.m3u8'
echo '  Warehouse: http://localhost:8888/cam_warehouse/index.m3u8'
echo ''
echo '  App: http://localhost:8000'
echo '════════════════════════════════════════════════════'
