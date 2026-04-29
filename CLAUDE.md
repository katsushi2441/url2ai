# CLAUDE.md — URL2AI Project Rules

## PHP Version Constraint

**The production server (aiknowledgecms.exbridge.jp) runs PHP 5.x.**

When editing any PHP file under `src/`, do NOT use PHP 7+ syntax:

| Forbidden | PHP 5.x alternative |
|---|---|
| `$a ?? 'default'` | `(isset($a) ? $a : 'default')` |
| `$a ?? $b ?? ''` | `(isset($a) ? $a : (isset($b) ? $b : ''))` |
| `function f(?string $x)` | `function f($x = null)` |
| `int $x` typed properties | Untyped only |
| `declare(strict_types=1)` | Omit |
| `match()` expression | `switch` / `if-elseif` |
| Named arguments `f(key: val)` | Positional only |
| Arrow functions `fn($x) => $x` | `function($x) { return $x; }` |

This applies to all files in `src/*.php`. Other directories (`apps/`, `x402/`) run Node.js and are not affected.

## Node.js / TypeScript (apps/, x402/)

- Node.js 22 (ESM, `"type": "module"`)
- TypeScript for Bankr x402 handlers (`x402/*/index.ts`)

## FTP Deploy

Upload PHP changes with:
```bash
cd src && python3 ftpphp.py
```

## Architecture Notes

- `src/` — PHP 5.x pages served on shared hosting via FTP
- `apps/llm-gateway/` — Node.js 22, LLM2API gateway (ports 8019/8020)
- `apps/oss2api/` — Node.js 22, OSS2API gateway (ports 8015/8017)
- `apps/ernie-image-turbo/` — Python, image generation server
- `x402/` — Bankr x402 TypeScript handlers, deployed via `bankr x402 deploy`

## Port Map (8010–8020)

| Port | Service | Stack | Notes |
|------|---------|-------|-------|
| 8010 | **api_gateway** | Python (ernie venv) | Unified gateway: `/image` → ernie-image-turbo, `/pdf` → updf2md. Started manually with `nohup uvicorn api_gateway:app ...` from `apps/` |
| 8011 | **ernie-image-turbo** | Python | ERNIE image generation standalone. systemd: なし、直接起動 |
| 8012 | **nginx SSL proxy** | nginx | `exbridge.ddns.net:8012` (HTTPS). `/mcp` → 127.0.0.1:8013, `/oss2api` → 8015, `/updf2md` → 8010/pdf, etc. |
| 8013 | **url2ai-mcp** | Python (FastMCP) | MCP server, localhost only。`apps/url2ai-mcp/`。systemd: `url2ai-mcp.service` |
| 8014 | **finreport** | Python (FastAPI) | 投資レポート生成。`apps/finreport/`。systemd: なし |
| 8015 | **oss2api** | Node.js | OSS2API Bankr x402 endpoint。`apps/oss2api/`。systemd: `oss2api.service` |
| 8016 | **polymarket** | Python (FastAPI) | Polymarket連携。`apps/polymarket/` |
| 8017 | **oss2api-jpyc** | Node.js | OSS2API JPYC x402 gateway (proxies to 8015)。systemd: `oss2api-jpyc.service` |
| 8018 | (未使用) | — | — |
| 8019 | **llm-gateway** | Node.js | LLM2API Bankr x402 endpoint (Gemma 4 E4B via Ollama)。systemd: `llm-gateway.service` |
| 8020 | **llm-gateway-jpyc** | Node.js | LLM2API JPYC x402 gateway (proxies to 8019)。systemd: `llm-gateway-jpyc.service` |

### api_gateway (port 8010) の再起動方法

api_gateway はsystemdサービスがないため、手動で再起動する:

```bash
kill $(pgrep -f "api_gateway:app")
cd /home/kojima/work/url2ai/apps
nohup /home/kojima/work/url2ai/apps/ernie-image-turbo/.venv-cu128/bin/python \
  /home/kojima/work/url2ai/apps/ernie-image-turbo/.venv-cu128/bin/uvicorn \
  api_gateway:app --host 0.0.0.0 --port 8010 > /tmp/api_gateway.log 2>&1 &
```

ernie venv に必要なパッケージ: `PyMuPDF`, `pytesseract`, `Pillow`, `yfinance` (updf2md/finreportの依存を同じvenvで動かすため)
