import express from "express";
import nodeHttp from "node:http";
import { paymentMiddleware } from "x402-express";
import { createFacilitatorConfig } from "@coinbase/x402";

const PORT       = Number.parseInt(process.env.PORT || "8021", 10);
const PAY_TO     = process.env.PAY_TO || "";
const OSS2API    = process.env.OSS2API_URL || "http://127.0.0.1:8015";
const LLM_URL    = process.env.LLM_URL    || "http://127.0.0.1:8019";
const NETWORK    = process.env.NETWORK    || "base";
const PRICE_OSS  = process.env.PRICE_OSS  || "$0.01";
const PRICE_LLM  = process.env.PRICE_LLM  || "$0.05";
const FXBRAIN_URL   = process.env.FXBRAIN_URL   || "http://127.0.0.1:18326";
const FXBRAIN_TOKEN = process.env.FXBRAIN_TOKEN || "";
// TradingAgentsフルグラフは1回5分超(実測5.5分)のマルチエージェント討論なので高価格帯
const PRICE_FXGRAPH = process.env.PRICE_FXGRAPH || "$0.50";
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

// Kurage FX Brain (kfxbrain :18326) — FX judgment APIs backed by Gemma 4 12B.
// Vendored OSS intelligence: TradingAgents (Apache-2.0), FinGPT (MIT), ai-hedge-fund (MIT).
const FXBRAIN_EVIDENCE_SCHEMA = {
  bodyType: "json",
  properties: {
    pair: { type: "string", description: "FX pair like USD_JPY or EUR_USD (required)" },
    timeframe: { type: "string", description: "e.g. M15, H1, D (default H1)" },
    technicals: { type: "object", description: "Indicator values, ranges, closes" },
    macro: { type: "object", description: "Rates, CPI, policy expectations" },
    news: { type: "array", description: "Headlines (max 40)" },
    position: { type: "object", description: "Open position context" },
    history: { type: "array", description: "Recent trades (max 30)" },
    question: { type: "string", description: "Optional focused question" },
  },
};
const FXBRAIN_GRAPH_SCHEMA = {
  bodyType: "json",
  properties: {
    pair: { type: "string", description: "FX pair like USD_JPY (required)" },
    trade_date: { type: "string", description: "YYYY-MM-DD (default today)" },
    debate_rounds: { type: "integer", description: "1-3 (default 1)" },
    risk_rounds: { type: "integer", description: "1-3 (default 1)" },
    output_language: { type: "string", description: "Report language (default Japanese)" },
  },
};

const FXBRAIN_MARKET_SCHEMA = {
  bodyType: "json",
  properties: {
    pairs: { type: "array", description: "1-40 pair evidence objects: {pair, market, technicals, macro, flows, positioning, news}" },
    timeframe: { type: "string", description: "e.g. H1 (default)" },
    global_context: { type: "object", description: "Risk sentiment, calendar, cross-market context" },
    account_context: { type: "object", description: "Leverage, equity, margin (for margin-risk)" },
    question: { type: "string", description: "Optional focused question" },
  },
};

