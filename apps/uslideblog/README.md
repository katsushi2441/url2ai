# USlideBlog App

USlideBlog is the URL2AI slide-blog renderer.

It deliberately uses OSS tools for the slide pipeline:

- Reveal.js: browser presentation UI, loaded by `src/uslideblog.php`
- Marp: Markdown slide rendering and PDF-friendly HTML generation
- PptxGenJS: PowerPoint output
- Tiptap: browser text editing, loaded by `src/uslideblog.php`
- Excalidraw / diagrams.net: planned diagram-edit blocks

## Run

```bash
cd /home/kojima/exdirect/url2ai/apps/uslideblog
npm install
PORT=8022 node server.js
```

## Endpoints

- `GET /health`
- `POST /api/uslideblog/markdown`
- `POST /api/uslideblog/marp-html`
- `POST /api/uslideblog/pptx`

Payload:

```json
{
  "title": "Slide blog title",
  "description": "Description",
  "source_url": "https://example.com",
  "tags": ["VibeCoding", "Codex"],
  "slides": [
    {
      "title": "Slide title",
      "body": "Slide body",
      "note": "Speaker note",
      "layout": "cover"
    }
  ]
}
```

