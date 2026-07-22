/**
 * server-jpyc-fxbrain.js — JPYC x402 payment gateway for Kurage FX Brain
 *
 * server-jpyc.js(llm2api用)と同じ検証/決済フロー:
 * JPYC transferWithAuthorization を検証しオンチェーン決済後、
 * kfxbrain(:18326)へトークンを注入してプロキシする。
 *
 * Ports:
 *   This server : PORT_JPYC_FXBRAIN (default 18327; 8018は既存サービスが使用中)
 *   Upstream    : kfxbrain FXBRAIN_URL (default http://127.0.0.1:18326)
 *
 * 価格: 全判断スキル均一 JPYC_AMOUNT (7.5 JPYC ≒ $0.05)
 * (tradingagents/runフルグラフは2026-07-22に製品から撤去)
 */

import http from "node:http";
import https from "node:https";
import fs from "node:fs";
import { Buffer } from "node:buffer";
import {
  createPublicClient,
  createWalletClient,
  http as viemHttp,
  verifyTypedData,
} from "viem";
import { polygon } from "viem/chains";
import { privateKeyToAccount } from "viem/accounts";

const HOST          = process.env.HOST           || "0.0.0.0";
// 専用のenv名にする: 共有する.env.jpycのPORT_JPYC(8020=llm2api用)に上書きされないため
const PORT          = Number.parseInt(process.env.PORT_JPYC_FXBRAIN || "18327", 10);
const TLS_CERT      = process.env.TLS_CERT || "/etc/letsencrypt/live/exbridge.ddns.net/fullchain.pem";
const TLS_KEY       = process.env.TLS_KEY  || "/etc/letsencrypt/live/exbridge.ddns.net/privkey.pem";
const FXBRAIN_URL   = process.env.FXBRAIN_URL || "http://127.0.0.1:18326";
const FXBRAIN_TOKEN = (process.env.KFXBRAIN_API_TOKEN || process.env.FXBRAIN_TOKEN || "").trim();
const MAX_BODY_BYTES = 64 * 1024;

const JPYC_PAY_TO    = (process.env.JPYC_PAY_TO             || "").trim();
const JPYC_RELAY_KEY = (process.env.JPYC_RELAY_PRIVATE_KEY  || "").trim();
const JPYC_AMOUNT    = process.env.JPYC_AMOUNT || "7500000000000000000"; // 7.5 JPYC
const JPYC_TOKEN     = process.env.JPYC_TOKEN  || "0x431D5dfF03120AFA4bDf332c61A6e1766eF37BDB";
const JPYC_RPC       = process.env.JPYC_RPC    || "https://polygon.drpc.org";

if (!JPYC_PAY_TO || !JPYC_RELAY_KEY) {
  console.error("JPYC_PAY_TO and JPYC_RELAY_PRIVATE_KEY are required");
  process.exit(1);
}
if (!FXBRAIN_TOKEN) {
  console.error("KFXBRAIN_API_TOKEN (or FXBRAIN_TOKEN) is required");
  process.exit(1);
}

