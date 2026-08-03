// 無課金の直叩き対策の判定テスト。
//   node --test test_access.mjs
//
// 外部IPからのアクセスはローカルでは再現できない(ループバックは常に許可)ため、
// 判定を access.js に切り出して直接検証する。
import test from "node:test";
import assert from "node:assert/strict";
import { isAuthorized, parseAllowedIps, suppliedToken, tokenOk } from "./access.js";

const TOKEN = "t0ken-test-value";
const CONFIG = { token: TOKEN, allowedIps: parseAllowedIps("") };
const OUTSIDE = "203.0.113.9"; // 外部の呼び出し元

const req = (headers, ip = OUTSIDE) => ({ headers, ip });

test("正しいトークンヘッダなら通る(Bankrハンドラ経由の正規経路)", () => {
  assert.equal(isAuthorized(req({ "x-llm2api-token": TOKEN }), CONFIG), true);
});

test("Authorization: Bearer でも受け付ける", () => {
  assert.equal(isAuthorized(req({ authorization: `Bearer ${TOKEN}` }), CONFIG), true);
});

test("外部からトークン無しは弾く(これが収益漏れの本体)", () => {
  assert.equal(isAuthorized(req({}), CONFIG), false);
});

test("外部から誤ったトークンは弾く", () => {
  assert.equal(isAuthorized(req({ "x-llm2api-token": "wrong" }), CONFIG), false);
});

test("長さだけ合わせた総当たりも弾く", () => {
  const same = "x".repeat(TOKEN.length);
  assert.equal(isAuthorized(req({ "x-llm2api-token": same }), CONFIG), false);
});

test("ループバック(自前のJPYC/RapidAPIラッパー)はトークン無しでも通る", () => {
  for (const ip of ["127.0.0.1", "::1", "::ffff:127.0.0.1"]) {
    assert.equal(isAuthorized(req({}, ip), CONFIG), true, `${ip} を塞ぐと課金レールが死ぬ`);
  }
});

test("追加の許可IPを設定できる", () => {
  const config = { token: TOKEN, allowedIps: parseAllowedIps("198.51.100.7, 203.0.113.1") };
  assert.equal(isAuthorized(req({}, "198.51.100.7"), config), true);
  assert.equal(isAuthorized(req({}, "198.51.100.8"), config), false);
});

test("x402の支払いヘッダがあれば通る(前段で決済済み)", () => {
  assert.equal(isAuthorized(req({ "x-payment": "eyJhIjoxfQ==" }), CONFIG), true);
  assert.equal(isAuthorized(req({ "payment-signature": "sig" }), CONFIG), true);
});

test("トークン未設定なら素通しする(設定前に売上を止めない)", () => {
  const config = { token: "", allowedIps: parseAllowedIps("") };
  assert.equal(isAuthorized(req({}), config), true);
});

test("トークン比較は空文字を通さない", () => {
  assert.equal(tokenOk("", TOKEN), false);
  assert.equal(tokenOk(TOKEN, ""), false);
  assert.equal(tokenOk(TOKEN, TOKEN), true);
});

test("ヘッダからトークンを取り出す順序", () => {
  assert.equal(suppliedToken({ "x-llm2api-token": "a", authorization: "Bearer b" }), "a");
  assert.equal(suppliedToken({ authorization: "bearer b" }), "b");
  assert.equal(suppliedToken({ authorization: "Basic xyz" }), "");
  assert.equal(suppliedToken({}), "");
});
