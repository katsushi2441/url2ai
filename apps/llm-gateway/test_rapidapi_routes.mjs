// RapidAPI窓口の振り分けテスト。
//   node --test test_rapidapi_routes.mjs
//
// server-rapidapi.js を LLM2API側 と brain側 に分割した際、経路が1本でも
// 変わるとRapidAPIの掲載商品が壊れる(掲載スペック5本がこの1ポートを指す)。
// 上流を偽サーバーに差し替えて、どのパスがどこへ行くかを固定する。
import test from "node:test";
import assert from "node:assert/strict";
import http from "node:http";
import net from "node:net";
import { spawn } from "node:child_process";
import path from "node:path";

const SECRET = "rapid-secret-test";
const HERE = path.dirname(new URL(import.meta.url).pathname);

function freePort() {
  return new Promise((resolve) => {
    const s = net.createServer();
    s.listen(0, "127.0.0.1", () => { const p = s.address().port; s.close(() => resolve(p)); });
  });
}

/** 受け取ったリクエストを記録して固定JSONを返す偽の上流。 */
function fakeUpstream(name, seen) {
  return new Promise((resolve) => {
    const srv = http.createServer((req, res) => {
      let body = "";
      req.on("data", (c) => { body += c; });
      req.on("end", () => {
        seen.push({ name, method: req.method, path: req.url, headers: req.headers, body });
        res.writeHead(200, { "Content-Type": "application/json" });
        res.end(JSON.stringify({ from: name, path: req.url }));
      });
    });
    srv.listen(0, "127.0.0.1", () => resolve({ srv, port: srv.address().port }));
  });
}

async function boot() {
  const seen = [];
  const core = await fakeUpstream("llm2api", seen);
  const judgment = await fakeUpstream("judgment", seen);
  const kc = await fakeUpstream("kcbrain", seen);
  const u2b = await fakeUpstream("url2brain", seen);
  const port = await freePort();
  const child = spawn(process.execPath, ["server-rapidapi.js"], {
    cwd: HERE,
    env: {
      ...process.env,
      PORT: String(port),
      RAPIDAPI_PROXY_SECRET: SECRET,
      LLM2API_HOST: "127.0.0.1", LLM2API_PORT: String(core.port),
      JUDGMENT_HOST: "127.0.0.1", JUDGMENT_PORT: String(judgment.port),
      KCBRAIN_HOST: "127.0.0.1", KCBRAIN_PORT: String(kc.port), KCBRAIN_TOKEN: "kc-token",
      URL2BRAIN_HOST: "127.0.0.1", URL2BRAIN_PORT: String(u2b.port), URL2BRAIN_TOKEN: "u2b-token",
    },
    stdio: ["ignore", "pipe", "pipe"],
  });
  await new Promise((r) => setTimeout(r, 900));
  const stop = () => { child.kill(); [core, judgment, kc, u2b].forEach((s) => s.srv.close()); };
  return { port, seen, stop };
}

function call(port, urlPath, { method = "POST", secret = SECRET, body } = {}) {
  const headers = { "Content-Type": "application/json" };
  if (secret !== null) headers["x-rapidapi-proxy-secret"] = secret;
  return fetch(`http://127.0.0.1:${port}${urlPath}`, {
    method, headers, body: method === "GET" ? undefined : JSON.stringify(body ?? {}),
  });
}

test("LLM2APIの推論は本体へ、RapidAPI利用者名を付けて中継する", async () => {
  const gw = await boot();
  try {
    const res = await call(gw.port, "/v1/chat/completions",
      { body: { messages: [{ role: "user", content: "hi" }] } });
    assert.equal(res.status, 200);
    const hit = gw.seen.find((s) => s.name === "llm2api" && s.path === "/v1/chat/completions");
    assert.ok(hit, "LLM2API本体へ届いていない");
    assert.equal(hit.headers["x-rapidapi-user"], "rapidapi");
    // モデルは本体側が決める。ここで固定すると本体のプロバイダ切替と食い違う
    assert.equal(JSON.parse(hit.body).model, undefined);
    assert.equal(JSON.parse(hit.body).max_tokens, 2048);
  } finally { gw.stop(); }
});

