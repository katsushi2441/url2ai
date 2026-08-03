// 無課金での直叩きを弾く判定。
//
// 8019 は Bankr から到達するため外部公開が必要で、そのためホスト:ポートを
// 知っていれば誰でも無課金で推論できていた(2026-08-04 実測)。
// kcbrain / kfxbrain / ksbrain / url2brain は Bankr の暗号化env に *_TOKEN を持ち、
// ハンドラがヘッダで付けて呼んでいる。LLM2API だけこれが無かったので同じ型に揃える。
//
// 判定を server.js から切り出しているのは、外部IPからのアクセスを
// ローカルのテストで再現できないため(ループバックは常に許可される)。
import crypto from "node:crypto";

// 自前のラッパー(JPYC:8020 / RapidAPI:8018)は 127.0.0.1 から来るので常に許可。
export const LOOPBACK = ["127.0.0.1", "::1", "::ffff:127.0.0.1"];

export function parseAllowedIps(raw) {
  return new Set(
    LOOPBACK.concat(String(raw || "").split(",").map((s) => s.trim()).filter(Boolean))
  );
}

export function tokenOk(supplied, expected) {
  if (!expected || !supplied) return false;
  const a = Buffer.from(String(supplied));
  const b = Buffer.from(String(expected));
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

export function suppliedToken(headers) {
  const direct = headers["x-llm2api-token"];
  if (direct) return String(direct);
  const auth = String(headers["authorization"] || "");
  return auth.toLowerCase().startsWith("bearer ") ? auth.slice(7).trim() : "";
}

/**
 * 課金レールを経由したリクエストか。false は無課金の直叩きの疑い。
 * @param {{headers: object, ip: string}} req
 * @param {{token: string, allowedIps: Set<string>}} config
 */
export function isAuthorized(req, config) {
  const headers = req.headers || {};
  if (tokenOk(suppliedToken(headers), config.token)) return true;
  if (config.allowedIps.has(req.ip)) return true;
  // x402の支払いヘッダが付いていれば、前段で決済済みとみなす
  if (headers["x-payment"] || headers["payment-signature"]) return true;
  // トークン未設定なら、設定前に全遮断しないよう素通しにする
  return !config.token;
}
