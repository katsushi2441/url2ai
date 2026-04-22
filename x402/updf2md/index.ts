/**
 * updf2md — x402 service handler.
 *
 * Accepts a JSON body with a publicly reachable PDF URL and forwards it to the
 * existing FastAPI converter endpoint as multipart/form-data.
 */

type Updf2mdRequest = {
  mode?: "pdf" | "ticker";
  pdf_url?: string;
  ticker?: string;
  pages?: string;
  filename?: string;
};

const DEFAULT_API_URL = "http://exbridge.ddns.net:8010/pdf/convert";
const DEFAULT_FINREPORT_API_URL = "http://exbridge.ddns.net:8010/pdf/report";

function getApiUrl(): string {
  return process.env.UPDF2MD_API_URL || DEFAULT_API_URL;
}

function getFinReportApiUrl(): string {
  return process.env.UPDF2MD_FINREPORT_API_URL || DEFAULT_FINREPORT_API_URL;
}

function json(data: unknown, init?: ResponseInit): Response {
  return Response.json(data, init);
}

function getSafeFilename(sourceUrl: string, fallback = "document.pdf"): string {
  try {
    const pathname = new URL(sourceUrl).pathname;
    const candidate = pathname.split("/").pop() || fallback;
    return candidate.toLowerCase().endsWith(".pdf") ? candidate : `${candidate}.pdf`;
  } catch {
    return fallback;
  }
}

export default async function handler(req: Request): Promise<Response> {
  if (req.method !== "POST") {
    return json({ error: "POST required" }, { status: 405 });
  }

  let body: Updf2mdRequest;
  try {
    body = (await req.json()) as Updf2mdRequest;
  } catch {
    return json({ error: "Invalid JSON body" }, { status: 400 });
  }

  const mode = body.mode?.trim().toLowerCase();
  const ticker = body.ticker?.trim();
  if (mode && !["pdf", "ticker"].includes(mode)) {
    return json({ error: "mode must be either 'pdf' or 'ticker'" }, { status: 400 });
  }

  if (!body.pdf_url && !ticker) {
    return json({ error: "Provide either pdf_url or ticker" }, { status: 400 });
  }

  const useTickerMode = mode === "ticker" || (!!ticker && mode !== "pdf");

  if (useTickerMode) {
    const backendResponse = await fetch(getFinReportApiUrl(), {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ ticker }),
    });

    const responseText = await backendResponse.text();
    let responseData: any = null;
    try {
      responseData = JSON.parse(responseText);
    } catch {
      responseData = { raw_response: responseText };
    }

    if (!backendResponse.ok) {
      return json(
        {
          error: "finreport backend request failed",
          backend_status: backendResponse.status,
          backend_response: responseData,
        },
        { status: 502 },
      );
    }

    return json(
      {
        ok: true,
        input_type: "ticker",
        ticker,
        markdown: responseData?.report || "",
        summary: responseData?.summary || "",
        sources: responseData?.sources || [],
        generated_at: responseData?.generated_at || null,
      },
      { status: backendResponse.status },
    );
  }

  if (!body.pdf_url) {
    return json({ error: "pdf_url is required when ticker is not provided" }, { status: 400 });
  }

  let pdfSourceUrl: URL;
  try {
    pdfSourceUrl = new URL(body.pdf_url);
  } catch {
    return json({ error: "pdf_url must be a valid absolute URL" }, { status: 400 });
  }

  if (!["http:", "https:"].includes(pdfSourceUrl.protocol)) {
    return json({ error: "pdf_url must use http or https" }, { status: 400 });
  }

  const pdfResponse = await fetch(pdfSourceUrl.toString());
  if (!pdfResponse.ok) {
    return json(
      {
        error: "Failed to download source PDF",
        status: pdfResponse.status,
        status_text: pdfResponse.statusText,
      },
      { status: 502 },
    );
  }

  const pdfBlob = await pdfResponse.blob();
  const formData = new FormData();
  formData.append("file", pdfBlob, body.filename || getSafeFilename(pdfSourceUrl.toString()));
  if (body.pages) {
    formData.append("pages", body.pages);
  }

  const backendResponse = await fetch(getApiUrl(), {
    method: "POST",
    body: formData,
  });

  const responseText = await backendResponse.text();
  let responseData: unknown = null;
  try {
    responseData = JSON.parse(responseText);
  } catch {
    responseData = { raw_response: responseText };
  }

  if (!backendResponse.ok) {
    return json(
      {
        error: "updf2md backend request failed",
        backend_status: backendResponse.status,
        backend_response: responseData,
      },
      { status: 502 },
    );
  }

  return json(responseData, { status: backendResponse.status });
}
