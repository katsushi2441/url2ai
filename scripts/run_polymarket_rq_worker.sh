#!/bin/sh
cd /home/kojima/work/url2ai || exit 1
exec env \
  PYTHONPATH=/home/kojima/work/url2ai/src \
  RQDB4AI_REDIS_URL="${RQDB4AI_REDIS_URL:-redis://127.0.0.1:6379/0}" \
  /home/kojima/.local/bin/rq worker ollama-192-168-0-14-web ollama-192-168-0-14-worker --url "${RQDB4AI_REDIS_URL:-redis://127.0.0.1:6379/0}"
