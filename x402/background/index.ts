/**
 * OSS2API background-removal x402 service handler.
 *
 * Proxies paid requests to the local background-removal-js gateway.
 */

type BackgroundMode = "remove" | "replace" | "blur";

type BackgroundRequest = {
  image_url?: string;
  image_base64?: string;
  mode?: BackgroundMode;
  background_color?: string;
  background_image_url?: string;
  background_image_base64?: string;
  blur_sigma?: number;
  output_format?: "png" | "webp" | "jpeg";
  output_quality?: number;
  response?: "binary" | "json";
};

const DEFAULT_API_URL = "http://127.0.0.1:8015/run";
const MAX_BASE64_CHARS = 16 * 1024 * 1024;

function getApiUrl(): string {
  return process.env.BACKGROUND_REMOVAL_API_URL || DEFAULT_API_URL;
}

function json(data: unknown, init?: ResponseInit): Response {
  return Response.json(data, init);
}

function validateBody(body: BackgroundRequest): string | null {
  if (!body.image_url && !body.image_base64) {
    return "Provide image_url or image_base64";
  }
  if (body.image_base64 && body.image_base64.length > MAX_BASE64_CHARS) {
    return "image_base64 is too large";
  }
  if (body.mode && !["remove", "replace", "blur"].includes(body.mode)) {
    return "mode must be remove, replace, or blur";
  }
  if (body.output_format && !["png", "webp", "jpeg"].includes(body.output_format)) {
    return "output_format must be png, webp, or jpeg";
  }
  if (body.response && !["binary", "json"].includes(body.response)) {
    return "response must be binary or json";
  }
  return null;
}

export default async function handler(req: Request): Promise<Response> {
  if (req.method !== "POST") {
    return json({ error: "POST required" }, { status: 405 });
  }

  let body: BackgroundRequest;
  try {
    body = (await req.json()) as BackgroundRequest;
  } catch {
    return json({ error: "Invalid JSON body" }, { status: 400 });
  }

  const error = validateBody(body);
  if (error) {
    return json({ error }, { status: 400 });
  }

  const backendResponse = await fetch(getApiUrl(), {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: body.response === "json" ? "application/json" : "image/*" },
    body: JSON.stringify({
      ...body,
      mode: body.mode || "remove",
    }),
  });

  const contentType = backendResponse.headers.get("content-type") || "";
  const responseBytes = await backendResponse.arrayBuffer();

  if (!backendResponse.ok) {
    let backendBody: unknown = new TextDecoder().decode(responseBytes);
    try {
      backendBody = JSON.parse(String(backendBody));
    } catch {
      // Keep raw text.
    }
    return json(
      {
        error: "background-removal backend request failed",
        backend_status: backendResponse.status,
        backend_response: backendBody,
      },
      { status: 502 },
    );
  }

  return new Response(responseBytes, {
    status: backendResponse.status,
    headers: {
      "Content-Type": contentType || (body.response === "json" ? "application/json" : "image/png"),
      "Cache-Control": "no-store",
    },
  });
}
