// LLM2API の RapidAPI 窓口。
//
// LLM生成は LLM2API 本体(8019)へ中継する。有料レール(Bankr/JPYC/ACP/RapidAPI)は
// すべてDeepSeekで揃える方針で、本体は既にDeepSeek。ここで直接Ollamaを叩くと
// RapidAPIだけGemmaになり、レール間で品質が食い違う(2026-08-04に発覚)。
// 本体経由にすることで使用量計測(usage.js)にも同じ経路で載る。
//
// LLM2APIを別リポジトリへ切り出すときは、このファイルが持っていく側になる。
import { json, proxyJson, readBody } from "./shared.js";

const LLM2API_HOST = process.env.LLM2API_HOST || "127.0.0.1";
const LLM2API_PORT = Number.parseInt(process.env.LLM2API_PORT || "8019", 10);
// kfreqai judgment API (trade pre-checks)。LLM2APIの商品面に含まれる。
const JUDGMENT_HOST = process.env.JUDGMENT_HOST || "127.0.0.1";
const JUDGMENT_PORT = Number.parseInt(process.env.JUDGMENT_PORT || "18321", 10);

const MAX_INPUT_CHARS = Number.parseInt(process.env.MAX_INPUT_CHARS || "4000", 10);
const MAX_MESSAGES = Number.parseInt(process.env.MAX_MESSAGES || "20", 10);
const MAX_OUTPUT_TOKENS = Number.parseInt(process.env.MAX_OUTPUT_TOKENS || "2048", 10);

function toCore(req, res, path, body = "", method = "POST") {
  return proxyJson(res, {
    host: LLM2API_HOST, port: LLM2API_PORT, path, method, body, label: "LLM2API",
    // 計測で「RapidAPI利用者」として集計されるよう素性を渡す
    headers: { "X-RapidAPI-User": req.headers["x-rapidapi-user"] || "rapidapi" },
  });
}

/** 認証より前に通す経路(監視用)。処理したら true。 */
export function handlePublic(req, res, path) {
  if (req.method === "GET" && ["/health", "/healthz"].includes(path)) {
    // 実際に応答するのは本体(8019)なので、モデル名も本体に合わせる。
    // ここで自前のDEFAULT_MODELを返すと、Gemmaだと誤って案内してしまう。
    toCore(req, res, "/health", "", "GET");
    return true;
  }
  return false;
}

/** 認証後の経路。処理したら true、担当外なら false。 */
export async function handle(req, res, path) {
  if (req.method === "GET" && path === "/v1/models") {
    toCore(req, res, "/v1/models", "", "GET");   // 本体の実枠をそのまま返す
    return true;
  }

  if (req.method === "POST" && path === "/v1/chat/completions") {
    const bodyStr = await readBody(req);
    let body;
    try { body = JSON.parse(bodyStr); } catch { json(res, 400, { error: "Invalid JSON" }); return true; }

    if (!Array.isArray(body.messages) || body.messages.length === 0) {
      json(res, 400, { error: "messages array is required" }); return true;
    }
    if (body.messages.length > MAX_MESSAGES) {
      json(res, 400, { error: `Too many messages (max ${MAX_MESSAGES})` }); return true;
    }
    const totalChars = body.messages.reduce((sum, m) => sum + String(m.content || "").length, 0);
    if (totalChars > MAX_INPUT_CHARS) {
      json(res, 400, { error: `Input too long (${totalChars} chars, max ${MAX_INPUT_CHARS})` });
      return true;
    }
    if (!body.max_tokens || body.max_tokens > MAX_OUTPUT_TOKENS) {
      body.max_tokens = MAX_OUTPUT_TOKENS;
    }
    // モデルの強制と思考型モデルの扱いは本体(8019)側が行う。
    // ここで model を固定すると、本体のプロバイダ切替と食い違う。
    delete body.model;

    toCore(req, res, "/v1/chat/completions", JSON.stringify(body));
    return true;
  }

  // Trade pre-checks (kfreqai judgment API)
  if (req.method === "POST" && (path === "/trade/risk-check" || path === "/trade/size-check")) {
    const bodyStr = await readBody(req);
    try { JSON.parse(bodyStr); } catch { json(res, 400, { error: "Invalid JSON" }); return true; }
    proxyJson(res, {
      host: JUDGMENT_HOST, port: JUDGMENT_PORT, path: `/v1${path}`,
      body: bodyStr, label: "judgment API",
    });
    return true;
  }

  return false;
}