// gateway suffix -> [upstream path, price, description, schema]
const FXBRAIN_ENDPOINTS = {
  "analyze/technical": ["/v1/analyze/technical", PRICE_LLM,
    "FX technical analysis (trend, levels, momentum) as structured JSON with evidence and invalidation. Gemma 4 12B."],
  "analyze/macro": ["/v1/analyze/macro", PRICE_LLM,
    "FX macro analysis: rate differentials, growth, policy divergence as structured JSON. Gemma 4 12B."],
  "analyze/sentiment": ["/v1/analyze/sentiment", PRICE_LLM,
    "FX news and market sentiment analysis as structured JSON. Gemma 4 12B."],
  "analyze/full": ["/v1/analyze/full", PRICE_LLM,
    "All FX perspectives (technical, macro, sentiment, decision) in one structured response. Gemma 4 12B."],
  "debate/bull-bear": ["/v1/debate/bull-bear", PRICE_LLM,
    "Bull vs bear argument mapping for an FX pair as structured JSON. Gemma 4 12B."],
  "decide/trade": ["/v1/decide/trade", PRICE_LLM,
    "BUY / SELL / HOLD judgment for an FX pair from supplied evidence. Judgment only — never executes orders."],
  "assess/risk": ["/v1/assess/risk", PRICE_LLM,
    "Risk gate for a proposed FX trade: approve / reduce / reject with reasons. Judgment only."],
  "decide/portfolio": ["/v1/decide/portfolio", PRICE_LLM,
    "Manage open FX positions: hold / close / adjust judgments as structured JSON. Judgment only."],
  "review/trade": ["/v1/review/trade", PRICE_LLM,
    "Post-trade review: category, verdict, lesson from a closed FX trade. Gemma 4 12B."],
  "fingpt/sentiment": ["/v1/vendor/fingpt/sentiment", PRICE_LLM,
    "FinGPT (MIT) financial sentiment classification task executed on Gemma 4 12B."],
  "fingpt/headline": ["/v1/vendor/fingpt/headline", PRICE_LLM,
    "FinGPT (MIT) headline classification task executed on Gemma 4 12B."],
  "fingpt/forecast": ["/v1/vendor/fingpt/forecast", PRICE_LLM,
    "FinGPT (MIT) Forecaster task: evidence-grounded FX outlook on Gemma 4 12B."],
  "fingpt/report": ["/v1/vendor/fingpt/report", PRICE_LLM,
    "FinGPT (MIT) financial report analysis task executed on Gemma 4 12B."],
  "hedge/news-sentiment": ["/v1/vendor/ai-hedge-fund/news-sentiment", PRICE_LLM,
    "ai-hedge-fund (MIT) news sentiment agent: per-headline classification plus aggregate signal for an FX pair."],
  "hedge/portfolio": ["/v1/vendor/ai-hedge-fund/portfolio", PRICE_LLM,
    "ai-hedge-fund (MIT) portfolio manager synthesis over supplied analyst signals. Judgment only."],
  "finrobot/forecast": ["/v1/vendor/finrobot/forecast", PRICE_LLM,
    "FinRobot (Apache-2.0) Market Forecaster workflow: 2-4 positive developments and concerns, next-week move prediction with % range. Gemma 4 12B."],
  ...Object.fromEntries([
    "income_stmt", "balance_sheet", "cash_flow", "segment_stmt",
    "risk_assessment", "competitors", "business_highlights", "company_description",
  ].map((s) => [`finrobot/report/${s}`, [`/v1/vendor/finrobot/report/${s}`, PRICE_LLM,
    `FinRobot (Apache-2.0) analyst instruction: ${s.replace(/_/g, " ")} analysis on supplied evidence. Honestly reports missing data instead of inventing.`]])),
  "finmem/decide": ["/v1/vendor/finmem/decide", PRICE_LLM,
    "FinMem (MIT) layered-memory trading decision: pass short/mid/long/reflection memories via prior_reports{}; character switches risk-seeking/averse by cumulative return."],
  "finmem/reflect": ["/v1/vendor/finmem/reflect", PRICE_LLM,
    "FinMem (MIT) reflection loop: extract the lesson from a trade outcome plus supporting memories, ready to store as a reflection memory."],
  "market/opportunity-ranking": ["/v1/market/opportunity-ranking", PRICE_LLM,
    "Multi-pair FX opportunity ranking (up to 40 pairs in one call): risk-adjusted scores, duplicated-currency exposure conflicts, event risk. Body: {pairs:[{pair, technicals, news, flows, positioning}...], global_context}. Gemma 4 12B.",
    FXBRAIN_MARKET_SCHEMA],
  "market/flow-ranking": ["/v1/market/flow-ranking", PRICE_LLM,
    "Multi-pair currency-flow strength ranking from supplied COT/positioning, rate differential, carry and liquidity evidence. Same multi-pair body. Gemma 4 12B.",
    FXBRAIN_MARKET_SCHEMA],
  "market/anomaly": ["/v1/market/anomaly", PRICE_LLM,
    "Cross-pair FX anomaly detection: price/spread/volatility/volume/rates/positioning/correlation/intervention/liquidity, severity low-critical. Ordinary volatility is not flagged. Same multi-pair body.",
    FXBRAIN_MARKET_SCHEMA],
  "market/margin-risk": ["/v1/market/margin-risk", PRICE_LLM,
    "Margin-call and stop-out risk ranking per pair plus systemic risk, from supplied leverage/equity/margin thresholds. Never assumes broker rules. Same multi-pair body.",
    FXBRAIN_MARKET_SCHEMA],
  ...Object.fromEntries([
    "USD_JPY", "EUR_JPY", "GBP_JPY", "EUR_USD", "GBP_USD", "AUD_USD",
  ].map((p) => [`signal/pair/${p}`, [`/v1/signal/pair/${p}`, PRICE_LLM,
    `Single evidence-bounded FX signal for ${p}: watch_buy_base/watch_sell_base/wait/avoid with invalidation and event risks. Judgment only, never places orders.`]])),
  "tradingagents/run": ["/v1/vendor/tradingagents/run", PRICE_FXGRAPH,
    "TradingAgents (Apache-2.0) full multi-agent graph on real FX market data: analyst reports, bull/bear debate, trader plan, risk debate, final decision. Runs ~2.5 min (fast multi-agent profile), not seconds; over the gateway deadline it fails cleanly with no charge. Gemma 4 12B.",
    FXBRAIN_GRAPH_SCHEMA, 180],
};

