import http from "node:http";
import { Buffer } from "node:buffer";

const HOST               = process.env.HOST                || "0.0.0.0";
const PORT               = Number.parseInt(process.env.PORT || "8018", 10);
const OLLAMA_HOST        = process.env.OLLAMA_HOST         || "192.168.0.14";
const OLLAMA_PORT        = Number.parseInt(process.env.OLLAMA_PORT || "11434", 10);
const DEFAULT_MODEL      = process.env.DEFAULT_MODEL       || "gemma4:e4b";
const RAPIDAPI_SECRET    = process.env.RAPIDAPI_PROXY_SECRET || "";
// kfreqai judgment API (trade pre-checks)
const JUDGMENT_HOST      = process.env.JUDGMENT_HOST || "127.0.0.1";
const JUDGMENT_PORT      = Number.parseInt(process.env.JUDGMENT_PORT || "18321", 10);
// Kurage judgment brains。RapidAPIは有料の販路なので Bankr(x402)/JPYC/ACP と同じく DeepSeek で
// 応答する(Providerヘッダ注入)。ローカルGemmaは無料・内部の直叩き(webコンソール/kfreqai毎時/
// kfxai)専用で、有料マーケット販売では使わない。
const KCBRAIN_HOST       = process.env.KCBRAIN_HOST || "127.0.0.1";
const KCBRAIN_PORT       = Number.parseInt(process.env.KCBRAIN_PORT || "18328", 10);
const KCBRAIN_TOKEN      = process.env.KCBRAIN_TOKEN || "";
const FXBRAIN_HOST       = process.env.FXBRAIN_HOST || "127.0.0.1";
const FXBRAIN_PORT       = Number.parseInt(process.env.FXBRAIN_PORT || "18326", 10);
const FXBRAIN_TOKEN      = process.env.FXBRAIN_TOKEN || "";
const BRAIN_ROUTES = {
  "/kcbrain/": { host: KCBRAIN_HOST, port: KCBRAIN_PORT, tokenHeader: "X-KCBRAIN-Token", token: KCBRAIN_TOKEN, providerHeader: "X-KCBRAIN-Provider" },
  "/fxbrain/": { host: FXBRAIN_HOST, port: FXBRAIN_PORT, tokenHeader: "X-KFXBRAIN-Token", token: FXBRAIN_TOKEN, providerHeader: "X-KFXBrain-Provider" },
};
// URL2Brain(コンテンツ生成+Kurage自身のSNS/ブログへの投稿)。Bankr/cdp-gatewayと同一挙動:
// LLM生成系は body に provider:"deepseek" を注入、投稿系は confirm_post:true + persona を注入。
const URL2BRAIN_HOST     = process.env.URL2BRAIN_HOST || "127.0.0.1";
const URL2BRAIN_PORT     = Number.parseInt(process.env.URL2BRAIN_PORT || "18332", 10);
const URL2BRAIN_TOKEN    = process.env.URL2BRAIN_TOKEN || "";
const URL2BRAIN_LLM_SUFFIXES = new Set(["generate/announcement", "generate/blog-article", "generate/from-url"]);
const URL2BRAIN_POST_PERSONA = {
  "post/bluesky": "kurage", "post/hatena-bookmark": "", "post/aixsns": "bittensorman",
  "post/bludit": "kurage", "post/hatena-blog": "bittensorman",
};
const BRAIN_TIMEOUT_MS   = Number.parseInt(process.env.BRAIN_TIMEOUT_MS || "180000", 10);
const MAX_BODY_BYTES     = 256 * 1024;
const MAX_INPUT_CHARS    = Number.parseInt(process.env.MAX_INPUT_CHARS   || "4000", 10);
const MAX_MESSAGES       = Number.parseInt(process.env.MAX_MESSAGES      || "20",   10);
const MAX_OUTPUT_TOKENS  = Number.parseInt(process.env.MAX_OUTPUT_TOKENS || "2048", 10);

function json(res, status, data) {
  const body = JSON.stringify(data);
  res.writeHead(status, { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-store" });
  res.end(body);
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on("data", (chunk) => {
      size += chunk.length;
      if (size > MAX_BODY_BYTES) { reject(new Error("Body too large")); req.destroy(); return; }
      chunks.push(chunk);
    });
    req.on("end", () => resolve(Buffer.concat(chunks).toString("utf8")));
    req.on("error", reject);
  });
}

