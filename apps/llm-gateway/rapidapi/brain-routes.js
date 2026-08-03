// Kurage brain 群の RapidAPI 共用窓口。
//
// kcbrain / kfxbrain / ksbrain / url2brain は、それぞれ独立したリポジトリに
// 本体がある。ここにあるのは「RapidAPIという販路の入口」だけで、LLM2API とは
// 無関係。LLM2APIを別リポジトリへ切り出すときは、このファイルが残る側になる。
//
// RapidAPIは有料の販路なので Bankr(x402)/JPYC/ACP と同じく DeepSeek で応答する
// (Providerヘッダ注入)。ローカルGemmaは無料・内部の直叩き(webコンソール/
// kfreqai毎時/kfxai)専用で、有料マーケット販売では使わない。
import fs from "node:fs";

import { json, proxyJson, readBody } from "./shared.js";

const BRAIN_TIMEOUT_MS = Number.parseInt(process.env.BRAIN_TIMEOUT_MS || "180000", 10);

const KCBRAIN_HOST = process.env.KCBRAIN_HOST || "127.0.0.1";
const KCBRAIN_PORT = Number.parseInt(process.env.KCBRAIN_PORT || "18328", 10);
const KCBRAIN_TOKEN = process.env.KCBRAIN_TOKEN || "";
const FXBRAIN_HOST = process.env.FXBRAIN_HOST || "127.0.0.1";
const FXBRAIN_PORT = Number.parseInt(process.env.FXBRAIN_PORT || "18326", 10);
const FXBRAIN_TOKEN = process.env.FXBRAIN_TOKEN || "";
const KSBRAIN_HOST = process.env.KSBRAIN_HOST || "127.0.0.1";
const KSBRAIN_PORT = Number.parseInt(process.env.KSBRAIN_PORT || "18338", 10);
// ksbrainトークン: unit環境に無ければTradingAgents-JPの.envから読む(単一の真実源)
const KSBRAIN_TOKEN = process.env.KSBRAIN_TOKEN || (() => {
  try {
    const text = fs.readFileSync("/home/kojima/work/TradingAgents-JP/.env", "utf8");
    const m = text.match(/^TRADINGAGENTS_JP_LLM_API_KEY=([^\n,]+)/m);
    return m ? m[1].trim() : "";
  } catch { return ""; }
})();

const BRAIN_ROUTES = {
  "/kcbrain/": { host: KCBRAIN_HOST, port: KCBRAIN_PORT, tokenHeader: "X-KCBRAIN-Token", token: KCBRAIN_TOKEN, providerHeader: "X-KCBRAIN-Provider" },
  "/fxbrain/": { host: FXBRAIN_HOST, port: FXBRAIN_PORT, tokenHeader: "X-KFXBRAIN-Token", token: FXBRAIN_TOKEN, providerHeader: "X-KFXBrain-Provider" },
  "/ksbrain/": { host: KSBRAIN_HOST, port: KSBRAIN_PORT, tokenHeader: "X-API-Key", token: KSBRAIN_TOKEN, providerHeader: "X-KSBRAIN-Provider" },
};

// URL2Brain(コンテンツ生成+Kurage自身のSNS/ブログへの投稿)。Bankr/cdp-gatewayと同一挙動:
// LLM生成系は body に provider:"deepseek" を注入、投稿系は confirm_post:true + persona を注入。
const URL2BRAIN_HOST = process.env.URL2BRAIN_HOST || "127.0.0.1";
const URL2BRAIN_PORT = Number.parseInt(process.env.URL2BRAIN_PORT || "18332", 10);
const URL2BRAIN_TOKEN = process.env.URL2BRAIN_TOKEN || "";
const URL2BRAIN_LLM_SUFFIXES = new Set(["generate/announcement", "generate/blog-article", "generate/from-url"]);
const URL2BRAIN_POST_PERSONA = {
  "post/bluesky": "kurage", "post/hatena-bookmark": "", "post/aixsns": "bittensorman",
  "post/bludit": "kurage", "post/hatena-blog": "bittensorman",
};

function toBrain(route, upstreamPath, res, body) {
  const headers = {};
  if (route.token) {
    headers[route.tokenHeader] = route.token;
    headers["Authorization"] = `Bearer ${route.token}`;
  }
  if (route.providerHeader) headers[route.providerHeader] = "deepseek";
  proxyJson(res, {
    host: route.host, port: route.port, path: upstreamPath,
    headers, body, timeout: BRAIN_TIMEOUT_MS, label: "brain",
  });
}

/** 処理したら true、担当外なら false。 */
export async function handle(req, res, path) {
  if (req.method !== "POST") return false;

  // 判断brain: /kcbrain/<skill> などを内部brainの /v1/<skill> へ中継。
  // 例) POST /kcbrain/analyze/technical, /fxbrain/decide/trade, /kcbrain/signal/pair/BTC_USDT
  for (const [prefix, route] of Object.entries(BRAIN_ROUTES)) {
    if (path.startsWith(prefix)) {
      const skill = path.slice(prefix.length).replace(/^\/+/, "");
      if (!skill) {
        json(res, 400, { error: "skill path required", hint: `POST ${prefix}analyze/technical` });
        return true;
      }
      const bodyStr = await readBody(req);
      try { JSON.parse(bodyStr); } catch { json(res, 400, { error: "Invalid JSON" }); return true; }
      toBrain(route, `/v1/${skill}`, res, bodyStr);
      return true;
    }
  }

  // URL2Brain: /url2brain/<skill> を url2brain の /v1/<skill> へ中継(Bankrと同一のbody注入)。
  // 例) POST /url2brain/generate/from-url, /url2brain/post/bluesky
  if (path.startsWith("/url2brain/")) {
    const suffix = path.slice("/url2brain/".length).replace(/^\/+/, "");
    if (!suffix) {
      json(res, 400, { error: "skill path required", hint: "POST /url2brain/generate/from-url" });
      return true;
    }
    const bodyStr = await readBody(req);
    let body;
    try { body = JSON.parse(bodyStr); } catch { json(res, 400, { error: "Invalid JSON" }); return true; }
    if (URL2BRAIN_LLM_SUFFIXES.has(suffix)) body.provider = "deepseek";
    if (Object.prototype.hasOwnProperty.call(URL2BRAIN_POST_PERSONA, suffix)) {
      body.confirm_post = true;
      const persona = URL2BRAIN_POST_PERSONA[suffix];
      if (persona) body.persona = persona;
    }
    const route = { host: URL2BRAIN_HOST, port: URL2BRAIN_PORT, tokenHeader: "X-URL2BRAIN-Token", token: URL2BRAIN_TOKEN };
    toBrain(route, `/v1/${suffix}`, res, JSON.stringify(body));
    return true;
  }

  return false;
}

/** 起動ログ用。トークンの値は出さない。 */
export function summary() {
  const configured = Object.entries({
    kcbrain: KCBRAIN_TOKEN, fxbrain: FXBRAIN_TOKEN, ksbrain: KSBRAIN_TOKEN, url2brain: URL2BRAIN_TOKEN,
  }).filter(([, token]) => token).map(([name]) => name);
  return `brains: ${configured.join(", ") || "(トークン未設定)"}`;
}
