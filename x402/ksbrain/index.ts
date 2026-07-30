/**
 * Kurage Stock Brain x402 service handler (Bankr rail).
 *
 * Proxies paid requests to the ksbrain judgment API (:18338).
 * Japan & US equity evidence analysis — no broker credentials, no order
 * execution. Judgments cite only the evidence supplied in the request
 * (stateless: the shared evidence store is not used on this rail), except
 * /us/analyze/full which auto-fetches SEC EDGAR facts/filings and daily
 * quotes for the ticker before running the full 7-perspective analysis.
 * Paid calls run on DeepSeek (deepseek-v4-flash) via the provider header.
 */

const UPSTREAM = process.env.KSBRAIN_URL || "http://exbridge.ddns.net:18338";
const TOKEN = process.env.KSBRAIN_TOKEN || "";

// gateway path (after /ksbrain) -> ksbrain upstream path
const SKILLS: Record<string, string> = {
  "/analyze/technical": "/v1/analyze/technical",
  "/analyze/fundamentals": "/v1/analyze/fundamentals",
  "/analyze/disclosure": "/v1/analyze/disclosure",
  "/analyze/news": "/v1/analyze/news",
  "/analyze/market-context": "/v1/analyze/market-context",
  "/debate/bull-bear": "/v1/debate/bull-bear",
  "/assess/risk": "/v1/assess/risk",
  "/analyze/full": "/v1/analyze/full",
  "/us/analyze/full": "/v1/us/analyze/full",
};

function json(data: unknown, init?: ResponseInit): Response {
  return Response.json(data, init);
}

export default async function handler(req: Request): Promise<Response> {
  const url = new URL(req.url);
  // Bankrはウォレットプレフィックス付きパス(/0x.../ksbrain/...)でハンドラを呼ぶことが
  // あるため、先頭一致でなく "/ksbrain/" の出現位置からスキルパスを切り出す
  const marker = "/ksbrain";
  const mi = url.pathname.indexOf(marker + "/");
  const path = mi >= 0 ? url.pathname.slice(mi + marker.length)
    : url.pathname.endsWith(marker) ? "/"
    : url.pathname.replace(/^\/ksbrain/, "") || "/";

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
        skills: Object.keys(SKILLS).map((s) => `POST /ksbrain${s}`),
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
      "X-API-Key": TOKEN,
      // 課金レールはDeepSeek(直叩き・内部利用はローカルGemmaのまま)
      "X-KSBRAIN-Provider": "deepseek",
    },
    body: JSON.stringify(body),
  });
  const bytes = await upstream.arrayBuffer();
  return new Response(bytes, {
    status: upstream.status,
    headers: { "Content-Type": upstream.headers.get("content-type") || "application/json" },
  });
}
