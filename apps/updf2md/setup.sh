#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/opt/updf2md"
VENV_DIR="$APP_DIR/.venv"

sudo mkdir -p "$APP_DIR"
sudo cp server.py cli.py requirements.txt .env.sample "$APP_DIR"/
cd "$APP_DIR"

python3 -m venv "$VENV_DIR"
source "$VENV_DIR/bin/activate"
python -m pip install --upgrade pip
python -m pip install -r requirements.txt

if [ ! -f .env ]; then
  cp .env.sample .env
fi

echo "Installed into $APP_DIR"
echo "Start manually with:"
echo "  cd $APP_DIR && source $VENV_DIR/bin/activate && set -a && source .env && set +a && uvicorn server:app --host \$HOST --port \$PORT"