// 公開パス(/fxbrain/...) -> kfxbrain upstream パス
const SKILLS = {
  "/fxbrain/analyze/technical": "/v1/analyze/technical",
  "/fxbrain/analyze/macro": "/v1/analyze/macro",
  "/fxbrain/analyze/sentiment": "/v1/analyze/sentiment",
  "/fxbrain/analyze/full": "/v1/analyze/full",
  "/fxbrain/debate/bull-bear": "/v1/debate/bull-bear",
  "/fxbrain/decide/trade": "/v1/decide/trade",
  "/fxbrain/assess/risk": "/v1/assess/risk",
  "/fxbrain/decide/portfolio": "/v1/decide/portfolio",
  "/fxbrain/review/trade": "/v1/review/trade",
  "/fxbrain/fingpt/sentiment": "/v1/vendor/fingpt/sentiment",
  "/fxbrain/fingpt/headline": "/v1/vendor/fingpt/headline",
  "/fxbrain/fingpt/forecast": "/v1/vendor/fingpt/forecast",
  "/fxbrain/fingpt/report": "/v1/vendor/fingpt/report",
  "/fxbrain/hedge/news-sentiment": "/v1/vendor/ai-hedge-fund/news-sentiment",
  "/fxbrain/hedge/portfolio": "/v1/vendor/ai-hedge-fund/portfolio",
  "/fxbrain/finrobot/forecast": "/v1/vendor/finrobot/forecast",
  "/fxbrain/finrobot/report/income_stmt": "/v1/vendor/finrobot/report/income_stmt",
  "/fxbrain/finrobot/report/balance_sheet": "/v1/vendor/finrobot/report/balance_sheet",
  "/fxbrain/finrobot/report/cash_flow": "/v1/vendor/finrobot/report/cash_flow",
  "/fxbrain/finrobot/report/segment_stmt": "/v1/vendor/finrobot/report/segment_stmt",
  "/fxbrain/finrobot/report/risk_assessment": "/v1/vendor/finrobot/report/risk_assessment",
  "/fxbrain/finrobot/report/competitors": "/v1/vendor/finrobot/report/competitors",
  "/fxbrain/finrobot/report/business_highlights": "/v1/vendor/finrobot/report/business_highlights",
  "/fxbrain/finrobot/report/company_description": "/v1/vendor/finrobot/report/company_description",
  "/fxbrain/finmem/decide": "/v1/vendor/finmem/decide",
  "/fxbrain/finmem/reflect": "/v1/vendor/finmem/reflect",
  // tradingagents/run(フルグラフ)は製品から撤去(2026-07-22)。全レール共通で提供しない。
  "/fxbrain/market/opportunity-ranking": "/v1/market/opportunity-ranking",
  "/fxbrain/market/flow-ranking": "/v1/market/flow-ranking",
  "/fxbrain/market/anomaly": "/v1/market/anomaly",
  "/fxbrain/market/margin-risk": "/v1/market/margin-risk",
};

function amountFor(_pathname) {
  // グラフ撤去後は全スキル均一(JPYC_AMOUNT)。
  return JPYC_AMOUNT;
}

const JPYC_ABI = [
  {
    name: "authorizationState",
    type: "function",
    stateMutability: "view",
    inputs: [
      { name: "authorizer", type: "address" },
      { name: "nonce",      type: "bytes32"  },
    ],
    outputs: [{ type: "uint8" }],
  },
  {
    name: "transferWithAuthorization",
    type: "function",
    stateMutability: "nonpayable",
    inputs: [
      { name: "from",        type: "address" },
      { name: "to",          type: "address" },
      { name: "value",       type: "uint256" },
      { name: "validAfter",  type: "uint256" },
      { name: "validBefore", type: "uint256" },
      { name: "nonce",       type: "bytes32" },
      { name: "v",           type: "uint8"   },
      { name: "r",           type: "bytes32" },
      { name: "s",           type: "bytes32" },
    ],
    outputs: [],
  },
];

const EIP712_DOMAIN = {
  name: "JPY Coin",
  version: "1",
  chainId: 137,
  verifyingContract: JPYC_TOKEN,
};

const EIP712_TYPES = {
  TransferWithAuthorization: [
    { name: "from",        type: "address" },
    { name: "to",          type: "address" },
    { name: "value",       type: "uint256" },
    { name: "validAfter",  type: "uint256" },
    { name: "validBefore", type: "uint256" },
    { name: "nonce",       type: "bytes32" },
  ],
};

const publicClient = createPublicClient({ chain: polygon, transport: viemHttp(JPYC_RPC) });
const account      = privateKeyToAccount(JPYC_RELAY_KEY);
const walletClient = createWalletClient({ account, chain: polygon, transport: viemHttp(JPYC_RPC) });

console.log(`FX Brain JPYC gateway — payTo=${JPYC_PAY_TO} amount=${JPYC_AMOUNT}`);

const pendingNonces = new Set();

