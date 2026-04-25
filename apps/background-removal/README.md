# OSS2API Background Removal

x402-ready background image gateway powered by `imgly/background-removal-js`.

## Capabilities

- `remove`: remove the background and return a transparent PNG/WebP/JPEG
- `replace`: remove the background and composite the foreground over a color or image
- `blur`: remove the background and composite the foreground over a blurred copy of the original image

## License Note

This gateway uses `@imgly/background-removal-node`, which is distributed under AGPL terms. Keep OSS2API deployments and marketplace metadata explicit about that license.

## Run

```bash
npm install
npm start
```

Default URL:

```text
http://localhost:8015
```

## Examples

Remove background:

```bash
curl -sS http://localhost:8015/run \
  -H 'Content-Type: application/json' \
  -d '{"image_url":"https://example.com/product.jpg","mode":"remove"}' \
  --output removed.png
```

Replace background:

```bash
curl -sS http://localhost:8015/run \
  -H 'Content-Type: application/json' \
  -d '{"image_url":"https://example.com/product.jpg","mode":"replace","background_color":"#f8fafc"}' \
  --output replaced.png
```

Blur background:

```bash
curl -sS http://localhost:8015/run \
  -H 'Content-Type: application/json' \
  -d '{"image_url":"https://example.com/person.jpg","mode":"blur","blur_sigma":22}' \
  --output blurred.png
```

Return JSON with base64:

```bash
curl -sS http://localhost:8015/run \
  -H 'Content-Type: application/json' \
  -d '{"image_url":"https://example.com/product.jpg","mode":"remove","response":"json"}'
```