function fxbrainRoute(price, description, schema, maxTimeoutSeconds) {
  const config = { description, discoverable: true, inputSchema: schema || FXBRAIN_EVIDENCE_SCHEMA };
  // 既定のmaxTimeoutSeconds(60)はEIP-3009のvalidBefore=now+60sを意味する。
  // 処理が60秒を超えるエンドポイント(tradingagents/run ~5.5分)はここで延長しないと、
  // 決済時にblock.timestamp>validBeforeで期限切れ拒否され、GPU計算だけ浪費される
  // (PayApi Chet 2026-07-17指摘)。
  if (maxTimeoutSeconds) config.maxTimeoutSeconds = maxTimeoutSeconds;
  return { price, network: NETWORK, config };
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

for (const [suffix, [, price, description, schema, maxTimeoutSeconds]] of Object.entries(FXBRAIN_ENDPOINTS)) {
  routes[`POST /fxbrain/${suffix}`] = fxbrainRoute(price, description, schema, maxTimeoutSeconds);
}

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
  "x402Version": 1,
  "pay_to": WALLET,
  "wallet": WALLET,
  "treasury": WALLET,
  "network": NETWORK,
  "endpoints": [
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

for (const [suffix, [, price, description]] of Object.entries(FXBRAIN_ENDPOINTS)) {
  X402_WELL_KNOWN.endpoints.push({
    path: `/fxbrain/${suffix}`,
    method: "POST",
    price,
    network: NETWORK,
    pay_to: WALLET,
    description,
  });
}

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

// kfxbrain proxy: node:httpで長タイムアウト(TradingAgentsフルグラフは実測5.5分、
// fetch/undiciの既定headersTimeout 300秒では途中で切れる)。認証トークンを注入。
// エッジ(bittensorman.xyz/nginx)のproxy_read_timeoutが約180秒。ここより手前で
// 打ち切って >=400 を返せば、x402-expressのpaymentMiddlewareは settle をスキップする
// (res.statusCode >= 400 で決済しない)。これで「処理が長引いた時に課金だけされて
// 納品されない(charge-without-delivery)」を構造的に防ぐ。PayApi/Chet 2026-07-18指摘。
const FXBRAIN_DEADLINE_MS = Number(process.env.FXBRAIN_DEADLINE_MS || 170000);

function proxyToFxbrain(upstreamPath, req, res) {
  const body = JSON.stringify(req.body);
  const url = new URL(`${FXBRAIN_URL}${upstreamPath}`);
  const options = {
    hostname: url.hostname,
    port: url.port,
    path: url.pathname,
    method: "POST",
    timeout: FXBRAIN_DEADLINE_MS + 10000,
    headers: {
      "Content-Type": "application/json",
      "Content-Length": Buffer.byteLength(body),
      "X-KFXBRAIN-Token": FXBRAIN_TOKEN,
    },
  };
  let settled = false;
  const once = (fn) => { if (!settled) { settled = true; clearTimeout(deadline); fn(); } };
  const proxyReq = nodeHttp.request(options, (upRes) => {
    once(() => {
      res.status(upRes.statusCode || 502);
      res.set("Content-Type", upRes.headers["content-type"] || "application/json");
      upRes.pipe(res);
    });
  });
  // ハード締切: 超えたら上流を切って504を返す → x402は課金しない(no charge)。
  const deadline = setTimeout(() => {
    once(() => {
      proxyReq.destroy(new Error("fxbrain deadline"));
      if (!res.headersSent) {
        res.status(504).json({
          error: "workflow exceeded the gateway deadline; no payment was captured. Please retry.",
        });
      }
    });
  }, FXBRAIN_DEADLINE_MS);
  proxyReq.on("timeout", () => proxyReq.destroy(new Error("fxbrain upstream timeout")));
  proxyReq.on("error", (err) => {
    once(() => { if (!res.headersSent) res.status(502).json({ error: `fxbrain unavailable: ${err.message}` }); });
  });
  proxyReq.write(body);
  proxyReq.end();
}

for (const [suffix, [upstreamPath]] of Object.entries(FXBRAIN_ENDPOINTS)) {
  app.post(`/fxbrain/${suffix}`, (req, res) => proxyToFxbrain(upstreamPath, req, res));
}

app.listen(PORT, "0.0.0.0", () => {
  console.log(`CDP gateway → http://0.0.0.0:${PORT}`);
  console.log(`  OSS2API: ${OSS2API}  LLM: ${LLM_URL}`);
  console.log(`  Network: ${NETWORK}  PayTo: ${PAY_TO}`);
});
