/**
 * Kurage Crypto Brain x402 service handler (Bankr rail).
 *
 * Proxies paid requests to the kcbrain judgment API (:18328).
 * Crypto judgment only — no exchange credentials, no order execution.
 * Vendored OSS intelligence: ai-hedge-fund-crypto (MIT), crypto-trading-agents
 * (from TradingAgents, Apache-2.0), Vibe-Trading, LLM_trader, HELM Agents,
 * running on DeepSeek (deepseek-v4-flash).
 *
 * NOTE: multi-step chained skills (vendor/crypto-trading-agents/debate,
 * vendor/vibe-trading/research, vendor/helm-agents/consensus — each 3-5
 * sequential DeepSeek calls) are intentionally NOT exposed on this flat-price
 * rail; they are sold separately on the direct x402 rail at
 * https://bittensorman.xyz/kcbrain/vendor/... ($0.003, matches their higher cost).
 */

const UPSTREAM = process.env.KCBRAIN_URL || "http://exbridge.ddns.net:18328";
const TOKEN = process.env.KCBRAIN_TOKEN || "";

// gateway path (after /kcbrain) -> kcbrain upstream path
const SKILLS: Record<string, string> = {
  "/analyze/technical": "/v1/analyze/technical",
  "/analyze/onchain": "/v1/analyze/onchain",
  "/analyze/sentiment": "/v1/analyze/sentiment",
  "/analyze/full": "/v1/analyze/full",
  "/debate/bull-bear": "/v1/debate/bull-bear",
  "/decide/trade": "/v1/decide/trade",
  "/assess/risk": "/v1/assess/risk",
  "/decide/portfolio": "/v1/decide/portfolio",
  "/review/trade": "/v1/review/trade",
  "/market/opportunity-ranking": "/v1/market/opportunity-ranking",
  "/market/flow-ranking": "/v1/market/flow-ranking",
  "/market/anomaly": "/v1/market/anomaly",
  "/market/liquidation-risk": "/v1/market/liquidation-risk",
  "/vendor/ai-hedge-fund-crypto/portfolio": "/v1/vendor/ai-hedge-fund-crypto/portfolio",
  "/vendor/llm-trader/analyze": "/v1/vendor/llm-trader/analyze",
};

function json(data: unknown, init?: ResponseInit): Response {
  return Response.json(data, init);
}

export default async function handler(req: Request): Promise<Response> {
  const url = new URL(req.url);
  const path = url.pathname.replace(/^\/kcbrain/, "") || "/";

  if (req.method === "GET" && ["/health", "/healthz"].includes(path)) {
    const upstream = await fetch(`${UPSTREAM}/health`);
    return json(await upstream.json(), { status: upstream.status });
  }

  if (req.method !== "POST") {
    return json({ error: "POST required" }, { status: 405 });
  }

  let upstreamPath = SKILLS[path];
  // 単一ペアシグナルはパスパラメータ(/signal/pair/BTC_USDT等)なので動的にマッチ
  if (!upstreamPath && /^\/signal\/pair\/[A-Z0-9]{2,10}_[A-Z]{3,6}$/.test(path)) {
    upstreamPath = `/v1${path}`;
  }
  if (!upstreamPath) {
    return json(
      {
        error: "Unknown endpoint",
        skills: Object.keys(SKILLS).map((s) => `POST /kcbrain${s}`),
      },
      { status: 404 },
    );
  }

  let body: unknown;
  try {
    body = await req.json();
  } catch {
    return json({ error: "Invalid JSON body" }, { status: 400 });
  }

  const upstream = await fetch(`${UPSTREAM}${upstreamPath}`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-KCBRAIN-Token": TOKEN,
    },
    body: JSON.stringify(body),
  });
  const bytes = await upstream.arrayBuffer();
  return new Response(bytes, {
    status: upstream.status,
    headers: { "Content-Type": upstream.headers.get("content-type") || "application/json" },
  });
}
