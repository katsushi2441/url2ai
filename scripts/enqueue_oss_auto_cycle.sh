#!/bin/sh
RQDB4AI_API_URL="${RQDB4AI_API_URL:-http://127.0.0.1:18300}"
: "${RQDB4AI_API_TOKEN:?RQDB4AI_API_TOKEN required}"
OSS_PERIOD="${OSS_PERIOD:-daily}"
OSS_TOP_N="${OSS_TOP_N:-3}"

curl -fsS "$RQDB4AI_API_URL/api/enqueue" \
  -H "Authorization: Bearer $RQDB4AI_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "queue": "auto",
    "function": "oss_jobs.worker_auto_cycle_job",
    "kwargs": {
      "period": "'"$OSS_PERIOD"'",
      "top_n": '"$OSS_TOP_N"',
      "dry_run": false,
      "source": "worker_auto",
      "resource": "ollama",
      "ollama_host": "192.168.0.14",
      "ollama_model": "gemma4:e4b"
    },
    "meta": {
      "project": "url2ai",
      "app": "oss",
      "kind": "ollama",
      "source": "worker_auto",
      "resource": "ollama",
      "ollama_host": "192.168.0.14",
      "ollama_model": "gemma4:e4b"
    },
    "timeout": 1800,
    "result_ttl": 86400,
    "failure_ttl": 604800
  }'