test("/health は認証なしで通る(監視を止めない)", async () => {
  const gw = await boot();
  try {
    const res = await call(gw.port, "/health", { method: "GET", secret: null });
    assert.equal(res.status, 200);
    assert.ok(gw.seen.some((s) => s.name === "llm2api" && s.path === "/health"));
  } finally { gw.stop(); }
});

test("proxy secret が違えば403", async () => {
  const gw = await boot();
  try {
    const res = await call(gw.port, "/v1/models", { method: "GET", secret: "wrong" });
    assert.equal(res.status, 403);
  } finally { gw.stop(); }
});

test("kcbrain は /v1/<skill> へ、トークンとDeepSeek指定を付けて中継する", async () => {
  const gw = await boot();
  try {
    const res = await call(gw.port, "/kcbrain/analyze/technical", { body: { symbol: "BTC_USDT" } });
    assert.equal(res.status, 200);
    const hit = gw.seen.find((s) => s.name === "kcbrain");
    assert.equal(hit.path, "/v1/analyze/technical");
    assert.equal(hit.headers["x-kcbrain-token"], "kc-token");
    // 有料販路なのでDeepSeek(無料のローカルGemmaは内部専用)
    assert.equal(hit.headers["x-kcbrain-provider"], "deepseek");
  } finally { gw.stop(); }
});

test("多階層のskillもそのまま渡す", async () => {
  const gw = await boot();
  try {
    await call(gw.port, "/kcbrain/signal/pair/BTC_USDT", { body: {} });
    assert.equal(gw.seen.find((s) => s.name === "kcbrain").path, "/v1/signal/pair/BTC_USDT");
  } finally { gw.stop(); }
});

test("url2brain の生成系は provider を注入する", async () => {
  const gw = await boot();
  try {
    await call(gw.port, "/url2brain/generate/from-url", { body: { url: "https://example.com" } });
    const hit = gw.seen.find((s) => s.name === "url2brain");
    assert.equal(hit.path, "/v1/generate/from-url");
    assert.equal(JSON.parse(hit.body).provider, "deepseek");
  } finally { gw.stop(); }
});

test("url2brain の投稿系は confirm_post と persona を注入する", async () => {
  const gw = await boot();
  try {
    await call(gw.port, "/url2brain/post/bluesky", { body: { text: "hi" } });
    const body = JSON.parse(gw.seen.find((s) => s.name === "url2brain").body);
    assert.equal(body.confirm_post, true);
    assert.equal(body.persona, "kurage");
  } finally { gw.stop(); }
});

test("trade系は judgment API へ /v1 を付けて中継する", async () => {
  const gw = await boot();
  try {
    await call(gw.port, "/trade/risk-check", { body: { symbol: "BTC" } });
    assert.equal(gw.seen.find((s) => s.name === "judgment").path, "/v1/trade/risk-check");
  } finally { gw.stop(); }
});

test("skill未指定は400、未知のパスは404", async () => {
  const gw = await boot();
  try {
    assert.equal((await call(gw.port, "/kcbrain/", { body: {} })).status, 400);
    assert.equal((await call(gw.port, "/unknown", { body: {} })).status, 404);
  } finally { gw.stop(); }
});

test("メッセージ数と入力長の上限を超えたら400", async () => {
  const gw = await boot();
  try {
    const many = { messages: Array.from({ length: 21 }, () => ({ role: "user", content: "x" })) };
    assert.equal((await call(gw.port, "/v1/chat/completions", { body: many })).status, 400);
    const long = { messages: [{ role: "user", content: "x".repeat(4001) }] };
    assert.equal((await call(gw.port, "/v1/chat/completions", { body: long })).status, 400);
  } finally { gw.stop(); }
});
