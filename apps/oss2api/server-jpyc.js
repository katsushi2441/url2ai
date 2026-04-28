/**
 * server-jpyc.js — JPYC x402 payment gateway for background-removal
 *
 * Sits in front of server.js (Bankr-facing, untouched).
 * Verifies JPYC transferWithAuthorization, settles on-chain,
 * then proxies the request to the upstream server.
 *
 * Ports:
 *   This server : PORT_JPYC (default 8017)
 *   Upstream    : PORT_UPSTREAM (default 8015, where server.js runs)
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
const PORT          = Number.parseInt(process.env.PORT_JPYC     || "8017", 10);
const TLS_CERT      = process.env.TLS_CERT || "/etc/letsencrypt/live/exbridge.ddns.net/fullchain.pem";
const TLS_KEY       = process.env.TLS_KEY  || "/etc/letsencrypt/live/exbridge.ddns.net/privkey.pem";
const UPSTREAM_PORT = Number.parseInt(process.env.PORT_UPSTREAM || "8015", 10);
const UPSTREAM_HOST = process.env.UPSTREAM_HOST  || "127.0.0.1";
const MAX_BODY_BYTES = Number.parseInt(process.env.MAX_BODY_BYTES || `${12 * 1024 * 1024}`, 10);

const JPYC_PAY_TO    = (process.env.JPYC_PAY_TO             || "").trim();
const JPYC_RELAY_KEY = (process.env.JPYC_RELAY_PRIVATE_KEY  || "").trim();
const JPYC_AMOUNT    = process.env.JPYC_AMOUNT || "1500000000000000000"; // ~$0.01 (1.5 JPYC)
const JPYC_TOKEN     = process.env.JPYC_TOKEN  || "0x431D5dfF03120AFA4bDf332c61A6e1766eF37BDB";
const JPYC_RPC       = process.env.JPYC_RPC    || "https://polygon.drpc.org";

if (!JPYC_PAY_TO || !JPYC_RELAY_KEY) {
  console.error("JPYC_PAY_TO and JPYC_RELAY_PRIVATE_KEY are required");
  process.exit(1);
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

console.log(`JPYC gateway — payTo=${JPYC_PAY_TO} amount=${JPYC_AMOUNT}`);

const pendingNonces = new Set();

function json(res, status, data) {
  const body = JSON.stringify(data, null, 2);
  res.writeHead(status, {
    "Content-Type": "application/json; charset=utf-8",
    "Cache-Control": "no-store",
  });
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
  if (reqs.network !== "eip155:137")
    throw new Error("wrong network");
  if (reqs.asset.toLowerCase() !== JPYC_TOKEN.toLowerCase())
    throw new Error("wrong token");
  if (reqs.payTo.toLowerCase() !== JPYC_PAY_TO.toLowerCase())
    throw new Error("wrong payTo");
  if (BigInt(reqs.amount) < BigInt(JPYC_AMOUNT))
    throw new Error("insufficient amount");

  const now = Math.floor(Date.now() / 1000);
  if (Number(auth.validBefore) <= now)
    throw new Error("authorization expired");

  if (pendingNonces.has(auth.nonce))
    throw new Error("nonce in-flight");

  const state = await publicClient.readContract({
    address:      JPYC_TOKEN,
    abi:          JPYC_ABI,
    functionName: "authorizationState",
    args:         [auth.from, auth.nonce],
  });
  if (state !== 0)
    throw new Error("nonce already used");

  const sig = `${auth.r}${auth.s.slice(2)}${Number(auth.v).toString(16).padStart(2, "0")}`;
  const valid = await verifyTypedData({
    address:     auth.from,
    domain:      EIP712_DOMAIN,
    types:       EIP712_TYPES,
    primaryType: "TransferWithAuthorization",
    message: {
      from:        auth.from,
      to:          auth.to,
      value:       BigInt(auth.value),
      validAfter:  BigInt(auth.validAfter),
      validBefore: BigInt(auth.validBefore),
      nonce:       auth.nonce,
    },
    signature: sig,
  });
  if (!valid)
    throw new Error("invalid signature");
}

async function settle402(auth) {
  pendingNonces.add(auth.nonce);
  try {
    const hash = await walletClient.writeContract({
      address:      JPYC_TOKEN,
      abi:          JPYC_ABI,
      functionName: "transferWithAuthorization",
      args: [
        auth.from,
        auth.to,
        BigInt(auth.value),
        BigInt(auth.validAfter),
        BigInt(auth.validBefore),
        auth.nonce,
        Number(auth.v),
        auth.r,
        auth.s,
      ],
    });
    const receipt = await publicClient.waitForTransactionReceipt({ hash, confirmations: 1 });
    return { hash, status: receipt.status };
  } finally {
    pendingNonces.delete(auth.nonce);
  }
}

function proxyToUpstream(req, rawBody, extraHeaders) {
  return new Promise((resolve, reject) => {
    const options = {
      hostname: UPSTREAM_HOST,
      port:     UPSTREAM_PORT,
      path:     req.url,
      method:   req.method,
      headers: {
        ...req.headers,
        host: `${UPSTREAM_HOST}:${UPSTREAM_PORT}`,
        "content-length": Buffer.byteLength(rawBody),
        ...extraHeaders,
      },
    };
    const proxy = http.request(options, (upRes) => {
      const chunks = [];
      upRes.on("data", (c) => chunks.push(c));
      upRes.on("end", () => resolve({ status: upRes.statusCode, headers: upRes.headers, body: Buffer.concat(chunks) }));
      upRes.on("error", reject);
    });
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
      if (size > MAX_BODY_BYTES) {
        reject(new Error("Request body too large"));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on("end", () => resolve(Buffer.concat(chunks)));
    req.on("error", reject);
  });
}

async function handle(req, res) {
  const url = new URL(req.url || "/", `http://${req.headers.host || "localhost"}`);

  // Health checks — proxy directly, no payment needed
  if (req.method === "GET" && ["/health", "/healthz"].includes(url.pathname)) {
    const result = await proxyToUpstream(req, Buffer.alloc(0), {});
    res.writeHead(result.status, result.headers);
    return res.end(result.body);
  }

  // Non-POST or paths that don't require payment — proxy directly
  const requiresPayment = req.method === "POST" && (
    url.pathname === "/run" ||
    url.pathname.startsWith("/oss2api/")
  );
  if (!requiresPayment) {
    const rawBody = await readRawBody(req);
    const result = await proxyToUpstream(req, rawBody, {});
    res.writeHead(result.status, result.headers);
    return res.end(result.body);
  }

  // /run and /oss2api/* — verify JPYC payment first
  const raw = req.headers["x-payment"] || req.headers["payment-signature"];
  if (!raw) {
    return json(res, 402, payment402Body());
  }

  let auth, reqs;
  try {
    const parsed = JSON.parse(Buffer.from(raw, "base64").toString("utf8"));
    auth = parsed.paymentPayload.authorization;
    reqs = parsed.paymentRequirements;
    await verify402(auth, reqs);
  } catch (err) {
    return json(res, 402, { ...payment402Body(), error: err.message });
  }

  let settlement;
  try {
    settlement = await settle402(auth);
  } catch (err) {
    return json(res, 402, { ...payment402Body(), error: `settle failed: ${err.message}` });
  }

  const rawBody = await readRawBody(req);
  let result;
  try {
    result = await proxyToUpstream(req, rawBody, {});
  } catch (err) {
    return json(res, 502, { ok: false, error: `upstream error: ${err.message}` });
  }

  res.writeHead(result.status, {
    ...result.headers,
    "X-PAYMENT-RESPONSE": Buffer.from(JSON.stringify({ success: true, txHash: settlement.hash })).toString("base64"),
  });
  res.end(result.body);
}

const requestHandler = (req, res) => {
  handle(req, res).catch((err) => {
    json(res, 500, { ok: false, error: err.message || String(err) });
  });
};

let certOk = false;
try {
  fs.accessSync(TLS_CERT);
  fs.accessSync(TLS_KEY);
  certOk = true;
} catch {}

if (certOk) {
  const tlsOptions = {
    cert: fs.readFileSync(TLS_CERT),
    key:  fs.readFileSync(TLS_KEY),
  };
  https.createServer(tlsOptions, requestHandler).listen(PORT, HOST, () => {
    console.log(`JPYC payment gateway listening on https://${HOST}:${PORT} → upstream http://${UPSTREAM_HOST}:${UPSTREAM_PORT}`);
  });
} else {
  http.createServer(requestHandler).listen(PORT, HOST, () => {
    console.log(`JPYC payment gateway listening on http://${HOST}:${PORT} → upstream http://${UPSTREAM_HOST}:${UPSTREAM_PORT}`);
  });
}
