import express from "express";
import { paymentMiddleware } from "x402-express";
import { createFacilitatorConfig } from "@coinbase/x402";

const PORT       = Number.parseInt(process.env.PORT || "8021", 10);
const PAY_TO     = process.env.PAY_TO || "";
const OSS2API    = process.env.OSS2API_URL || "http://127.0.0.1:8015";
const LLM_URL    = process.env.LLM_URL    || "http://127.0.0.1:8019";
const NETWORK    = process.env.NETWORK    || "base";
const PRICE_OSS  = process.env.PRICE_OSS  || "$0.01";
const PRICE_LLM  = process.env.PRICE_LLM  || "$0.01";
const BACKGROUND_REMOVAL_SCHEMA = {
  bodyType: "json",
  properties: {
    image_url: { type: "string", description: "Public image URL" },
    image_base64: { type: "string", description: "Base64 encoded image" },
    mode: { type: "string", description: "remove, replace, or blur" },
    background_color: { type: "string", description: "Replacement color for mode=replace" },
    background_image_url: { type: "string", description: "Replacement image URL for mode=replace" },
    response: { type: "string", description: "json or binary" },
  },
};

if (!PAY_TO) { console.error("PAY_TO is required"); process.exit(1); }

const facilitator = createFacilitatorConfig(
  process.env.CDP_API_KEY_ID,
  process.env.CDP_API_KEY_SECRET,
);

const routes = {
  "POST /background-removal": {
    price: PRICE_OSS,
    network: NETWORK,
    config: {
      description: "Remove or replace image background (imgly AGPL-3.0)",
      discoverable: true,
      inputSchema: BACKGROUND_REMOVAL_SCHEMA,
    },
  },
  "POST /oss2api/image/remove-background": {
    price: PRICE_OSS,
    network: NETWORK,
    config: {
      description: "Remove or replace image background (imgly AGPL-3.0)",
      discoverable: true,
      inputSchema: BACKGROUND_REMOVAL_SCHEMA,
    },
  },
  "POST /oss2api/url/analyze": {
    price: PRICE_OSS,
    network: NETWORK,
    config: {
      description: "Extract title, headings, links and entities from a URL",
      discoverable: true,
      inputSchema: { bodyType: "json", properties: { url: { type: "string", description: "Target URL" } } },
    },
  },
  "POST /oss2api/url/browse": {
    price: PRICE_OSS,
    network: NETWORK,
    config: {
      description: "Playwright screenshot and dynamic content extraction from a URL",
      discoverable: true,
      inputSchema: { bodyType: "json", properties: { url: { type: "string", description: "Target URL" } } },
    },
  },
  "POST /oss2api/url/scan": {
    price: PRICE_OSS,
    network: NETWORK,
    config: {
      description: "3-phase security scan: HTTP headers + static HTML + AI analysis",
      discoverable: true,
      inputSchema: { bodyType: "json", properties: { url: { type: "string", description: "Target URL" } } },
    },
  },
  "POST /llm/v1/chat/completions": {
    price: PRICE_LLM,
    network: NETWORK,
    config: {
      description: "OpenAI-compatible chat completions via Gemma 4 E4B (Ollama)",
      discoverable: true,
      inputSchema: {
        bodyType: "json",
        properties: {
          messages: { type: "array", description: "Array of {role, content} objects" },
          temperature: { type: "number" },
          max_tokens: { type: "integer" },
        },
      },
    },
  },
};

const app = express();
app.set("trust proxy", true);
app.use(express.json({ limit: "20mb" }));
app.use(paymentMiddleware(PAY_TO, routes, facilitator));

async function proxyTo(url, req, res) {
  try {
    const upstream = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(req.body),
    });
    const contentType = upstream.headers.get("content-type") || "application/json";
    const bytes = await upstream.arrayBuffer();
    res.status(upstream.status).set("Content-Type", contentType).end(Buffer.from(bytes));
  } catch (err) {
    res.status(502).json({ error: `Upstream unavailable: ${err.message}` });
  }
}

app.get(["/health", "/healthz"], (_req, res) => {
  res.json({ ok: true, service: "cdp-gateway", port: PORT });
});

const WALLET = PAY_TO;
const X402_WELL_KNOWN = {
  "version": "1",
  "endpoints": [
    {
      "path": "/background-removal",
      "method": "POST",
      "price": PRICE_OSS,
      "network": NETWORK,
      "description": "Remove or replace image background (imgly/background-removal-js AGPL-3.0)"
    },
    {
      "path": "/oss2api/image/remove-background",
      "method": "POST",
      "price": PRICE_OSS,
      "network": NETWORK,
      "description": "Remove or replace image background (imgly/background-removal-js AGPL-3.0)"
    },
    {
      "path": "/oss2api/url/analyze",
      "method": "POST",
      "price": PRICE_OSS,
      "network": NETWORK,
      "description": "Extract structured content from a URL"
    },
    {
      "path": "/oss2api/url/browse",
      "method": "POST",
      "price": PRICE_OSS,
      "network": NETWORK,
      "description": "Playwright screenshot and content extraction"
    },
    {
      "path": "/oss2api/url/scan",
      "method": "POST",
      "price": PRICE_OSS,
      "network": NETWORK,
      "description": "3-phase security scan"
    },
    {
      "path": "/llm/v1/chat/completions",
      "method": "POST",
      "price": PRICE_LLM,
      "network": NETWORK,
      "description": "OpenAI-compatible chat completions via Gemma 4 E4B (Ollama)"
    }
  ]
};

app.get("/.well-known/x402.json", (_req, res) => {
  res.json(X402_WELL_KNOWN);
});

app.post("/background-removal",               (req, res) => proxyTo(`${OSS2API}/oss2api/image/remove-background`, req, res));
app.post("/oss2api/image/remove-background", (req, res) => proxyTo(`${OSS2API}/oss2api/image/remove-background`, req, res));
app.post("/oss2api/url/analyze",             (req, res) => proxyTo(`${OSS2API}/oss2api/url/analyze`, req, res));
app.post("/oss2api/url/browse",              (req, res) => proxyTo(`${OSS2API}/oss2api/url/browse`, req, res));
app.post("/oss2api/url/scan",                (req, res) => proxyTo(`${OSS2API}/oss2api/url/scan`, req, res));
app.post("/llm/v1/chat/completions",         (req, res) => proxyTo(`${LLM_URL}/v1/chat/completions`, req, res));

app.listen(PORT, "0.0.0.0", () => {
  console.log(`CDP gateway → http://0.0.0.0:${PORT}`);
  console.log(`  OSS2API: ${OSS2API}  LLM: ${LLM_URL}`);
  console.log(`  Network: ${NETWORK}  PayTo: ${PAY_TO}`);
});
