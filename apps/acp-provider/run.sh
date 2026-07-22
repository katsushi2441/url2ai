#!/usr/bin/env bash
# Kurage ACP プロバイダ常駐の起動スクリプト。
# brainトークンは新規ファイルに保存せず、cdp-gateway の systemd 環境から実行時に注入する。
set -euo pipefail

export PATH="/home/kojima/.nvm/versions/node/v20.20.2/bin:$PATH"
cd "$(dirname "$0")"

# cdp-gateway の merged 環境(drop-in含む)から brain トークン/URL を取り込む。
ENVDUMP="$(systemctl show cdp-gateway -p Environment 2>/dev/null || true)"
get() { echo "$ENVDUMP" | tr ' ' '\n' | grep "^$1=" | head -1 | cut -d= -f2- || true; }
export FXBRAIN_TOKEN="$(get FXBRAIN_TOKEN)"
export KCBRAIN_TOKEN="$(get KCBRAIN_TOKEN)"
export URL2BRAIN_TOKEN="$(get URL2BRAIN_TOKEN)"
export FXBRAIN_URL="${FXBRAIN_URL:-$(get FXBRAIN_URL)}"; export FXBRAIN_URL="${FXBRAIN_URL:-http://127.0.0.1:18326}"
export KCBRAIN_URL="${KCBRAIN_URL:-$(get KCBRAIN_URL)}"; export KCBRAIN_URL="${KCBRAIN_URL:-http://127.0.0.1:18328}"
export URL2BRAIN_URL="${URL2BRAIN_URL:-$(get URL2BRAIN_URL)}"; export URL2BRAIN_URL="${URL2BRAIN_URL:-http://127.0.0.1:18332}"
export ACP_BIN="/home/kojima/.nvm/versions/node/v20.20.2/bin/acp"

exec python3 provider.py
