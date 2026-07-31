/**
 * Kurage Finanalyst x402 service handler (Bankr rail).
 *
 * Proxies paid requests to the kfinanalyst report API (:18351).
 * AI analyst reports in Japanese for stock / crypto / FX symbols,
 * generated on DeepSeek (deepseek-v4-flash). Built on FinRobot
 * (Apache 2.0); "FinRobot" is a trademark of AI4Finance Foundation —
 * this is an independent derivative, not the official product.
 * Informational only, not investment advice.
 */

const UPSTREAM = process.env.KFINANALYST_URL || "http://exbridge.ddns.net:18351";
const TOKEN = process.env.KFINANALYST_TOKEN || "";

// gateway path (after /kfinanalyst) -> upstream path
const SKILLS: Record<string, string> = {
  "/report": "/v1/report",
};

function json(data: unknown, init?: ResponseInit): Response {
  return Response.json(data, init);
}

export default async function handler(req: Request): Promise<Response> {
  const url = new URL(req.url);
  // Bankrはウォレットプレフィックス付きパス(/0x.../kfinanalyst/...)でハンドラを呼ぶことが
  // あるため、先頭一致でなく "/kfinanalyst" の出現位置からスキルパスを切り出す
  const marker = "/kfinanalyst";
  const mi = url.pathname.indexOf(marker + "/");
  const path = mi >= 0 ? url.pathname.slice(mi + marker.length)
    : url.pathname.endsWith(marker) ? "/"
    : url.pathname.replace(/^\/kfinanalyst/, "") || "/";

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
        skills: Object.keys(SKILLS).map((s) => `POST /kfinanalyst${s}`),
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
      // 課金レール専用の内部トークン(一般ユーザーのDeepSeek生成は/v1/report側で固定)
      "X-API-Key": TOKEN,
    },
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
