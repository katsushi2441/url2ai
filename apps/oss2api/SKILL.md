---
name: background-removal
description: >
  Remove, replace, or blur image backgrounds using the OSS2API background
  removal service (powered by imgly/background-removal-js).
  Use this skill when the user wants to remove a background from an image,
  replace it with a color or another image, or blur it.
  Supports JPYC x402 payment on Polygon and free access via Bankr / payapi.market.
---

# Background Removal (OSS2API)

This skill calls the OSS2API background removal service to process images.
The service wraps [imgly/background-removal-js](https://github.com/imgly/background-removal-js) (AGPL-3.0).

## Endpoints

| Endpoint | Description |
|---|---|
| `https://exbridge.ddns.net:8015/run` | Free (Bankr / payapi.market) |
| `https://exbridge.ddns.net:8017/run` | JPYC x402 payment (Polygon, 1.5 JPYC / request) |

Use port **8015** by default unless the user specifically requests JPYC payment.

## Quick Start

Send a POST request to `/run` with a JSON body:

```bash
curl -X POST https://exbridge.ddns.net:8015/run \
  -H "Content-Type: application/json" \
  -d '{"image_url": "https://example.com/photo.jpg", "mode": "remove"}' \
  --output result.png
```

## Parameters

| Field | Type | Required | Description |
|---|---|---|---|
| `image_url` | string | either/or | HTTPS URL of the input image |
| `image_base64` | string | either/or | base64 or data URL of the input image |
| `mode` | string | no | `remove` (default), `replace`, `blur` |
| `background_color` | string | no | CSS color for `replace` mode (e.g. `#ffffff`) |
| `background_image_url` | string | no | Background image URL for `replace` mode |
| `blur_sigma` | number | no | Blur strength 1–80 for `blur` mode (default 18) |
| `output_format` | string | no | `png` (default), `webp`, `jpeg` |
| `output_quality` | number | no | 1–100 (default 90) |
| `response` | string | no | `binary` (default) or `json` (returns base64) |

## Workflow

1. Determine the image source (`image_url` or `image_base64`) from user input.
2. Determine the mode:
   - User wants transparent background → `remove`
   - User wants a specific color or image background → `replace`
   - User wants a blurred background → `blur`
3. Call the `/run` endpoint with the appropriate parameters.
4. If `response=json`, extract `image_base64` from the response and show or save it.
5. If binary response, save the raw bytes as a PNG/WebP/JPEG file.

## Examples

### Remove background (transparent PNG)

```json
{
  "image_url": "https://example.com/product.jpg",
  "mode": "remove"
}
```

### Replace background with white

```json
{
  "image_url": "https://example.com/person.jpg",
  "mode": "replace",
  "background_color": "#ffffff"
}
```

### Blur background

```json
{
  "image_url": "https://example.com/portrait.jpg",
  "mode": "blur",
  "blur_sigma": 22
}
```

### Get result as JSON (base64)

```json
{
  "image_url": "https://example.com/photo.jpg",
  "mode": "remove",
  "response": "json"
}
```

Response:
```json
{
  "ok": true,
  "mode": "remove",
  "content_type": "image/png",
  "image_base64": "<base64-encoded-image>"
}
```

## JPYC x402 Payment (port 8017)

For direct JPYC payment, use port 8017. The server implements the x402 protocol
with JPYC (`transferWithAuthorization`, EIP-3009) on Polygon (chainId 137).

1. Call `/run` without payment → server returns `402` with payment requirements.
2. Sign a `transferWithAuthorization` for 1.5 JPYC to `0xd5d3DFe5F48222Bee84B69f808b00186b2bd1FC4`.
3. Retry with `X-Payment: <base64-encoded payment payload>`.
4. On success, response includes `X-PAYMENT-RESPONSE` header with tx hash.

## Self-hosting

```bash
git clone https://github.com/katsushi2441/url2ai
cd url2ai/apps/background-removal
npm install
cp .env.sample .env
npm start          # port 8015 (free)
# JPYC gateway:
cp .env.jpyc.sample .env.jpyc
# fill in JPYC_RELAY_PRIVATE_KEY
node server-jpyc.js  # port 8017
```

## License

The underlying OSS library (`@imgly/background-removal-node`) is AGPL-3.0.
Deployments using this skill must comply with AGPL terms.
