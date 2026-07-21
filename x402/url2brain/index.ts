/**
 * Kurage URL2AI Publisher brain (url2brain) x402 service handler (Bankr rail).
 *
 * Proxies paid requests to the url2brain API (:18332): fetch a URL, extract its
 * content, and generate a Japanese/English announcement + blog article from it
 * on Gemma 4 12B (Ollama) or DeepSeek. Same brain that powers the url2pub web app
 * (Kurage URL2AI Publisher, https://url2ai.exbridge.jp/).
 *
 * NOTE: only read/generate endpoints are exposed here. The /v1/post/* endpoints
 * (Bluesky, Hatena, AIxSNS, Bludit, Hatena Blog) are intentionally NOT exposed —
 * those post under Kurage/EXBRIDGE's own accounts and must never be reachable by
 * a third party who simply pays the x402 fee.
 */

const UPSTREAM = process.env.URL2BRAIN_URL || "http://127.0.0.1:18332";
const TOKEN = process.env.URL2BRAIN_TOKEN || "";

// gateway path (after /url2brain) -> url2brain upstream path
const SKILLS: Record<string, string> = {
  "/analyze/url": "/v1/analyze/url",
  "/generate/announcement": "/v1/generate/announcement",
  "/generate/blog-article": "/v1/generate/blog-article",
  "/generate/from-url": "/v1/generate/from-url",
};

// 有料x402コールは常にDeepSeek(ホスト型API)へ強制する。url2pub Webアプリはこのレールを
// 経由しないためローカルGemma4のまま(2026-07-21方針: 課金コールとローカルGPUのKurage
// 本番系を競合させない)。
const LLM_SKILLS = new Set(["/generate/announcement", "/generate/blog-article", "/generate/from-url"]);

function json(data: unknown, init?: ResponseInit): Response {
  return Response.json(data, init);
}

export default async function handler(req: Request): Promise<Response> {
  const url = new URL(req.url);
  const path = url.pathname.replace(/^\/url2brain/, "") || "/";

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
        skills: Object.keys(SKILLS).map((s) => `POST /url2brain${s}`),
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
  if (LLM_SKILLS.has(path) && body && typeof body === "object") {
    body = { ...(body as Record<string, unknown>), provider: "deepseek" };
  }

  const upstream = await fetch(`${UPSTREAM}${upstreamPath}`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-URL2BRAIN-Token": TOKEN,
    },
    body: JSON.stringify(body),
  });
  const bytes = await upstream.arrayBuffer();
  return new Response(bytes, {
    status: upstream.status,
    headers: { "Content-Type": upstream.headers.get("content-type") || "application/json" },
  });
}
