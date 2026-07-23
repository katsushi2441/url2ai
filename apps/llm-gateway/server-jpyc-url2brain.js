/**
 * server-jpyc-url2brain.js — JPYC x402 payment gateway for Kurage URL2AI Brain
 *
 * server-jpyc-kcbrain.js / server-jpyc-fxbrain.js と同じ検証/決済フロー:
 * JPYC transferWithAuthorization を検証しオンチェーン決済後、
 * url2brain(:18332)の /v1/<skill> へ Bankr/RapidAPIと同一のbody注入をしてプロキシする。
 *
 * Ports:
 *   This server : PORT_JPYC_URL2BRAIN (default 18333; 18300番台の空き最若番号で確保)
 *   Upstream    : url2brain URL2BRAIN_URL (default http://127.0.0.1:18332)
 *
 * 価格: 全9スキル一律 JPYC_AMOUNT_URL2BRAIN (150 JPYC ≒ $1.00)。
 *       直接x402レール・Bankrレール($1.00フラット)と同一。150 JPYC/USD換算はkc/fx-jpycと同じ比率。
 *
 * body注入(server-rapidapi.js と同一の正典ロジック):
 *   生成系(generate/announcement, generate/blog-article, generate/from-url) -> provider:"deepseek"
 *   投稿系(post/*) -> confirm_post:true (+ persona)。決済=投稿許可(2026-07-21ユーザー承認済み)。
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

const HOST           = process.env.HOST            || "0.0.0.0";
// 専用のenv名にする: 共有する.env.jpycのPORT_JPYC(8020=llm2api用)に上書きされないため
const PORT           = Number.parseInt(process.env.PORT_JPYC_URL2BRAIN || "18333", 10);
const TLS_CERT       = process.env.TLS_CERT || "/etc/letsencrypt/live/exbridge.ddns.net/fullchain.pem";
const TLS_KEY        = process.env.TLS_KEY  || "/etc/letsencrypt/live/exbridge.ddns.net/privkey.pem";
const URL2BRAIN_URL  = process.env.URL2BRAIN_URL || "http://127.0.0.1:18332";
const URL2BRAIN_TOKEN = (process.env.URL2BRAIN_API_TOKEN || process.env.URL2BRAIN_TOKEN || "").trim();
const MAX_BODY_BYTES = 256 * 1024; // url2brainはURL本文を含むため kc/fx より大きめ

const JPYC_PAY_TO    = (process.env.JPYC_PAY_TO             || "").trim();
const JPYC_RELAY_KEY = (process.env.JPYC_RELAY_PRIVATE_KEY  || "").trim();
const JPYC_AMOUNT    = process.env.JPYC_AMOUNT_URL2BRAIN || "150000000000000000000";  // 150 JPYC ≒ $1.00
const JPYC_TOKEN     = process.env.JPYC_TOKEN  || "0x431D5dfF03120AFA4bDf332c61A6e1766eF37BDB";
const JPYC_RPC       = process.env.JPYC_RPC    || "https://polygon.drpc.org";

if (!JPYC_PAY_TO || !JPYC_RELAY_KEY) {
  console.error("JPYC_PAY_TO and JPYC_RELAY_PRIVATE_KEY are required");
  process.exit(1);
}
if (!URL2BRAIN_TOKEN) {
  console.error("URL2BRAIN_API_TOKEN (or URL2BRAIN_TOKEN) is required");
  process.exit(1);
}

// 公開スキル(/url2brain/<suffix>) -> url2brain の /v1/<suffix>。全9スキル一律価格。
const SKILLS = new Set([
  "analyze/url",
  "generate/announcement",
  "generate/blog-article",
  "generate/from-url",
  "post/bluesky",
  "post/hatena-bookmark",
  "post/aixsns",
  "post/bludit",
  "post/hatena-blog",
]);

// server-rapidapi.js と同一の注入テーブル。
const LLM_SUFFIXES = new Set(["generate/announcement", "generate/blog-article", "generate/from-url"]);
const POST_PERSONA = {
  "post/bluesky": "kurage",
  "post/hatena-bookmark": "",
  "post/aixsns": "bittensorman",
  "post/bludit": "kurage",
  "post/hatena-blog": "bittensorman",
};

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

console.log(`URL2AI Brain JPYC gateway — payTo=${JPYC_PAY_TO} amount=${JPYC_AMOUNT}`);

const pendingNonces = new Set();

function json(res, status, data) {
  const body = JSON.stringify(data, null, 2);
  res.writeHead(status, { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-store" });
  res.end(body);
}

function payment402Body() {
  return {
    x402Version: 2,
    accepts: [{
      scheme:  "exact",
      network: "eip155:137",
      asset:   JPYC_TOKEN,
      amount:  JPYC_AMOUNT,
      payTo:   JPYC_PAY_TO,
      extra:   { name: "JPY Coin", version: "1" },
    }],
    error: "Payment required",
  };
}

async function verify402(auth, reqs) {
  if (reqs.network !== "eip155:137")     throw new Error("wrong network");
  if (reqs.asset.toLowerCase() !== JPYC_TOKEN.toLowerCase()) throw new Error("wrong token");
  if (reqs.payTo.toLowerCase() !== JPYC_PAY_TO.toLowerCase()) throw new Error("wrong payTo");
  if (BigInt(reqs.amount) < BigInt(JPYC_AMOUNT)) throw new Error("insufficient amount");

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

// server-rapidapi.js と同一: 生成系はprovider:deepseek、投稿系はconfirm_post(+persona)を注入。
function injectBody(suffix, rawBody) {
  let body = {};
  const text = rawBody.toString("utf8").trim();
  if (text) {
    try { body = JSON.parse(text); } catch { throw new Error("Invalid JSON"); }
  }
  if (LLM_SUFFIXES.has(suffix)) body.provider = "deepseek";
  if (Object.prototype.hasOwnProperty.call(POST_PERSONA, suffix)) {
    body.confirm_post = true;
    const persona = POST_PERSONA[suffix];
    if (persona) body.persona = persona;
  }
  return Buffer.from(JSON.stringify(body));
}

function proxyToUrl2brain(suffix, rawBody) {
  return new Promise((resolve, reject) => {
    const url = new URL(`${URL2BRAIN_URL}/v1/${suffix}`);
    const options = {
      hostname: url.hostname,
      port:     url.port,
      path:     url.pathname,
      method:   "POST",
      timeout:  300 * 1000, // 記事生成・実投稿はfrom-urlで数十秒かかることがある
      headers: {
        "Content-Type": "application/json",
        "Content-Length": Buffer.byteLength(rawBody),
        "X-URL2BRAIN-Token": URL2BRAIN_TOKEN,
      },
    };
    const proxy = http.request(options, (upRes) => {
      const chunks = [];
      upRes.on("data", (c) => chunks.push(c));
      upRes.on("end", () => resolve({ status: upRes.statusCode, headers: upRes.headers, body: Buffer.concat(chunks) }));
      upRes.on("error", reject);
    });
    proxy.on("timeout", () => proxy.destroy(new Error("url2brain upstream timeout")));
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

  let suffix = null;
  if (url.pathname.startsWith("/url2brain/")) {
    suffix = url.pathname.slice("/url2brain/".length).replace(/^\/+/, "");
  }
  if (req.method !== "POST" || !suffix || !SKILLS.has(suffix)) {
    return json(res, 404, {
      error: "Unknown endpoint",
      skills: [...SKILLS].map((s) => `POST /url2brain/${s}`),
    });
  }

  const raw = req.headers["x-payment"] || req.headers["payment-signature"];
  if (!raw) return json(res, 402, payment402Body());

  let auth, reqs;
  try {
    const parsed = JSON.parse(Buffer.from(raw, "base64").toString("utf8"));
    auth = parsed.paymentPayload.authorization;
    reqs = parsed.paymentRequirements;
    await verify402(auth, reqs);
  } catch (err) {
    return json(res, 402, { ...payment402Body(), error: err.message });
  }

  let rawBody, injected;
  try {
    rawBody = await readRawBody(req);
    injected = injectBody(suffix, rawBody);
  } catch (err) {
    return json(res, 400, { error: err.message });
  }

  let settlement;
  try {
    settlement = await settle402(auth);
  } catch (err) {
    return json(res, 402, { ...payment402Body(), error: `settle failed: ${err.message}` });
  }

  let result;
  try {
    result = await proxyToUrl2brain(suffix, injected);
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
    const url = new URL(`${URL2BRAIN_URL}/health`);
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
    .listen(PORT, HOST, () => console.log(`URL2AI Brain JPYC gateway → https://${HOST}:${PORT} → ${URL2BRAIN_URL}`));
} else {
  http.createServer(requestHandler)
    .listen(PORT, HOST, () => console.log(`URL2AI Brain JPYC gateway → http://${HOST}:${PORT} → ${URL2BRAIN_URL}`));
}
