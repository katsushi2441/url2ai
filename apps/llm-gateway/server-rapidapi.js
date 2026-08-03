// RapidAPI 販路の入口(:8018)。
//
// 認証(RapidAPIのproxy secret)と振り分けだけを持つ薄い層。
// 実処理は責務ごとに分かれている:
//   rapidapi/llm2api-routes.js — LLM2API の窓口(LLM2APIを切り出すとき持っていく側)
//   rapidapi/brain-routes.js   — kcbrain/kfxbrain/ksbrain/url2brain の共用窓口(残る側)
//
// RapidAPIの掲載スペック5本がすべて exbridge.ddns.net:8018 を指しているため、
// プロセスとポートは1つのまま保つ。ここを分けると4商品の再掲載が必要になる。
import http from "node:http";

import { json } from "./rapidapi/shared.js";
import * as llm2api from "./rapidapi/llm2api-routes.js";
import * as brains from "./rapidapi/brain-routes.js";

const HOST = process.env.HOST || "0.0.0.0";
const PORT = Number.parseInt(process.env.PORT || "8018", 10);
const RAPIDAPI_SECRET = process.env.RAPIDAPI_PROXY_SECRET || "";

async function handle(req, res) {
  const url = new URL(req.url || "/", `http://${req.headers.host || "localhost"}`);
  const path = url.pathname;

  // 監視用の経路は認証より前に通す
  if (llm2api.handlePublic(req, res, path)) return;

  // RapidAPIのproxy secret を検証(未設定なら素通し)。
  // 以前はここで incoming と expected を console.log していたが、
  // シークレットが journal に平文で残るため削除した(2026-08-04)。
  if (RAPIDAPI_SECRET) {
    const incoming = req.headers["x-rapidapi-proxy-secret"] || "";
    if (incoming !== RAPIDAPI_SECRET) return json(res, 403, { error: "Forbidden" });
  }

  if (await llm2api.handle(req, res, path)) return;
  if (await brains.handle(req, res, path)) return;

  return json(res, 404, { error: "Not found", hint: "POST /v1/chat/completions" });
}

http.createServer((req, res) => {
  handle(req, res).catch((err) => json(res, 500, { error: err.message || String(err) }));
}).listen(PORT, HOST, () => {
  console.log(`RapidAPI gateway → http://${HOST}:${PORT}`);
  console.log(`  LLM2API: 127.0.0.1:${process.env.LLM2API_PORT || 8019} へ中継`);
  console.log(`  ${brains.summary()}`);
  console.log(`  RapidAPI secret: ${RAPIDAPI_SECRET ? "configured" : "NOT SET (open access)"}`);
});
