/**
 * Kurage TradingAgents x402 service handler (Bankr rail).
 *
 * Proxies paid requests to the ktajp analysis API (:18337).
 * Multi-agent analysis of Japanese equities: specialist analysts evaluate
 * independently, then a bull/bear debate and a risk review produce an
 * evidence-backed judgment. Paid calls run on DeepSeek (deepseek-v4-flash).
 * Informational only — not investment advice.
 */

const UPSTREAM = process.env.KTAJP_URL || "http://exbridge.ddns.net:18337";
const TOKEN = process.env.KTAJP_TOKEN || "";

// gateway path (after /ktajp) -> upstream path
const SKILLS: Record<string, string> = {
  "/analyze": "/v1/analyze",
};

function json(data: unknown, init?: ResponseInit): Response {
  return Response.json(data, init);
}

export default async function handler(req: Request): Promise<Response> {
  const url = new URL(req.url);
  // Bankrはウォレットプレフィックス付きパス(/0x.../ktajp/...)でハンドラを呼ぶことが
  // あるため、先頭一致でなく "/ktajp" の出現位置からスキルパスを切り出す
  const marker = "/ktajp";
  const mi = url.pathname.indexOf(marker + "/");
  const path = mi >= 0 ? url.pathname.slice(mi + marker.length)
    : url.pathname.endsWith(marker) ? "/"
    : url.pathname.replace(/^\/ktajp/, "") || "/";

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
        skills: Object.keys(SKILLS).map((s) => `POST /ktajp${s}`),
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
    headers: { "Content-Type": "application/json", "X-API-Key": TOKEN },
    body: JSON.stringify(body),
  });
  const bytes = await upstream.arrayBuffer();
  return new Response(bytes, {
    status: upstream.status,
    headers: {
      "Content-Type": upstream.headers.get("Content-Type") || "application/json",
    },
  });
}
