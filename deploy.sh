#!/bin/bash
# deploy.sh — Script deployment otomatis ke VPS
# Jalankan dari lokal: ./deploy.sh
# Atau dari VPS: bash deploy.sh

set -e  # Exit on error

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  MINETRA — Production Deployment Script"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# ── Pull latest code ──────────────────────────────────────────
echo "[1/7] Pulling latest code..."
git pull origin main

# ── Build production images ───────────────────────────────────
echo "[2/7] Building Docker images (production)..."
docker compose -f docker-compose.prod.yml build --no-cache app worker

# ── Stop old containers ───────────────────────────────────────
echo "[3/7] Stopping old containers..."
docker compose -f docker-compose.prod.yml down --remove-orphans

# ── Start new containers ──────────────────────────────────────
echo "[4/7] Starting containers..."
docker compose -f docker-compose.prod.yml up -d

# ── Wait for DB ───────────────────────────────────────────────
echo "[5/7] Waiting for database..."
sleep 10

# ── Rebuild frontend assets onto the host ──────────────────────
# nginx and the app container both bind-mount ./public from the HOST
# (see docker-compose.prod.yml), so the `npm run build` that happens
# inside the image during `docker build` is thrown away at runtime —
# it never reaches the host's public/build that nginx actually serves.
# This step re-runs the build inside the running app container, which
# writes straight through the bind mount onto the host filesystem.
echo "[6/7] Building frontend assets (writes to host public/build)..."
docker compose -f docker-compose.prod.yml exec -T app sh -c "npm ci && npm run build"

# ── Run migrations & optimize ─────────────────────────────────
echo "[7/7] Running migrations and optimizing..."
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan optimize:clear
docker compose -f docker-compose.prod.yml exec app php artisan optimize

echo ""
echo "✅ Deployment selesai!"
echo "🌐 https://mining-traceability.com"