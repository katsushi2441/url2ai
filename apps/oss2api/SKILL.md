---
name: oss2api
description: >
  OSS2API is a multi-skill AI agent gateway wrapping open-source tools into
  paid x402 endpoints. Skills: image background removal (remove/replace/blur),
  URL structured extraction, Playwright browser screenshot/extract, and a
  Shannon-like 3-phase security scan.
  Use this skill when the user wants to remove/replace/blur an image background,
  extract structured content from a URL, take a browser screenshot, or run a
  security scan on a website.
  Payment is handled via Bankr x402 (USDC on Base) or JPYC x402 (JPYC on Polygon).
---

# OSS2API — Multi-skill AI Agent Gateway

Wraps open-source tools into x402-ready paid endpoints.
GitHub: [katsushi2441/url2ai](https://github.com/katsushi2441/url2ai)

## Endpoints

| Gateway | Base URL | Payment |
|---|---|---|
| Bankr x402 | `https://x402.bankr.bot/0x444fadbd6e1fed0cfbf7613b6c9f91b9021eecbd/oss2api` | USDC on Base ($0.01/req) |
| JPYC x402 | `https://exbridge.ddns.net:8017` | JPYC on Polygon (1.5 JPYC/req) |

**Use the Bankr endpoint by default.** Append the skill path to the base URL.

## Skills

### 1. Image Background Removal

`POST {base}/oss2api/image/remove-background`

Remove, replace, or blur the background of any image.
Powered by [imgly/background-removal-js](https://github.com/imgly/background-removal-js) (AGPL-3.0).

**Parameters:**

| Field | Type | Description |
|---|---|---|
| `image_url` | string | Publicly reachable HTTPS URL of the source image |
| `image_base64` | string | Base64-encoded source image (raw or data URL) |
| `mode` | string | `remove` (default, transparent), `replace`, `blur` |
| `background_color` | string | CSS color for `replace` mode (e.g. `#ffffff`) |
| `background_image_url` | string | Background image URL for `replace` mode |
| `blur_sigma` | number | Blur strength 1–80 for `blur` mode (default 18) |
| `output_format` | string | `png` (default), `webp`, `jpeg` |
| `response` | string | `binary` (default) or `json` (returns base64) |

Either `image_url` or `image_base64` is required.

**Examples:**

```json
// Remove background (transparent PNG)
{ "image_url": "https://example.com/photo.jpg", "mode": "remove" }

// Replace with white background
{ "image_url": "https://example.com/photo.jpg", "mode": "replace", "background_color": "#ffffff" }

// Blur background
{ "image_url": "https://example.com/photo.jpg", "mode": "blur", "blur_sigma": 22 }

// Return as JSON (base64)
{ "image_url": "https://example.com/photo.jpg", "mode": "remove", "response": "json" }
```

**Response (binary):** Raw image bytes (PNG/WebP/JPEG).

**Response (json):**
```json
{ "ok": true, "mode": "remove", "content_type": "image/png", "image_base64": "<base64>" }
```

---

### 2. URL Structured Extraction

`POST {base}/oss2api/url/analyze`

Fetch a URL and extract structured content.

**Parameters:**

| Field | Type | Description |
|---|---|---|
| `url` | string | Target URL (required) |
| `format` | string | `json` (default) or `markdown` |
| `depth` | string | `basic` (default, 600-char summary) or `full` (8000-char body) |

**Response:**
```json
{
  "ok": true,
  "url": "...",
  "title": "...",
  "description": "...",
  "headings": [{ "tag": "h1", "text": "..." }],
  "links": [{ "text": "...", "href": "..." }],
  "entities": [{ "type": "email", "value": "..." }],
  "summary": "..."
}
```

---

### 3. Browser Screenshot / Extract

`POST {base}/oss2api/url/browse`

Render a page with a real Chromium browser (Playwright). Useful for JavaScript-heavy pages.

**Parameters:**

| Field | Type | Description |
|---|---|---|
| `url` | string | Target URL (required) |
| `action` | string | `screenshot` (default, PNG as base64) or `extract` (visible text + links) |
| `wait_until` | string | `networkidle` (default) or `load` |

**Response (screenshot):**
```json
{ "ok": true, "url": "...", "title": "...", "screenshot": "<base64-png>", "content_type": "image/png" }
```

**Response (extract):**
```json
{ "ok": true, "url": "...", "title": "...", "content": "...", "links": [...] }
```

---

### 4. Security Scan

`POST {base}/oss2api/url/scan`

Run a 3-phase security scan on any URL.

- **Phase 1** — HTTP security headers (CSP, HSTS, X-Frame-Options, Referrer-Policy, Permissions-Policy)
- **Phase 2** — Static HTML analysis: XSS sinks (`innerHTML`, `eval`, `document.write`), SQL error exposure, login form issues, SSRF-prone parameters, external script count
- **Phase 3** — Ollama AI analysis for additional findings and remediation recommendations

**Parameters:**

| Field | Type | Description |
|---|---|---|
| `url` | string | Target URL (required) |

**Response:**
```json
{
  "ok": true,
  "scanner": "shannon-like (headers + static + ollama-ai)",
  "risk_score": 50,
  "summary": "...",
  "findings": {
    "critical": [], "high": [], "medium": ["[xss] Missing CSP"], "low": [...], "info": [...]
  },
  "findings_flat": ["[MEDIUM/xss] Missing CSP", ...],
  "actions": ["Implement Content-Security-Policy", ...],
  "phases": ["1:header-analysis", "2:static-content-analysis", "3:ollama-ai-analysis"]
}
```

---

## Workflow

1. Identify which skill the user needs based on their request.
2. Use the **Bankr x402 base URL** by default. Switch to JPYC if the user requests JPYC payment.
3. Append the skill path and POST with the appropriate JSON body.
4. Handle the response according to the skill (binary image, JSON data, etc.).

## JPYC x402 Payment (port 8017)

For JPYC payment, use `https://exbridge.ddns.net:8017` as the base and append the same skill paths.

1. Call the endpoint without payment → server returns `402` with payment requirements.
2. Sign a `transferWithAuthorization` for 1.5 JPYC to the relay address (EIP-3009, Polygon chainId 137).
3. Retry with `X-Payment: <base64-encoded payment payload>`.
4. On success, response includes `X-PAYMENT-RESPONSE` header with tx hash.

## License

`@imgly/background-removal-js` is AGPL-3.0. Deployments using this skill must comply with AGPL terms.
