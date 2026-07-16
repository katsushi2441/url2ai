/**
 * Kurage FX Brain x402 service handler (Bankr rail).
 *
 * Proxies paid requests to the kfxbrain judgment API (:18326).
 * FX judgment only — no broker credentials, no order execution.
 * Vendored OSS intelligence: TradingAgents (Apache-2.0), FinGPT (MIT),
 * ai-hedge-fund (MIT), running on local Gemma 4 12B.
 *
 * NOTE: the TradingAgents full graph (/v1/vendor/tradingagents/run, ~5.5 min)
 * is intentionally NOT exposed on this rail (serverless timeout); it is sold
 * on the direct x402 rail at https://bittensorman.xyz/fxbrain/tradingagents/run
 */

const UPSTREAM = process.env.FXBRAIN_URL || "http://exbridge.ddns.net:18326";
const TOKEN = process.env.FXBRAIN_TOKEN || "";

// gateway path (after /fxbrain) -> kfxbrain upstream path
const SKILLS: Record<string, string> = {
  "/analyze/technical": "/v1/analyze/technical",
  "/analyze/macro": "/v1/analyze/macro",
  "/analyze/sentiment": "/v1/analyze/sentiment",
  "/analyze/full": "/v1/analyze/full",
  "/debate/bull-bear": "/v1/debate/bull-bear",
  "/decide/trade": "/v1/decide/trade",
  "/assess/risk": "/v1/assess/risk",
  "/decide/portfolio": "/v1/decide/portfolio",
  "/review/trade": "/v1/review/trade",
  "/fingpt/sentiment": "/v1/vendor/fingpt/sentiment",
  "/fingpt/headline": "/v1/vendor/fingpt/headline",
  "/fingpt/forecast": "/v1/vendor/fingpt/forecast",
  "/fingpt/report": "/v1/vendor/fingpt/report",
  "/hedge/news-sentiment": "/v1/vendor/ai-hedge-fund/news-sentiment",
  "/hedge/portfolio": "/v1/vendor/ai-hedge-fund/portfolio",
};

function json(data: unknown, init?: ResponseInit): Response {
  return Response.json(data, init);
}

export default async function handler(req: Request): Promise<Response> {
  const url = new URL(req.url);
  const path = url.pathname.replace(/^\/fxbrain/, "") || "/";

  if (req.method === "GET" && ["/health", "/healthz"].includes(path)) {
    const upstream = await fetch(`${UPSTREAM}/health`);
    return json(await upstream.json(), { status: upstream.status });
  }

  if (req.method !== "POST") {
    return json({ error: "POST required" }, { status: 405 });
  }

  const upstreamPath = SKILLS[path];
  if (!upstreamPath) {
    return json(
      {
        error: "Unknown endpoint",
        skills: Object.keys(SKILLS).map((s) => `POST /fxbrain${s}`),
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
      "X-KFXBRAIN-Token": TOKEN,
    },
    body: JSON.stringify(body),
  });
  const bytes = await upstream.arrayBuffer();
  return new Response(bytes, {
    status: upstream.status,
    headers: { "Content-Type": upstream.headers.get("content-type") || "application/json" },
  });
}
