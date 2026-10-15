#!/usr/bin/env bash
# Gera o zip de instalação para Hostinger: app/ + public_html/ + database/
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/dist/hostinger"
ZIP="$ROOT/dist/leva-ja-hostinger.zip"

echo "==> Build do frontend web..."
cd "$ROOT/frontend"
[ -d node_modules ] || npm install
npm run build

echo "==> Montando pacote..."
rm -rf "$OUT"
mkdir -p "$OUT/app/src" "$OUT/app/bin" "$OUT/public_html/api" "$OUT/database"

cp -R "$ROOT/backend/src/." "$OUT/app/src/"
cp -R "$ROOT/backend/bin/." "$OUT/app/bin/"
cp "$ROOT/backend/.env.example" "$OUT/app/.env.example"
cp "$ROOT/backend/public/"* "$OUT/public_html/api/"
cp -R "$ROOT/frontend/dist/." "$OUT/public_html/"
cp "$ROOT/backend/database/schema.sql" "$OUT/database/schema.sql"
cp "$ROOT/deploy/hostinger/INSTALL.md" "$OUT/INSTALL.md"

rm -f "$ZIP"
(cd "$OUT" && zip -r "$ZIP" .)

echo ""
echo "Pacote pronto:"
echo "  pasta: $OUT"
echo "  zip:   $ZIP"
echo ""
echo "Envie app/ para /home/USUARIO/app/ e public_html/ para o document root."
