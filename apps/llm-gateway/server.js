import http from "node:http";
import https from "node:https";
import { Buffer } from "node:buffer";
import crypto from "node:crypto";
import { identify, meter, record, summary } from "./usage.js";
import { isAuthorized, parseAllowedIps } from "./access.js";

const HOST          = process.env.HOST         || "0.0.0.0";
const PORT          = Number.parseInt(process.env.PORT || "8019", 10);
const OLLAMA_HOST   = process.env.OLLAMA_HOST  || "192.168.0.14";
const OLLAMA_PORT   = Number.parseInt(process.env.OLLAMA_PORT || "11434", 10);
const DEFAULT_MODEL = process.env.DEFAULT_MODEL || "gemma4:e4b";
// LLMプロバイダ: ollama(セルフホストGemma) / deepseek(ホスト型・OpenAI互換)。
// 2026-07-22: x402の全LLM APIをDeepSeekへ統一(GPU競合/レイテンシ解消)。
const LLM_PROVIDER    = (process.env.LLM_PROVIDER || "ollama").trim().toLowerCase();
const DEEPSEEK_HOST   = (process.env.DEEPSEEK_HOST || "api.deepseek.com").replace(/^https?:\/\//, "");
const DEEPSEEK_KEY    = process.env.DEEPSEEK_API_KEY || "";
const DEEPSEEK_MODEL  = process.env.DEEPSEEK_MODEL || "deepseek-v4-flash";
const ACTIVE_MODEL    = LLM_PROVIDER === "deepseek" ? DEEPSEEK_MODEL : DEFAULT_MODEL;
// kfreqai judgment API (trade pre-checks: risk-check / size-check)
const JUDGMENT_HOST = process.env.JUDGMENT_HOST || "127.0.0.1";
const JUDGMENT_PORT = Number.parseInt(process.env.JUDGMENT_PORT || "18321", 10);
const MAX_BODY_BYTES   = 64 * 1024;                                      // 64KB body limit
const MAX_INPUT_CHARS  = Number.parseInt(process.env.MAX_INPUT_CHARS  || "4000",  10); // total message chars
const MAX_MESSAGES     = Number.parseInt(process.env.MAX_MESSAGES     || "20",    10); // message count
const MAX_OUTPUT_TOKENS = Number.parseInt(process.env.MAX_OUTPUT_TOKENS || "2048", 10); // forced cap
// 使用量サマリ(GET /usage)の閲覧トークン。未設定なら /usage は 404 で塞ぐ。
const USAGE_TOKEN = (process.env.LLM2API_USAGE_TOKEN || "").trim();

// --- 無課金での直叩き対策 (判定は access.js) -------------------------------
const API_TOKEN = (process.env.LLM2API_TOKEN || "").trim();
const ALLOWED_IPS = parseAllowedIps(process.env.LLM2API_ALLOWED_CLIENT_IPS);
// 既定は「記録するだけで遮断しない」。Bankr側のトークン設定が済んだのを
// 確認してから LLM2API_ENFORCE=true にする。いきなり遮断して売上を止めないため。
const ENFORCE = String(process.env.LLM2API_ENFORCE || "").toLowerCase() === "true";

function clientIp(req) {
  return (req.socket && req.socket.remoteAddress) || "";
}

function authorized(req) {
  return isAuthorized({ headers: req.headers, ip: clientIp(req) },
                      { token: API_TOKEN, allowedIps: ALLOWED_IPS });
}

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

function proxyToOllama(req, res, ollamaPath, bodyStr, meta) {
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
    if (meta) return meter(proxyRes, res, meta);
    proxyRes.pipe(res);
  });

  proxyReq.on("error", (err) => {
    if (meta) record({ ...meta, status: 502, error: err.message });
    json(res, 502, { error: `Ollama unavailable: ${err.message}` });
  });

  proxyReq.write(bodyStr);
  proxyReq.end();
}

