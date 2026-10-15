#!/bin/bash
echo 'Stopping MINETRACE local Docker services...'
docker compose stop postgres redis mediamtx
echo ''
echo 'Docker services stopped.'
echo 'App server dan queue worker perlu di-stop manual (Ctrl+C di terminal masing-masing).'