function proxyToOllama(req, res, ollamaPath, bodyStr) {
  const options = {
    hostname: OLLAMA_HOST,
    port: OLLAMA_PORT,
    path: ollamaPath,
    method: req.method,
    headers: {
      "Content-Type": "application/json",
      "Content-Length": Buffer.byteLength(bodyStr),
    },
  };

  const proxyReq = http.request(options, (proxyRes) => {
    // Transfer-Encodingは手で書かない(空値ヘッダは不正なHTTPになり、RapidAPI等の
    // 厳格なプロキシが502にする。Nodeが自動でchunkedを管理する)
    res.writeHead(proxyRes.statusCode || 200, {
      "Content-Type": proxyRes.headers["content-type"] || "application/json",
      "Cache-Control": "no-store",
    });
    proxyRes.pipe(res);
  });

  proxyReq.on("error", (err) => {
    json(res, 502, { error: `Ollama unavailable: ${err.message}` });
  });

  proxyReq.write(bodyStr);
  proxyReq.end();
}

// Kurage brain (kcbrain/kfxbrain) へ内部プロキシ。Providerヘッダは付けない=ローカルGemma。
function proxyToBrain(route, upstreamPath, res, bodyStr) {
  const headers = {
    "Content-Type": "application/json",
    "Content-Length": Buffer.byteLength(bodyStr),
  };
  if (route.token) {
    headers[route.tokenHeader] = route.token;
    headers["Authorization"] = `Bearer ${route.token}`;
  }
  // 有料販路(RapidAPI)なので DeepSeek を使う(Bankr/x402 と同一挙動)。
  if (route.providerHeader) headers[route.providerHeader] = "deepseek";
  const options = { hostname: route.host, port: route.port, path: upstreamPath,
    method: "POST", headers, timeout: BRAIN_TIMEOUT_MS };
  const proxyReq = http.request(options, (proxyRes) => {
    res.writeHead(proxyRes.statusCode || 200, {
      "Content-Type": proxyRes.headers["content-type"] || "application/json",
      "Cache-Control": "no-store",
    });
    proxyRes.pipe(res);
  });
  proxyReq.on("timeout", () => proxyReq.destroy(new Error("brain upstream timeout")));
  proxyReq.on("error", (err) => json(res, 502, { error: `brain unavailable: ${err.message}` }));
  proxyReq.write(bodyStr);
  proxyReq.end();
}

