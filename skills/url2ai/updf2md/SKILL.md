---
name: updf2md
description: Use when an agent needs to convert a publicly reachable PDF into Markdown for RAG, document extraction, or MCP workflows via the hosted URL2AI x402 endpoint.
---

# UPDF2MD

Convert a public PDF URL into Markdown using the hosted URL2AI endpoint.

## When to use

- A task depends on extracting text from a PDF before summarizing, indexing, or chunking it
- The source PDF is public and reachable over `http` or `https`
- A workflow needs structured Markdown plus layout metadata such as `pdf_type` and OCR hints

## Endpoint

`https://x402.bankr.bot/0x444fadbd6e1fed0cfbf7613b6c9f91b9021eecbd/updf2md`

## Input

Send JSON with:

- `pdf_url` (required): public PDF URL
- `pages` (optional): page selection like `1-3,5`
- `filename` (optional): override filename when the URL path is unclear

## Output

Returns JSON including:

- `markdown`
- `pdf_type`
- `processing_time_ms`
- OCR / layout metadata from the backend converter

## Usage examples

```bash
bankr x402 schema https://x402.bankr.bot/0x444fadbd6e1fed0cfbf7613b6c9f91b9021eecbd/updf2md
```

```bash
bankr x402 call https://x402.bankr.bot/0x444fadbd6e1fed0cfbf7613b6c9f91b9021eecbd/updf2md \
  -X POST \
  -H 'content-type: application/json' \
  -d '{"pdf_url":"https://example.com/document.pdf"}'
```

## Requirements

- The PDF must be publicly reachable
- The caller pays via Bankr x402
- Current price is `0.001 USDC / request`

## Notes

- Prefer this tool when PDF quality matters more than using a generic fetch-and-parse flow
- This is one product inside the broader URL2AI ecosystem