function json(res, status, data) {
  const body = JSON.stringify(data, null, 2);
  res.writeHead(status, { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-store" });
  res.end(body);
}

function payment402Body(pathname) {
  return {
    x402Version: 2,
    accepts: [{
      scheme:  "exact",
      network: "eip155:137",
      asset:   JPYC_TOKEN,
      amount:  amountFor(pathname),
      payTo:   JPYC_PAY_TO,
      extra:   { name: "JPY Coin", version: "1" },
    }],
    error: "Payment required",
  };
}

async function verify402(auth, reqs, pathname) {
  if (reqs.network !== "eip155:137")     throw new Error("wrong network");
  if (reqs.asset.toLowerCase() !== JPYC_TOKEN.toLowerCase()) throw new Error("wrong token");
  if (reqs.payTo.toLowerCase() !== JPYC_PAY_TO.toLowerCase()) throw new Error("wrong payTo");
  if (BigInt(reqs.amount) < BigInt(amountFor(pathname))) throw new Error("insufficient amount");

  const now = Math.floor(Date.now() / 1000);
  if (Number(auth.validBefore) <= now) throw new Error("authorization expired");
  if (pendingNonces.has(auth.nonce))   throw new Error("nonce in-flight");

  const state = await publicClient.readContract({
    address: JPYC_TOKEN, abi: JPYC_ABI, functionName: "authorizationState",
    args: [auth.from, auth.nonce],
  });
  if (state !== 0) throw new Error("nonce already used");

  const sig = `${auth.r}${auth.s.slice(2)}${Number(auth.v).toString(16).padStart(2, "0")}`;
  const valid = await verifyTypedData({
    address: auth.from, domain: EIP712_DOMAIN, types: EIP712_TYPES,
    primaryType: "TransferWithAuthorization",
    message: {
      from: auth.from, to: auth.to,
      value: BigInt(auth.value),
      validAfter: BigInt(auth.validAfter), validBefore: BigInt(auth.validBefore),
      nonce: auth.nonce,
    },
    signature: sig,
  });
  if (!valid) throw new Error("invalid signature");
}

async function settle402(auth) {
  pendingNonces.add(auth.nonce);
  try {
    const hash = await walletClient.writeContract({
      address: JPYC_TOKEN, abi: JPYC_ABI, functionName: "transferWithAuthorization",
      args: [
        auth.from, auth.to, BigInt(auth.value),
        BigInt(auth.validAfter), BigInt(auth.validBefore),
        auth.nonce, Number(auth.v), auth.r, auth.s,
      ],
    });
    const receipt = await publicClient.waitForTransactionReceipt({ hash, confirmations: 1 });
    return { hash, status: receipt.status };
  } finally {
    pendingNonces.delete(auth.nonce);
  }
}

function proxyToFxbrain(upstreamPath, rawBody) {
  return new Promise((resolve, reject) => {
    const url = new URL(`${FXBRAIN_URL}${upstreamPath}`);
    const options = {
      hostname: url.hostname,
      port:     url.port,
      path:     url.pathname,
      method:   "POST",
      timeout:  5 * 60 * 1000, // 判断スキルのみ(グラフ撤去済み)。多ペアrankingの余裕で5分
      headers: {
        "Content-Type": "application/json",
        "Content-Length": Buffer.byteLength(rawBody),
        "X-KFXBRAIN-Token": FXBRAIN_TOKEN,
        // 課金コール(JPYC)はDeepSeek。kfxbrain既定のローカルGemmaを上書きする。
        "X-KFXBrain-Provider": "deepseek",
      },
    };
    const proxy = http.request(options, (upRes) => {
      const chunks = [];
      upRes.on("data", (c) => chunks.push(c));
      upRes.on("end", () => resolve({ status: upRes.statusCode, headers: upRes.headers, body: Buffer.concat(chunks) }));
      upRes.on("error", reject);
    });
    proxy.on("timeout", () => proxy.destroy(new Error("fxbrain upstream timeout")));
    proxy.on("error", reject);
    proxy.write(rawBody);
    proxy.end();
  });
}

function readRawBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on("data", (chunk) => {
      size += chunk.length;
      if (size > MAX_BODY_BYTES) { reject(new Error("Request body too large")); req.destroy(); return; }
      chunks.push(chunk);
    });
    req.on("end", () => resolve(Buffer.concat(chunks)));
    req.on("error", reject);
  });
}

