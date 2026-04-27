/**
 * OSS2API x402 service handler.
 *
 * Proxies paid requests to the OSS2API gateway (port 8015).
 * Skills: /image/remove-background | /url/analyze | /url/browse | /url/scan
 */

const UPSTREAM = process.env.OSS2API_URL || "http://127.0.0.1:8015";

const ALLOWED_SKILLS = [
  "/image/remove-background",
  "/url/analyze",
  "/url/browse",
  "/url/scan",
];

function json(data: unknown, init?: ResponseInit): Response {
  return Response.json(data, init);
}

export default async function handler(req: Request): Promise<Response> {
  if (req.method !== "POST") {
    return json({ error: "POST required" }, { status: 405 });
  }

  const url = new URL(req.url);
  // strip leading /oss2api prefix if present
  const skill = url.pathname.replace(/^\/oss2api/, "") || "/";

  if (!ALLOWED_SKILLS.includes(skill)) {
    return json(
      { error: `Unknown skill. Use one of: ${ALLOWED_SKILLS.join(", ")}` },
      { status: 404 },
    );
  }

  let body: unknown;
  try {
    body = await req.json();
  } catch {
    return json({ error: "Invalid JSON body" }, { status: 400 });
  }

  const upstream = await fetch(`${UPSTREAM}/oss2api${skill}`, {
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
