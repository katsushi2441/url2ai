// LLM2API の使用量計測。
//
// 課金レール(x402 / JPYC / RapidAPI)は既にあるが、「誰がどれだけ使ったか」を
// 記録していなかった。売上の内訳が分からないと価格も改善できないため、
// トークン数を呼び出し元ごとに残す。
//
// 依存パッケージを増やさない方針なので、追記専用のJSONLに書く。
// Node 20 には node:sqlite が無く、better-sqlite3 を入れると
// package.json の依存ゼロという性質が失われる。
import fs from "node:fs";
import path from "node:path";

const DATA_DIR = process.env.LLM2API_USAGE_DIR
  || path.join(path.dirname(new URL(import.meta.url).pathname), "data");

// 1リクエストで読み込む応答の上限。これを超える分は計測せず素通しする
// (計測のためにメモリを食い潰さない)。
const MAX_CAPTURE_BYTES = 512 * 1024;

function monthFile(date = new Date()) {
  const stamp = `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, "0")}`;
  return path.join(DATA_DIR, `usage-${stamp}.jsonl`);
}

/** x402 の x-payment ヘッダから支払者アドレスを取り出す。 */
function payerFromPaymentHeader(raw) {
  try {
    const parsed = JSON.parse(Buffer.from(String(raw), "base64").toString("utf8"));
    const payload = parsed && parsed.paymentPayload;
    const auth = payload && payload.authorization;
    return (auth && (auth.from || auth.payer)) || "";
  } catch {
    return "";
  }
}

/**
 * 呼び出し元を特定する。
 * JPYCゲートウェイは req.headers をそのまま上流へ渡すので、x-payment はここまで届く。
 */
export function identify(req) {
  const headers = req.headers || {};
  const payment = headers["x-payment"] || headers["payment-signature"];
  if (payment) {
    const payer = payerFromPaymentHeader(payment);
    if (payer) return { rail: "x402", caller: String(payer).toLowerCase() };
  }
  const rapidUser = headers["x-rapidapi-user"] || headers["x-rapidapi-subscription"];
  if (rapidUser) return { rail: "rapidapi", caller: String(rapidUser) };
  const forwarded = String(headers["x-forwarded-for"] || "").split(",")[0].trim();
  if (forwarded) return { rail: "direct", caller: forwarded };
  return { rail: "direct", caller: (req.socket && req.socket.remoteAddress) || "unknown" };
}

/** 応答本文から OpenAI 互換の usage を取り出す。ストリーミング(SSE)にも対応する。 */
export function extractUsage(bodyText) {
  if (!bodyText) return null;
  try {
    const parsed = JSON.parse(bodyText);
    if (parsed && parsed.usage) return parsed.usage;
  } catch {
    // SSE は "data: {...}" の連なり。usage は末尾付近のチャンクに入る。
    const lines = bodyText.split("\n").filter((l) => l.startsWith("data:"));
    for (let i = lines.length - 1; i >= 0; i -= 1) {
      const payload = lines[i].slice(5).trim();
      if (!payload || payload === "[DONE]") continue;
      try {
        const parsed = JSON.parse(payload);
        if (parsed && parsed.usage) return parsed.usage;
      } catch {
        // 壊れたチャンクは飛ばす
      }
    }
  }
  return null;
}

/** 1件記録する。計測の失敗で本来の応答を壊さないよう、例外は握りつぶす。 */
export function record(entry) {
  try {
    fs.mkdirSync(DATA_DIR, { recursive: true });
    const line = JSON.stringify({ ts: new Date().toISOString(), ...entry });
    fs.appendFileSync(monthFile(), line + "\n", "utf8");
  } catch {
    // 計測は本業ではない。書けなくても推論は返す。
  }
}

/**
 * 応答ストリームを本来の宛先へ流しつつ、usage を取り出して記録する。
 * pipe と data リスナは併用できる(pipe は自前の data リスナを足すだけ)。
 */
export function meter(proxyRes, res, meta) {
  const chunks = [];
  let size = 0;
  let truncated = false;
  proxyRes.on("data", (chunk) => {
    if (truncated) return;
    size += chunk.length;
    if (size > MAX_CAPTURE_BYTES) { truncated = true; chunks.length = 0; return; }
    chunks.push(chunk);
  });
  proxyRes.on("end", () => {
    const usage = truncated ? null : extractUsage(Buffer.concat(chunks).toString("utf8"));
    record({
      ...meta,
      status: proxyRes.statusCode || 0,
      prompt_tokens: usage ? usage.prompt_tokens : null,
      completion_tokens: usage ? usage.completion_tokens : null,
      total_tokens: usage ? usage.total_tokens : null,
    });
  });
  proxyRes.pipe(res);
}

/**
 * 記録を集計する。呼び出し元別・モデル別・日別。
 * 直近 months か月分のファイルだけ読む(全期間を読むと際限なく遅くなる)。
 */
export function summary(months = 3) {
  const files = [];
  const now = new Date();
  for (let i = 0; i < months; i += 1) {
    files.push(monthFile(new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth() - i, 1))));
  }
  const totals = { calls: 0, prompt_tokens: 0, completion_tokens: 0, total_tokens: 0, errors: 0 };
  const byCaller = new Map();
  const byModel = new Map();
  const byDay = new Map();
  const byRail = new Map();

  for (const file of files) {
    let text = "";
    try { text = fs.readFileSync(file, "utf8"); } catch { continue; }
    for (const line of text.split("\n")) {
      if (!line.trim()) continue;
      let row;
      try { row = JSON.parse(line); } catch { continue; }
      const tokens = Number(row.total_tokens || 0);
      totals.calls += 1;
      totals.prompt_tokens += Number(row.prompt_tokens || 0);
      totals.completion_tokens += Number(row.completion_tokens || 0);
      totals.total_tokens += tokens;
      if (Number(row.status) >= 400) totals.errors += 1;

      const bump = (map, key) => {
        const current = map.get(key) || { calls: 0, total_tokens: 0 };
        current.calls += 1;
        current.total_tokens += tokens;
        map.set(key, current);
      };
      bump(byCaller, row.caller || "unknown");
      bump(byModel, row.model || "unknown");
      bump(byDay, String(row.ts || "").slice(0, 10) || "unknown");
      bump(byRail, row.rail || "direct");
    }
  }

  const toSorted = (map, limit) => [...map.entries()]
    .map(([key, value]) => ({ key, ...value }))
    .sort((a, b) => b.total_tokens - a.total_tokens || b.calls - a.calls)
    .slice(0, limit);

  return {
    totals,
    byCaller: toSorted(byCaller, 50),
    byModel: toSorted(byModel, 20),
    byRail: toSorted(byRail, 10),
    byDay: [...byDay.entries()].map(([key, value]) => ({ key, ...value })).sort((a, b) => a.key.localeCompare(b.key)),
  };
}