function proxyToDeepSeek(res, deepseekPath, bodyStr, meta) {
  const options = {
    hostname: DEEPSEEK_HOST,
    port: 443,
    path: deepseekPath,
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "Authorization": `Bearer ${DEEPSEEK_KEY}`,
      "Content-Length": Buffer.byteLength(bodyStr),
    },
    timeout: 120000,
  };
  const proxyReq = https.request(options, (proxyRes) => {
    res.writeHead(proxyRes.statusCode || 200, {
      "Content-Type": proxyRes.headers["content-type"] || "application/json",
      "Cache-Control": "no-store",
    });
    if (meta) return meter(proxyRes, res, meta);
    proxyRes.pipe(res);
  });
  proxyReq.on("timeout", () => proxyReq.destroy(new Error("deepseek timeout")));
  proxyReq.on("error", (err) => {
    if (meta) record({ ...meta, status: 502, error: err.message });
    json(res, 502, { error: `DeepSeek unavailable: ${err.message}` });
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

  // 推論を伴うPOSTだけ守る。/health や /v1/models は監視のため開けておく。
  if (req.method === "POST" && (skill === "/v1/chat/completions" || skill === "/v1/completions")) {
    if (!authorized(req)) {
      const ip = clientIp(req);
      if (ENFORCE) {
        console.warn(`[block] unpaid direct call from ${ip} ${skill}`);
        record({ rail: "blocked", caller: ip, endpoint: skill, model: ACTIVE_MODEL,
                 provider: LLM_PROVIDER, status: 403 });
        return json(res, 403, { error: "Use a payment rail. See https://llm2api.exbridge.jp/" });
      }
      // 監視のみの段階。遮断せず記録だけ残し、正規経路が塞がれていないか見る。
      console.warn(`[would-block] unpaid direct call from ${ip} ${skill} (LLM2API_ENFORCE=false)`);
    }
  }

  // Health
  if (req.method === "GET" && ["/health", "/healthz"].includes(skill)) {
    const backend = LLM_PROVIDER === "deepseek" ? DEEPSEEK_HOST : `${OLLAMA_HOST}:${OLLAMA_PORT}`;
    return json(res, 200, { ok: true, service: "llm-gateway", provider: LLM_PROVIDER, model: ACTIVE_MODEL, backend });
  }

  // List models
  // 実際に応答するのは ACTIVE_MODEL 1本だが、それがどの枠(ホスト型DeepSeek /
  // セルフホストGemma)なのかを呼び出し側が判別できるようにする。
  if (req.method === "GET" && skill === "/v1/models") {
    return json(res, 200, {
      object: "list",
      data: [{
        id: ACTIVE_MODEL,
        object: "model",
        created: 0,
        owned_by: LLM_PROVIDER,
        tier: LLM_PROVIDER === "deepseek" ? "hosted" : "self-hosted",
        max_input_chars: MAX_INPUT_CHARS,
        max_output_tokens: MAX_OUTPUT_TOKENS,
      }],
    });
  }

  // 使用量サマリ。読み取り専用だがトークンで保護する(利用者の内訳は非公開情報)。
  if (req.method === "GET" && skill === "/usage") {
    if (!USAGE_TOKEN) return json(res, 404, { error: "usage reporting is disabled" });
    const provided = String(req.headers["x-usage-token"] || url.searchParams.get("token") || "");
    if (provided.length !== USAGE_TOKEN.length
        || !crypto.timingSafeEqual(Buffer.from(provided), Buffer.from(USAGE_TOKEN))) {
      return json(res, 401, { error: "invalid usage token" });
    }
    const months = Math.min(12, Math.max(1, Number.parseInt(url.searchParams.get("months") || "3", 10) || 3));
    return json(res, 200, { ok: true, service: "llm2api", months, ...summary(months) });
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
    body.model = ACTIVE_MODEL;
    if (!body.max_tokens || body.max_tokens > MAX_OUTPUT_TOKENS) {
      body.max_tokens = MAX_OUTPUT_TOKENS;
    }

    // 誰がどれだけ使ったかを残す。課金レールは既にあるが内訳が無かった。
    const meta = { ...identify(req), endpoint: "/v1/chat/completions", model: ACTIVE_MODEL, provider: LLM_PROVIDER };

    if (LLM_PROVIDER === "deepseek") {
      // deepseek-v4-flashは思考型: 明示的にthinkingを無効化(低max_tokensでの空応答回避)。
      if (body.thinking === undefined) body.thinking = { type: "disabled" };
      delete body.reasoning_effort;  // ollama/gemma固有フィールドはDeepSeekへ送らない
      return proxyToDeepSeek(res, "/chat/completions", JSON.stringify(body), meta);
    }

    // gemma4は思考型モデル: 既定で思考を無効化しないと、低いmax_tokensで
    // 思考トークンがcontentを食い潰し空応答になる(PayAPI検証で実証)。
    // 呼び出し側が明示的にreasoning_effortを渡した場合のみ尊重する。
    if (body.reasoning_effort === undefined) {
      body.reasoning_effort = "none";
    }

    return proxyToOllama(req, res, "/v1/chat/completions", JSON.stringify(body), meta);
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
    if (LLM_PROVIDER === "deepseek") {
      return json(res, 400, { error: "legacy /v1/completions unsupported on deepseek; use /v1/chat/completions" });
    }
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
