// LLM2API の使用量計測のテスト。依存パッケージを増やさないため node:test を使う。
//   node --test test_usage.mjs
import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";

process.env.LLM2API_USAGE_DIR = fs.mkdtempSync(path.join(os.tmpdir(), "llm2api-usage-"));
const { identify, extractUsage, record, summary } = await import("./usage.js");

function paymentHeader(from) {
  return Buffer.from(JSON.stringify({
    paymentPayload: { authorization: { from } },
  })).toString("base64");
}

test("x402の支払者アドレスを呼び出し元にする", () => {
  const who = identify({ headers: { "x-payment": paymentHeader("0xAbCdEf0000000000000000000000000000000001") } });
  assert.equal(who.rail, "x402");
  // アドレスは大小文字で別人扱いされないよう正規化する
  assert.equal(who.caller, "0xabcdef0000000000000000000000000000000001");
});

test("壊れたx-paymentでも落ちず、直アクセス扱いに退避する", () => {
  const who = identify({ headers: { "x-payment": "not-base64-json" }, socket: { remoteAddress: "10.0.0.9" } });
  assert.equal(who.rail, "direct");
  assert.equal(who.caller, "10.0.0.9");
});

test("RapidAPI利用者を識別する", () => {
  const who = identify({ headers: { "x-rapidapi-user": "kojima" } });
  assert.deepEqual(who, { rail: "rapidapi", caller: "kojima" });
});

test("x-forwarded-forは先頭のIPだけ使う", () => {
  const who = identify({ headers: { "x-forwarded-for": "203.0.113.5, 70.41.3.18" } });
  assert.equal(who.caller, "203.0.113.5");
});

test("通常のJSON応答からusageを取り出す", () => {
  const usage = extractUsage(JSON.stringify({ usage: { prompt_tokens: 12, completion_tokens: 30, total_tokens: 42 } }));
  assert.equal(usage.total_tokens, 42);
});

test("SSEストリーミング応答の末尾からusageを取り出す", () => {
  const body = [
    'data: {"choices":[{"delta":{"content":"hi"}}]}',
    'data: {"choices":[],"usage":{"prompt_tokens":5,"completion_tokens":7,"total_tokens":12}}',
    "data: [DONE]",
  ].join("\n");
  const usage = extractUsage(body);
  assert.equal(usage.total_tokens, 12);
});

test("usageが無い応答ではnullを返す(0と混同しない)", () => {
  assert.equal(extractUsage(JSON.stringify({ error: "boom" })), null);
  assert.equal(extractUsage(""), null);
});

test("記録を呼び出し元・レール・日別に集計する", () => {
  record({ rail: "x402", caller: "0xaaa", model: "deepseek-v4-flash", status: 200,
           prompt_tokens: 10, completion_tokens: 20, total_tokens: 30 });
  record({ rail: "x402", caller: "0xaaa", model: "deepseek-v4-flash", status: 200,
           prompt_tokens: 1, completion_tokens: 2, total_tokens: 3 });
  record({ rail: "rapidapi", caller: "kojima", model: "gemma4:e4b", status: 500,
           prompt_tokens: null, completion_tokens: null, total_tokens: null });

  const result = summary(1);
  assert.equal(result.totals.calls, 3);
  assert.equal(result.totals.total_tokens, 33);
  assert.equal(result.totals.errors, 1);          // 500 は失敗として数える
  assert.equal(result.byCaller[0].key, "0xaaa");  // トークン数の多い順
  assert.equal(result.byCaller[0].calls, 2);
  assert.equal(result.byRail.length, 2);
  assert.equal(result.byDay.length, 1);
});

test("計測が書けなくても例外を投げない", () => {
  const original = process.env.LLM2API_USAGE_DIR;
  try {
    // 書き込めない場所を指しても推論の応答を壊してはいけない
    assert.doesNotThrow(() => record({ caller: "x", total_tokens: 1 }));
  } finally {
    process.env.LLM2API_USAGE_DIR = original;
  }
});
