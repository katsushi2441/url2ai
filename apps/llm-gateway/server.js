import http from "node:http";
import { Buffer } from "node:buffer";

const HOST          = process.env.HOST         || "0.0.0.0";
const PORT          = Number.parseInt(process.env.PORT || "8019", 10);
const OLLAMA_HOST   = process.env.OLLAMA_HOST  || "192.168.0.14";
const OLLAMA_PORT   = Number.parseInt(process.env.OLLAMA_PORT || "11434", 10);
const DEFAULT_MODEL = process.env.DEFAULT_MODEL || "gemma4:e4b";
// kfreqai judgment API (trade pre-checks: risk-check / size-check)
const JUDGMENT_HOST = process.env.JUDGMENT_HOST || "127.0.0.1";
const JUDGMENT_PORT = Number.parseInt(process.env.JUDGMENT_PORT || "18321", 10);
const MAX_BODY_BYTES   = 64 * 1024;                                      // 64KB body limit
const MAX_INPUT_CHARS  = Number.parseInt(process.env.MAX_INPUT_CHARS  || "4000",  10); // total message chars
const MAX_MESSAGES     = Number.parseInt(process.env.MAX_MESSAGES     || "20",    10); // message count
const MAX_OUTPUT_TOKENS = Number.parseInt(process.env.MAX_OUTPUT_TOKENS || "2048", 10); // forced cap

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

function normalizeSkill(pathname) {
  return pathname.startsWith("/llm/") ? pathname.slice("/llm".length) : pathname;
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

function proxyToJudgment(res, path, bodyStr) {
  const options = {
    hostname: JUDGMENT_HOST,
    port: JUDGMENT_PORT,
    path,
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "Content-Length": Buffer.byteLength(bodyStr),
    },
    // risk-checkはニュース収集+LLM分類で数十秒かかることがある
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
  proxyReq.on("error", (err) => {
    json(res, 502, { error: `judgment API unavailable: ${err.message}` });
  });
  proxyReq.write(bodyStr);
  proxyReq.end();
}

async function handle(req, res) {
  const url = new URL(req.url || "/", `http://${req.headers.host || "localhost"}`);
  const skill = normalizeSkill(url.pathname);

  // Health
  if (req.method === "GET" && ["/health", "/healthz"].includes(skill)) {
    return json(res, 200, { ok: true, service: "llm-gateway", model: DEFAULT_MODEL, ollama: `${OLLAMA_HOST}:${OLLAMA_PORT}` });
  }

  // List models
  if (req.method === "GET" && skill === "/v1/models") {
    return json(res, 200, {
      object: "list",
      data: [{ id: DEFAULT_MODEL, object: "model", created: 0, owned_by: "ollama" }],
    });
  }

  // Chat completions (OpenAI-compatible)
  if (req.method === "POST" && skill === "/v1/chat/completions") {
    const bodyStr = await readBody(req);
    let body;
    try { body = JSON.parse(bodyStr); } catch { return json(res, 400, { error: "Invalid JSON" }); }

    // Validate messages
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

    // Force model and cap output tokens
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
  // risk-check: 銘柄の直近ネガティブイベント検査 / size-check: 流動性・注文サイズ診断
  if (req.method === "POST" && (skill === "/trade/risk-check" || skill === "/trade/size-check")) {
    const bodyStr = await readBody(req);
    try { JSON.parse(bodyStr); } catch { return json(res, 400, { error: "Invalid JSON" }); }
    return proxyToJudgment(res, `/v1${skill}`, bodyStr);
  }

  // Completions (legacy)
  if (req.method === "POST" && skill === "/v1/completions") {
    const bodyStr = await readBody(req);
    let body;
    try { body = JSON.parse(bodyStr); } catch { return json(res, 400, { error: "Invalid JSON" }); }
    body.model = DEFAULT_MODEL;
    return proxyToOllama(req, res, "/v1/completions", JSON.stringify(body));
  }

  return json(res, 404, { error: "Not found", hint: "POST /llm/v1/chat/completions" });
}

http.createServer((req, res) => {
  handle(req, res).catch((err) => json(res, 500, { error: err.message || String(err) }));
}).listen(PORT, HOST, () => {
  console.log(`LLM gateway → http://${HOST}:${PORT}`);
  console.log(`  Ollama: http://${OLLAMA_HOST}:${OLLAMA_PORT}  model: ${DEFAULT_MODEL}`);
});