async function handle(req, res) {
  const url = new URL(req.url || "/", `http://${req.headers.host || "localhost"}`);
  const path = url.pathname;

  if (req.method === "GET" && ["/health", "/healthz"].includes(path)) {
    return json(res, 200, { ok: true, service: "llm-gateway-rapidapi", model: DEFAULT_MODEL });
  }

  // Validate RapidAPI proxy secret (skip check if secret not configured)
  if (RAPIDAPI_SECRET) {
    const incoming = req.headers["x-rapidapi-proxy-secret"] || "";
    console.log(`[secret] incoming="${incoming}" expected="${RAPIDAPI_SECRET}" match=${incoming === RAPIDAPI_SECRET}`);
    if (incoming !== RAPIDAPI_SECRET) {
      return json(res, 403, { error: "Forbidden" });
    }
  }

  if (req.method === "GET" && path === "/v1/models") {
    return json(res, 200, {
      object: "list",
      data: [{ id: DEFAULT_MODEL, object: "model", created: 0, owned_by: "ollama" }],
    });
  }

  if (req.method === "POST" && path === "/v1/chat/completions") {
    const bodyStr = await readBody(req);
    let body;
    try { body = JSON.parse(bodyStr); } catch { return json(res, 400, { error: "Invalid JSON" }); }

    if (!Array.isArray(body.messages) || body.messages.length === 0) {
      return json(res, 400, { error: "messages array is required" });
    }
    if (body.messages.length > MAX_MESSAGES) {
      return json(res, 400, { error: `Too many messages (max ${MAX_MESSAGES})` });
    }
    const totalChars = body.messages.reduce((sum, m) => sum + String(m.content || "").length, 0);
    if (totalChars > MAX_INPUT_CHARS) {
      return json(res, 400, { error: `Input too long (${totalChars} chars, max ${MAX_INPUT_CHARS})` });
    }

    body.model = DEFAULT_MODEL;
    if (!body.max_tokens || body.max_tokens > MAX_OUTPUT_TOKENS) {
      body.max_tokens = MAX_OUTPUT_TOKENS;
    }

    // gemma4は思考型モデル: 既定で思考を無効化しないと、低いmax_tokensで
    // 思考トークンがcontentを食い潰し空応答になる(PayAPI検証で実証)。
    // 呼び出し側が明示的にreasoning_effortを渡した場合のみ尊重する。
    if (body.reasoning_effort === undefined) {
      body.reasoning_effort = "none";
    }

    return proxyToOllama(req, res, "/v1/chat/completions", JSON.stringify(body));
  }

  // Trade pre-checks (kfreqai judgment API)
  if (req.method === "POST" && (path === "/trade/risk-check" || path === "/trade/size-check")) {
    const bodyStr = await readBody(req);
    try { JSON.parse(bodyStr); } catch { return json(res, 400, { error: "Invalid JSON" }); }
    const options = {
      hostname: JUDGMENT_HOST, port: JUDGMENT_PORT, path: `/v1${path}`, method: "POST",
      headers: { "Content-Type": "application/json", "Content-Length": Buffer.byteLength(bodyStr) },
      timeout: 180000,
    };
    const proxyReq = http.request(options, (proxyRes) => {
      res.writeHead(proxyRes.statusCode || 200, {
        "Content-Type": proxyRes.headers["content-type"] || "application/json",
        "Cache-Control": "no-store",
      });
      proxyRes.pipe(res);
    });
    proxyReq.on("timeout", () => proxyReq.destroy(new Error("upstream timeout")));
    proxyReq.on("error", (err) => json(res, 502, { error: `judgment API unavailable: ${err.message}` }));
    proxyReq.write(bodyStr);
    proxyReq.end();
    return;
  }

  // Kurage judgment brains: /kcbrain/<skill> と /fxbrain/<skill> を内部brainの /v1/<skill> へ中継。
  // 例) POST /kcbrain/analyze/technical, /fxbrain/decide/trade, /kcbrain/signal/pair/BTC_USDT
  if (req.method === "POST") {
    for (const [prefix, route] of Object.entries(BRAIN_ROUTES)) {
      if (path.startsWith(prefix)) {
        const skill = path.slice(prefix.length).replace(/^\/+/, "");
        if (!skill) return json(res, 400, { error: "skill path required", hint: `POST ${prefix}analyze/technical` });
        const bodyStr = await readBody(req);
        try { JSON.parse(bodyStr); } catch { return json(res, 400, { error: "Invalid JSON" }); }
        return proxyToBrain(route, `/v1/${skill}`, res, bodyStr);
      }
    }
  }

  // URL2Brain: /url2brain/<skill> を url2brain の /v1/<skill> へ中継(Bankrと同一のbody注入)。
  // 例) POST /url2brain/generate/from-url, /url2brain/post/bluesky
  if (req.method === "POST" && path.startsWith("/url2brain/")) {
    const suffix = path.slice("/url2brain/".length).replace(/^\/+/, "");
    if (!suffix) return json(res, 400, { error: "skill path required", hint: "POST /url2brain/generate/from-url" });
    const bodyStr = await readBody(req);
    let body;
    try { body = JSON.parse(bodyStr); } catch { return json(res, 400, { error: "Invalid JSON" }); }
    if (URL2BRAIN_LLM_SUFFIXES.has(suffix)) body.provider = "deepseek";
    if (Object.prototype.hasOwnProperty.call(URL2BRAIN_POST_PERSONA, suffix)) {
      body.confirm_post = true;
      const persona = URL2BRAIN_POST_PERSONA[suffix];
      if (persona) body.persona = persona;
    }
    const route = { host: URL2BRAIN_HOST, port: URL2BRAIN_PORT, tokenHeader: "X-URL2BRAIN-Token", token: URL2BRAIN_TOKEN };
    return proxyToBrain(route, `/v1/${suffix}`, res, JSON.stringify(body));
  }

  return json(res, 404, { error: "Not found", hint: "POST /v1/chat/completions" });
}

http.createServer((req, res) => {
  handle(req, res).catch((err) => json(res, 500, { error: err.message || String(err) }));
}).listen(PORT, HOST, () => {
  console.log(`LLM gateway (RapidAPI) → http://${HOST}:${PORT}`);
  console.log(`  Ollama: http://${OLLAMA_HOST}:${OLLAMA_PORT}  model: ${DEFAULT_MODEL}`);
  console.log(`  RapidAPI secret: ${RAPIDAPI_SECRET ? "configured" : "NOT SET (open access)"}`);
});
