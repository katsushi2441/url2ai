// RapidAPI窓口の共通部品。LLM2API側とbrain側の両方から使う。
//
// server-rapidapi.js は元々1ファイルに LLM2API と 4つのbrain(kcbrain/fxbrain/
// ksbrain/url2brain)が同居していた。LLM2API を別リポジトリへ切り出せるように、
// 責務ごとにファイルを分けたときの共有部分。
import http from "node:http";
import { Buffer } from "node:buffer";

export const MAX_BODY_BYTES = 256 * 1024;

export function json(res, status, data) {
  const body = JSON.stringify(data);
  res.writeHead(status, {
    "Content-Type": "application/json; charset=utf-8",
    "Cache-Control": "no-store",
  });
  res.end(body);
}

export function readBody(req) {
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

/**
 * 上流へJSONを中継して、応答をそのまま返す。
 *
 * Transfer-Encoding は手で書かない。空値ヘッダは不正なHTTPになり、
 * RapidAPI等の厳格なプロキシが502にする(Nodeが自動でchunkedを管理する)。
 */
export function proxyJson(res, { host, port, path, method = "POST", headers = {}, body = "", timeout = 180000, label = "upstream" }) {
  const options = {
    hostname: host,
    port,
    path,
    method,
    headers: {
      "Content-Type": "application/json",
      "Content-Length": method === "GET" ? 0 : Buffer.byteLength(body),
      ...headers,
    },
    timeout,
  };
  const proxyReq = http.request(options, (proxyRes) => {
    res.writeHead(proxyRes.statusCode || 200, {
      "Content-Type": proxyRes.headers["content-type"] || "application/json",
      "Cache-Control": "no-store",
    });
    proxyRes.pipe(res);
  });
  proxyReq.on("timeout", () => proxyReq.destroy(new Error(`${label} timeout`)));
  proxyReq.on("error", (err) => json(res, 502, { error: `${label} unavailable: ${err.message}` }));
  if (method !== "GET") proxyReq.write(body);
  proxyReq.end();
}