async function handle(req, res) {
  const url = new URL(req.url || "/", `http://${req.headers.host || "localhost"}`);

  if (req.method === "GET" && ["/health", "/healthz"].includes(url.pathname)) {
    const result = await proxyHealth();
    return json(res, result.status, result.body);
  }

  let upstreamPath = SKILLS[url.pathname];
  if (!upstreamPath && /^\/fxbrain\/signal\/pair\/[A-Z]{3}_[A-Z]{3}$/.test(url.pathname)) {
    upstreamPath = url.pathname.replace("/fxbrain", "/v1");
  }
  if (req.method !== "POST" || !upstreamPath) {
    return json(res, 404, {
      error: "Unknown endpoint",
      skills: Object.keys(SKILLS).map((s) => `POST ${s}`),
    });
  }

  const raw = req.headers["x-payment"] || req.headers["payment-signature"];
  if (!raw) return json(res, 402, payment402Body(url.pathname));

  let auth, reqs;
  try {
    const parsed = JSON.parse(Buffer.from(raw, "base64").toString("utf8"));
    auth = parsed.paymentPayload.authorization;
    reqs = parsed.paymentRequirements;
    await verify402(auth, reqs, url.pathname);
  } catch (err) {
    return json(res, 402, { ...payment402Body(url.pathname), error: err.message });
  }

  let settlement;
  try {
    settlement = await settle402(auth);
  } catch (err) {
    return json(res, 402, { ...payment402Body(url.pathname), error: `settle failed: ${err.message}` });
  }

  const rawBody = await readRawBody(req);
  let result;
  try {
    result = await proxyToFxbrain(upstreamPath, rawBody);
  } catch (err) {
    return json(res, 502, { ok: false, error: `upstream error: ${err.message}` });
  }

  res.writeHead(result.status, {
    "Content-Type": result.headers["content-type"] || "application/json",
    "X-PAYMENT-RESPONSE": Buffer.from(JSON.stringify({ success: true, txHash: settlement.hash })).toString("base64"),
  });
  res.end(result.body);
}

function proxyHealth() {
  return new Promise((resolve) => {
    const url = new URL(`${FXBRAIN_URL}/health`);
    http.get({ hostname: url.hostname, port: url.port, path: "/health", timeout: 5000 }, (upRes) => {
      const chunks = [];
      upRes.on("data", (c) => chunks.push(c));
      upRes.on("end", () => {
        try {
          resolve({ status: upRes.statusCode || 502, body: JSON.parse(Buffer.concat(chunks).toString("utf8")) });
        } catch {
          resolve({ status: 502, body: { ok: false, error: "invalid upstream health" } });
        }
      });
    }).on("error", (err) => resolve({ status: 502, body: { ok: false, error: err.message } }))
      .on("timeout", function () { this.destroy(); resolve({ status: 502, body: { ok: false, error: "health timeout" } }); });
  });
}

const requestHandler = (req, res) => {
  handle(req, res).catch((err) => json(res, 500, { ok: false, error: err.message || String(err) }));
};

let certOk = false;
try { fs.accessSync(TLS_CERT); fs.accessSync(TLS_KEY); certOk = true; } catch {}

if (certOk) {
  https.createServer({ cert: fs.readFileSync(TLS_CERT), key: fs.readFileSync(TLS_KEY) }, requestHandler)
    .listen(PORT, HOST, () => console.log(`FX Brain JPYC gateway → https://${HOST}:${PORT} → ${FXBRAIN_URL}`));
} else {
  http.createServer(requestHandler)
    .listen(PORT, HOST, () => console.log(`FX Brain JPYC gateway → http://${HOST}:${PORT} → ${FXBRAIN_URL}`));
}
