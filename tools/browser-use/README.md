# URL2AI browser-use runner

This folder contains a local `browser-use` setup for web UI automation.

## First use

Create `tools/browser-use/.env` from `.env.example` and set an LLM API key.

```bash
cd /home/kojima/work/url2ai/tools/browser-use
cp .env.example .env
```

## Run a task

```bash
cd /home/kojima/work/url2ai/tools/browser-use
uv run python run_task.py "Open https://www.payapi.market/login and check the URL2AI dashboard"
```

For visible browser mode:

```bash
uv run python run_task.py --headed "Open https://www.payapi.market/login"
```

Restrict navigation to specific domains:

```bash
uv run python run_task.py \
  --allowed-domain www.payapi.market \
  --allowed-domain payapi.market \
  "Open PayAPI Market and inspect the URL2AI listing status"
```

## Direct CLI

```bash
uv run browser-use doctor
uv run browser-use open https://www.payapi.market/list
uv run browser-use state
```

## Notes

- Chromium is installed in the Playwright cache.
- `browser-use install` tried to install OS packages with sudo; Chromium itself was installed with `uv run playwright install chromium`.
- The current diagnostic status is usable for local browser automation, with optional `cloudflared` and `profile-use` missing.
