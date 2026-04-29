/**
 * LLM2API x402 service handler.
 *
 * Proxies paid requests to the LLM gateway (port 8019).
 * Powered by Ollama + Gemma 4 E4B.
 * OpenAI-compatible: POST /llm2api/v1/chat/completions
 */

const UPSTREAM = process.env.LLM2API_URL || "http://127.0.0.1:8019";

function json(data: unknown, init?: ResponseInit): Response {
  return Response.json(data, init);
}

export default async function handler(req: Request): Promise<Response> {
  const url = new URL(req.url);
  // strip leading /llm2api prefix if present
  const path = url.pathname.replace(/^\/llm2api/, "") || "/";

  if (req.method === "GET" && ["/health", "/healthz"].includes(path)) {
    const upstream = await fetch(`${UPSTREAM}/health`);
    const data = await upstream.json();
    return json(data, { status: upstream.status });
  }

  if (req.method === "GET" && path === "/v1/models") {
    const upstream = await fetch(`${UPSTREAM}/llm/v1/models`);
    const data = await upstream.json();
    return json(data, { status: upstream.status });
  }

  if (req.method !== "POST") {
    return json({ error: "POST required" }, { status: 405 });
  }

  if (path !== "/v1/chat/completions") {
    return json(
      { error: "Unknown endpoint. Use POST /llm2api/v1/chat/completions" },
      { status: 404 },
    );
  }

  let body: unknown;
  try {
    body = await req.json();
  } catch {
    return json({ error: "Invalid JSON body" }, { status: 400 });
  }

  const upstream = await fetch(`${UPSTREAM}/llm/v1/chat/completions`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });

  const contentType = upstream.headers.get("content-type") || "application/json";
  const bytes = await upstream.arrayBuffer();

  return new Response(bytes, {
    status: upstream.status,
    headers: {
      "Content-Type": contentType,
      "Cache-Control": "no-store",
    },
  });
}
