---
name: llm2api
description: >
  LLM2API provides Gemma 4 E4B (gemma4:e4b) text generation via an
  OpenAI-compatible chat completions endpoint. Use this skill when the user
  needs LLM inference, text generation, summarization, translation, or
  question answering without calling the Anthropic or OpenAI APIs directly.
  This is the LLM backbone of OSS2API, developed within the URL2AI project.
  Payment is handled via Bankr x402 (USDC on Base) or JPYC x402 (JPYC on Polygon).
---

# LLM2API — Gemma 4 E4B Inference

OpenAI-compatible LLM inference powered by [Gemma 4 E4B](https://ai.google.dev/gemma) (gemma4:e4b) via Ollama.
The LLM backbone of OSS2API, developed within the URL2AI project.

GitHub: [katsushi2441/url2ai](https://github.com/katsushi2441/url2ai)

## Endpoints

| Gateway | URL | Payment |
|---|---|---|
| Bankr x402 | `https://x402.bankr.bot/0x444fadbd6e1fed0cfbf7613b6c9f91b9021eecbd/llm2api` | USDC on Base ($0.01/req) |
| JPYC x402 | `https://exbridge.ddns.net:8020` | JPYC on Polygon (1.5 JPYC/req) |

**Use the Bankr endpoint by default.**

## Usage

`POST {base}/v1/chat/completions`

OpenAI-compatible. Drop-in replacement for `openai.chat.completions.create` calls.

### Parameters

| Field | Type | Required | Description |
|---|---|---|---|
| `messages` | array | Yes | Array of `{role, content}` objects. Roles: `system`, `user`, `assistant`. |
| `stream` | boolean | No | Enable SSE streaming. Default `false`. |
| `temperature` | number | No | Sampling temperature 0.0–2.0. Default `0.7`. |
| `max_tokens` | integer | No | Max tokens to generate. Hard cap: **2,048**. |

### Limits

- **Input**: total text across all messages must not exceed **4,000 characters**; max **20 messages** per request
- **Output**: hard cap of **2,048 tokens** per request regardless of `max_tokens`

### Request example

```json
{
  "messages": [
    { "role": "system", "content": "You are a helpful assistant." },
    { "role": "user", "content": "日本語で自己紹介して" }
  ]
}
```

### Response

```json
{
  "id": "chatcmpl-...",
  "object": "chat.completion",
  "model": "gemma4:e4b",
  "choices": [
    {
      "index": 0,
      "message": { "role": "assistant", "content": "..." },
      "finish_reason": "stop"
    }
  ],
  "usage": {
    "prompt_tokens": 30,
    "completion_tokens": 120,
    "total_tokens": 150
  }
}
```

## Code Examples

### curl (Bankr x402)

```bash
bankr x402 call https://x402.bankr.bot/0x444fadbd6e1fed0cfbf7613b6c9f91b9021eecbd/llm2api/v1/chat/completions \
  -X POST -H 'content-type: application/json' \
  -d '{"messages":[{"role":"user","content":"日本語で自己紹介して"}]}'
```

### Python (JPYC endpoint — no payment header for local/free tier)

```python
import httpx

resp = httpx.post(
    "https://exbridge.ddns.net:8020/v1/chat/completions",
    json={
        "messages": [{"role": "user", "content": "Summarize this in one sentence."}],
        "temperature": 0.5,
    },
    timeout=60,
)
result = resp.json()
print(result["choices"][0]["message"]["content"])
```

### Python (OpenAI SDK — Bankr endpoint)

```python
from openai import OpenAI

client = OpenAI(
    api_key="<bankr-wallet-api-key>",
    base_url="https://x402.bankr.bot/0x444fadbd6e1fed0cfbf7613b6c9f91b9021eecbd/llm2api/v1",
)

response = client.chat.completions.create(
    model="gemma4:e4b",
    messages=[{"role": "user", "content": "Hello!"}],
)
print(response.choices[0].message.content)
```

## Workflow

1. Build a `messages` array with `system` (optional) and `user` turns.
2. POST to the Bankr endpoint via `bankr x402 call`, or use the JPYC endpoint directly.
3. Read `choices[0].message.content` from the response.
4. Keep total input under 4,000 characters to avoid a 400 error.

## Schema discovery

```bash
bankr x402 schema https://x402.bankr.bot/0x444fadbd6e1fed0cfbf7613b6c9f91b9021eecbd/llm2api
```
