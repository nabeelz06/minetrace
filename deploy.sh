#!/bin/bash
# deploy.sh — Script deployment otomatis ke VPS
# Jalankan dari lokal: ./deploy.sh
# Atau dari VPS: bash deploy.sh

set -e  # Exit on error

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  MINETRA — Production Deployment Script"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# ── Pull latest code ──────────────────────────────────────────
echo "[1/6] Pulling latest code..."
git pull origin main

# ── Build production images ───────────────────────────────────
echo "[2/6] Building Docker images (production)..."
docker compose -f docker-compose.prod.yml build --no-cache app

# ── Stop old containers ───────────────────────────────────────
echo "[3/6] Stopping old containers..."
docker compose -f docker-compose.prod.yml down --remove-orphans

# ── Start new containers ──────────────────────────────────────
echo "[4/6] Starting containers..."
docker compose -f docker-compose.prod.yml up -d

# ── Wait for DB ───────────────────────────────────────────────
echo "[5/6] Waiting for database..."
sleep 10

# ── Run migrations & optimize ─────────────────────────────────
echo "[6/6] Running migrations and optimizing..."
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan optimize:clear
docker compose -f docker-compose.prod.yml exec app php artisan optimize

echo ""
echo "✅ Deployment selesai!"
echo "🌐 https://mining-traceability.com"