#!/bin/sh
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPO_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
QUEUES="${URL2AI_OLLAMA_RQ_QUEUES:-${POLYMARKET_RQ_QUEUES:-ollama-192-168-0-14-web ollama-192-168-0-14-worker}}"

cd "$REPO_DIR" || exit 1
export PYTHONPATH="$REPO_DIR/src${PYTHONPATH:+:$PYTHONPATH}"
export RQDB4AI_REDIS_URL="${RQDB4AI_REDIS_URL:-redis://127.0.0.1:6379/0}"

if command -v rq >/dev/null 2>&1; then
  exec rq worker $QUEUES --url "$RQDB4AI_REDIS_URL"
elif [ -x "$HOME/.local/bin/rq" ]; then
  exec "$HOME/.local/bin/rq" worker $QUEUES --url "$RQDB4AI_REDIS_URL"
fi

exec python3 -m rq worker $QUEUES --url "$RQDB4AI_REDIS_URL"
