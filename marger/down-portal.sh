#!/usr/bin/env bash
# Stop app portal Legal & Sales (+ postgres). Data DB tetap di volume pg-data.
# FO/BO & database (jerp-mysql, ipro_bo) TIDAK disentuh.
# Jalankan:  bash marger/down-portal.sh
set -euo pipefail
JERP_DIR="${JERP_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$JERP_DIR"

echo "==> stop legal + sales + postgres (data pg-data tetap aman)"
docker compose stop legal sales postgres

echo "==> PORTAL berhenti. Nyalakan lagi: bash marger/up-portal.sh"
