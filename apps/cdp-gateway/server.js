import express from "express";
import { paymentMiddleware } from "x402-express";
import { createFacilitatorConfig } from "@coinbase/x402";

const PORT       = Number.parseInt(process.env.PORT || "8021", 10);
const PAY_TO     = process.env.PAY_TO || "";
const OSS2API    = process.env.OSS2API_URL || "http://127.0.0.1:8015";
const LLM_URL    = process.env.LLM_URL    || "http://127.0.0.1:8019";
const NETWORK    = process.env.NETWORK    || "base";
const PRICE_OSS  = process.env.PRICE_OSS  || "$0.01";
const PRICE_LLM  = process.env.PRICE_LLM  || "$0.05";
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

function paidRoute(description, inputSchema) {
  return {
    price: PRICE_OSS,
    network: NETWORK,
    config: {
      description,
      discoverable: true,
      inputSchema,
    },
  };
}

function llmRoute() {
  return {
    price: PRICE_LLM,
    network: NETWORK,
    config: {
      description: "OpenAI-compatible chat completions via Gemma 4 12B (Ollama)",
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
  };
}

function tradeRiskRoute() {
  return {
    price: PRICE_LLM,
    network: NETWORK,
    config: {
      description: "Crypto token risk check: scans recent news for hack/exploit/delisting/rug-pull/lawsuit events and returns a block/ok verdict with classified evidence. Same pipeline that protects the live Kurage FreqAI trading system.",
      discoverable: true,
      inputSchema: {
        bodyType: "json",
        properties: {
          symbol: { type: "string", description: "Base symbol, e.g. BTC (2-15 alphanumeric chars)" },
        },
      },
    },
  };
}

function tradeSizeRoute() {
  return {
    price: PRICE_LLM,
    network: NETWORK,
    config: {
      description: "Crypto order size / liquidity check: applies the live Kurage FreqAI 0.1%-of-24h-volume cap and returns max safe size with thin-market warning. Sub-second.",
      discoverable: true,
      inputSchema: {
        bodyType: "json",
        properties: {
          symbol: { type: "string", description: "Base symbol, e.g. DOGE" },
          order_size_usdt: { type: "number", description: "Intended order size in USDT" },
        },
      },
    },
  };
}

if (!PAY_TO) { console.error("PAY_TO is required"); process.exit(1); }

const facilitator = createFacilitatorConfig(
  process.env.CDP_API_KEY_ID,
  process.env.CDP_API_KEY_SECRET,
);

const routes = {
  "GET /background-removal": paidRoute("Remove or replace image background (imgly AGPL-3.0)", BACKGROUND_REMOVAL_SCHEMA),
  "POST /background-removal": paidRoute("Remove or replace image background (imgly AGPL-3.0)", BACKGROUND_REMOVAL_SCHEMA),
  "GET /oss2api": paidRoute("OSS2API multi-skill agent gateway", { bodyType: "json", properties: {} }),
  "POST /oss2api": paidRoute("OSS2API multi-skill agent gateway", { bodyType: "json", properties: {} }),
  "GET /oss2api/": paidRoute("OSS2API multi-skill agent gateway", { bodyType: "json", properties: {} }),
  "POST /oss2api/": paidRoute("OSS2API multi-skill agent gateway", { bodyType: "json", properties: {} }),
  "GET /oss2api/image/remove-background": paidRoute("Remove or replace image background (imgly AGPL-3.0)", BACKGROUND_REMOVAL_SCHEMA),
  "POST /oss2api/image/remove-background": paidRoute("Remove or replace image background (imgly AGPL-3.0)", BACKGROUND_REMOVAL_SCHEMA),
  "GET /oss2api/url/analyze": {
    price: PRICE_OSS,
    network: NETWORK,
    config: {
      description: "Extract title, headings, links and entities from a URL",
      discoverable: true,
      inputSchema: { bodyType: "json", properties: { url: { type: "string", description: "Target URL" } } },
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
  "GET /oss2api/url/browse": {
    price: PRICE_OSS,
    network: NETWORK,
    config: {
      description: "Playwright screenshot and dynamic content extraction from a URL",
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
  "GET /oss2api/url/scan": {
    price: PRICE_OSS,
    network: NETWORK,
    config: {
      description: "3-phase security scan: HTTP headers + static HTML + AI analysis",
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
  "GET /llm2api": llmRoute(),
  "POST /llm2api": llmRoute(),
  "GET /llm2api/": llmRoute(),
  "POST /llm2api/": llmRoute(),
  "GET /llm2api/v1/chat/completions": llmRoute(),
  "POST /llm2api/v1/chat/completions": llmRoute(),
  "GET /llm/v1/chat/completions": llmRoute(),
  "POST /llm/v1/chat/completions": llmRoute(),
  "GET /llm2api/trade/risk-check": tradeRiskRoute(),
  "POST /llm2api/trade/risk-check": tradeRiskRoute(),
  "GET /llm2api/trade/size-check": tradeSizeRoute(),
  "POST /llm2api/trade/size-check": tradeSizeRoute(),
};

const app = express();
app.set("trust proxy", true);
app.use(express.json({ limit: "20mb" }));
app.use((_req, res, next) => {
  const originalJson = res.json.bind(res);
  res.json = (body) => {
    if (res.statusCode === 402 && body && Array.isArray(body.accepts)) {
      const encoded = Buffer.from(JSON.stringify(body)).toString("base64");
      res.setHeader("PAYMENT-REQUIRED", encoded);
      res.setHeader("X-PAYMENT-REQUIRED", encoded);
      res.setHeader("Access-Control-Expose-Headers", "PAYMENT-REQUIRED, X-PAYMENT-REQUIRED, X-PAYMENT-RESPONSE");
    }
    return originalJson(body);
  };
  next();
});
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
  "pay_to": WALLET,
  "wallet": WALLET,
  "treasury": WALLET,
  "network": NETWORK,
  "endpoints": [
    {
      "path": "/background-removal",
      "method": "POST",
      "price": PRICE_OSS,
      "network": NETWORK,
      "pay_to": WALLET,
      "description": "Remove or replace image background (imgly/background-removal-js AGPL-3.0)"
    },
    {
      "path": "/oss2api",
      "method": "POST",
      "price": PRICE_OSS,
      "network": NETWORK,
      "pay_to": WALLET,
      "description": "OSS2API multi-skill agent gateway"
    },
    {
      "path": "/oss2api/image/remove-background",
      "method": "POST",
      "price": PRICE_OSS,
      "network": NETWORK,
      "pay_to": WALLET,
      "description": "Remove or replace image background (imgly/background-removal-js AGPL-3.0)"
    },
    {
      "path": "/oss2api/url/analyze",
      "method": "POST",
      "price": PRICE_OSS,
      "network": NETWORK,
      "pay_to": WALLET,
      "description": "Extract structured content from a URL"
    },
    {
      "path": "/oss2api/url/browse",
      "method": "POST",
      "price": PRICE_OSS,
      "network": NETWORK,
      "pay_to": WALLET,
      "description": "Playwright screenshot and content extraction"
    },
    {
      "path": "/oss2api/url/scan",
      "method": "POST",
      "price": PRICE_OSS,
      "network": NETWORK,
      "pay_to": WALLET,
      "description": "3-phase security scan"
    },
    {
      "path": "/llm2api",
      "method": "POST",
      "price": PRICE_LLM,
      "network": NETWORK,
      "pay_to": WALLET,
      "description": "OpenAI-compatible chat completions via Gemma 4 12B (Ollama)"
    },
    {
      "path": "/llm2api/v1/chat/completions",
      "method": "POST",
      "price": PRICE_LLM,
      "network": NETWORK,
      "pay_to": WALLET,
      "description": "OpenAI-compatible chat completions via Gemma 4 12B (Ollama)"
    },
    {
      "path": "/llm/v1/chat/completions",
      "method": "POST",
      "price": PRICE_LLM,
      "network": NETWORK,
      "pay_to": WALLET,
      "description": "OpenAI-compatible chat completions via Gemma 4 12B (Ollama)"
    },
    {
      "path": "/llm2api/trade/risk-check",
      "method": "POST",
      "price": PRICE_LLM,
      "network": NETWORK,
      "pay_to": WALLET,
      "description": "Crypto token risk check: recent hack/exploit/delisting/rug-pull/lawsuit scan with block/ok verdict"
    },
    {
      "path": "/llm2api/trade/size-check",
      "method": "POST",
      "price": PRICE_LLM,
      "network": NETWORK,
      "pay_to": WALLET,
      "description": "Crypto order size / liquidity check: max safe size from 24h volume (0.1% cap rule)"
    }
  ]
};

app.get("/.well-known/x402.json", (_req, res) => {
  res.json(X402_WELL_KNOWN);
});

app.post("/background-removal",               (req, res) => proxyTo(`${OSS2API}/oss2api/image/remove-background`, req, res));
app.post("/oss2api",                          (req, res) => proxyTo(`${OSS2API}/oss2api/url/analyze`, req, res));
app.post("/oss2api/",                         (req, res) => proxyTo(`${OSS2API}/oss2api/url/analyze`, req, res));
app.post("/oss2api/image/remove-background", (req, res) => proxyTo(`${OSS2API}/oss2api/image/remove-background`, req, res));
app.post("/oss2api/url/analyze",             (req, res) => proxyTo(`${OSS2API}/oss2api/url/analyze`, req, res));
app.post("/oss2api/url/browse",              (req, res) => proxyTo(`${OSS2API}/oss2api/url/browse`, req, res));
app.post("/oss2api/url/scan",                (req, res) => proxyTo(`${OSS2API}/oss2api/url/scan`, req, res));
app.post("/llm2api",                         (req, res) => proxyTo(`${LLM_URL}/v1/chat/completions`, req, res));
app.post("/llm2api/",                        (req, res) => proxyTo(`${LLM_URL}/v1/chat/completions`, req, res));
app.post("/llm2api/v1/chat/completions",     (req, res) => proxyTo(`${LLM_URL}/v1/chat/completions`, req, res));
app.post("/llm/v1/chat/completions",         (req, res) => proxyTo(`${LLM_URL}/v1/chat/completions`, req, res));
app.post("/llm2api/trade/risk-check",        (req, res) => proxyTo(`${LLM_URL}/trade/risk-check`, req, res));
app.post("/llm2api/trade/size-check",        (req, res) => proxyTo(`${LLM_URL}/trade/size-check`, req, res));

app.listen(PORT, "0.0.0.0", () => {
  console.log(`CDP gateway → http://0.0.0.0:${PORT}`);
  console.log(`  OSS2API: ${OSS2API}  LLM: ${LLM_URL}`);
  console.log(`  Network: ${NETWORK}  PayTo: ${PAY_TO}`);
});
