#!/usr/bin/env bash
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEFAULT_VENV_DIR="$APP_DIR/.venv"
if [ -d "$APP_DIR/.venv-cu128" ]; then
  DEFAULT_VENV_DIR="$APP_DIR/.venv-cu128"
fi
VENV_DIR="${VENV_DIR:-$DEFAULT_VENV_DIR}"
ENV_FILE="${ENV_FILE:-$APP_DIR/.env}"
PYTHON_BIN="${PYTHON_BIN:-python3}"
FORCE_DEVICE="${FORCE_DEVICE:-}"

detect_device() {
  python - <<'PY'
try:
    import torch
    print("cuda" if torch.cuda.is_available() else "cpu")
except Exception:
    print("cpu")
PY
}

if [ ! -d "$VENV_DIR" ]; then
  "$PYTHON_BIN" -m venv "$VENV_DIR"
fi

source "$VENV_DIR/bin/activate"
python -m pip install --upgrade pip
python -m pip install -r "$APP_DIR/requirements.txt"
if ! python -c "import torch" >/dev/null 2>&1; then
  if command -v nvidia-smi >/dev/null 2>&1 && nvidia-smi -L >/dev/null 2>&1; then
    python -m pip install torch --index-url https://download.pytorch.org/whl/cu128
  else
    python -m pip install torch --index-url https://download.pytorch.org/whl/cpu
  fi
fi
DEVICE_VALUE="$FORCE_DEVICE"
if [ -z "$DEVICE_VALUE" ]; then
  DEVICE_VALUE="$(detect_device)"
fi

if [ ! -f "$ENV_FILE" ]; then
  cp "$APP_DIR/.env.sample" "$ENV_FILE"
fi

python - "$ENV_FILE" "$DEVICE_VALUE" <<'PY'
from pathlib import Path
import sys

env_path = Path(sys.argv[1])
device = sys.argv[2]
lines = env_path.read_text(encoding="utf-8").splitlines()
updated = []
found = False
for line in lines:
    if line.startswith("DEVICE="):
        updated.append(f"DEVICE={device}")
        found = True
    else:
        updated.append(line)
if not found:
    updated.append(f"DEVICE={device}")
env_path.write_text("\n".join(updated) + "\n", encoding="utf-8")
PY

echo "Installed into $APP_DIR"
echo "Virtualenv: $VENV_DIR"
echo "Environment: $ENV_FILE"
echo "Detected device: $DEVICE_VALUE"
echo "Start manually with:"
echo "  cd $APP_DIR && source $VENV_DIR/bin/activate && set -a && source $ENV_FILE && set +a && uvicorn server:app --host \$HOST --port \$PORT"
