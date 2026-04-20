#!/bin/bash
set -e
cd "$(dirname "$0")"

python3 -m venv .venv
.venv/bin/pip install --upgrade pip
.venv/bin/pip install -r requirements.txt

cp -n .env.sample .env || true

echo ""
echo "セットアップ完了。.env を確認後、以下でサービス登録してください："
echo "  sudo cp finreport.service /etc/systemd/system/"
echo "  sudo systemctl daemon-reload"
echo "  sudo systemctl enable --now finreport"
