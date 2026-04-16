# updf2md

`updf2md` is a small PDF-to-Markdown service built on top of [firecrawl/pdf-inspector](https://github.com/firecrawl/pdf-inspector).

It accepts uploaded PDFs, classifies them (`text_based`, `mixed`, `scanned`, `image_based`), converts text-based content to Markdown, and returns extraction metadata that can later drive OCR fallback for only the pages that need it.

## What this MVP does

- FastAPI upload endpoint: `POST /convert`
- Local CLI for one-shot conversion
- Optional saving of `.md` and `.json` metadata files
- Returns `pages_needing_ocr`, `confidence`, table/column hints, and extracted Markdown

## What this MVP does not do yet

- OCR fallback for scanned pages
- Hybrid assembly that mixes local text extraction with OCR results
- Queueing, auth, object storage, or background workers

## Files

- `server.py` - HTTP API
- `cli.py` - local converter
- `setup.sh` - install into `/opt/updf2md`
- `.env.sample` - runtime settings

## Requirements

- Python 3.10+
- Rust toolchain (`cargo`, `rustc`) to build `pdf-inspector`

## Local setup

```bash
cd ~/work/url2ai/apps/updf2md
python3 -m venv .venv
source .venv/bin/activate
python -m pip install --upgrade pip
python -m pip install -r requirements.txt
cp .env.sample .env
set -a && source .env && set +a
uvicorn server:app --host "$HOST" --port "$PORT"
```

## API

### `GET /healthz`

Returns service status.

### `POST /convert`

Multipart form fields:

- `file` - required PDF file
- `pages` - optional 1-indexed selector like `1,3,5-8`
- `save_output` - optional boolean
- `include_markdown` - optional boolean, default `true`

Example:

```bash
curl -X POST http://127.0.0.1:8020/convert \
  -F "file=@/path/to/document.pdf" \
  -F "pages=1-3" \
  -F "save_output=true"
```

## CLI

Write Markdown next to the PDF:

```bash
python cli.py ./document.pdf
```

Write to a specific output file:

```bash
python cli.py ./document.pdf -o ./document.md
```

Emit JSON metadata:

```bash
python cli.py ./document.pdf --json
```

## Suggested next step

The natural v2 is:

1. Use `pages_needing_ocr` from `pdf-inspector`
2. OCR only those pages with a separate engine
3. Merge OCR page output back into the final Markdown in page order
