import http from "node:http";
import { Buffer } from "node:buffer";

const HOST               = process.env.HOST                || "0.0.0.0";
const PORT               = Number.parseInt(process.env.PORT || "8018", 10);
const OLLAMA_HOST        = process.env.OLLAMA_HOST         || "192.168.0.14";
const OLLAMA_PORT        = Number.parseInt(process.env.OLLAMA_PORT || "11434", 10);
const DEFAULT_MODEL      = process.env.DEFAULT_MODEL       || "gemma4:e4b";
const RAPIDAPI_SECRET    = process.env.RAPIDAPI_PROXY_SECRET || "";
const MAX_BODY_BYTES     = 64 * 1024;
const MAX_INPUT_CHARS    = Number.parseInt(process.env.MAX_INPUT_CHARS   || "4000", 10);
const MAX_MESSAGES       = Number.parseInt(process.env.MAX_MESSAGES      || "20",   10);
const MAX_OUTPUT_TOKENS  = Number.parseInt(process.env.MAX_OUTPUT_TOKENS || "2048", 10);

function json(res, status, data) {
  const body = JSON.stringify(data);
  res.writeHead(status, { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-store" });
  res.end(body);
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on("data", (chunk) => {
      size += chunk.length;
      if (size > MAX_BODY_BYTES) { reject(new Error("Body too large")); req.destroy(); return; }
      chunks.push(chunk);
    });
    req.on("end", () => resolve(Buffer.concat(chunks).toString("utf8")));
    req.on("error", reject);
  });
}

function proxyToOllama(req, res, ollamaPath, bodyStr) {
  const options = {
    hostname: OLLAMA_HOST,
    port: OLLAMA_PORT,
    path: ollamaPath,
    method: req.method,
    headers: {
      "Content-Type": "application/json",
      "Content-Length": Buffer.byteLength(bodyStr),
    },
  };

  const proxyReq = http.request(options, (proxyRes) => {
    res.writeHead(proxyRes.statusCode || 200, {
      "Content-Type": proxyRes.headers["content-type"] || "application/json",
      "Cache-Control": "no-store",
      "Transfer-Encoding": proxyRes.headers["transfer-encoding"] || "",
    });
    proxyRes.pipe(res);
  });

  proxyReq.on("error", (err) => {
    json(res, 502, { error: `Ollama unavailable: ${err.message}` });
  });

  proxyReq.write(bodyStr);
  proxyReq.end();
}

async function handle(req, res) {
  const url = new URL(req.url || "/", `http://${req.headers.host || "localhost"}`);
  const path = url.pathname;

  if (req.method === "GET" && ["/health", "/healthz"].includes(path)) {
    return json(res, 200, { ok: true, service: "llm-gateway-rapidapi", model: DEFAULT_MODEL });
  }

  // Validate RapidAPI proxy secret (skip check if secret not configured)
  if (RAPIDAPI_SECRET) {
    const incoming = req.headers["x-rapidapi-proxy-secret"] || "";
    console.log(`[secret] incoming="${incoming}" expected="${RAPIDAPI_SECRET}" match=${incoming === RAPIDAPI_SECRET}`);
    if (incoming !== RAPIDAPI_SECRET) {
      return json(res, 403, { error: "Forbidden" });
    }
  }

  if (req.method === "GET" && path === "/v1/models") {
    return json(res, 200, {
      object: "list",
      data: [{ id: DEFAULT_MODEL, object: "model", created: 0, owned_by: "ollama" }],
    });
  }

  if (req.method === "POST" && path === "/v1/chat/completions") {
    const bodyStr = await readBody(req);
    let body;
    try { body = JSON.parse(bodyStr); } catch { return json(res, 400, { error: "Invalid JSON" }); }

    if (!Array.isArray(body.messages) || body.messages.length === 0) {
      return json(res, 400, { error: "messages array is required" });
    }
    if (body.messages.length > MAX_MESSAGES) {
      return json(res, 400, { error: `Too many messages (max ${MAX_MESSAGES})` });
    }
    const totalChars = body.messages.reduce((sum, m) => sum + String(m.content || "").length, 0);
    if (totalChars > MAX_INPUT_CHARS) {
      return json(res, 400, { error: `Input too long (${totalChars} chars, max ${MAX_INPUT_CHARS})` });
    }

    body.model = DEFAULT_MODEL;
    if (!body.max_tokens || body.max_tokens > MAX_OUTPUT_TOKENS) {
      body.max_tokens = MAX_OUTPUT_TOKENS;
    }

    return proxyToOllama(req, res, "/v1/chat/completions", JSON.stringify(body));
  }

  return json(res, 404, { error: "Not found", hint: "POST /v1/chat/completions" });
}

http.createServer((req, res) => {
  handle(req, res).catch((err) => json(res, 500, { error: err.message || String(err) }));
}).listen(PORT, HOST, () => {
  console.log(`LLM gateway (RapidAPI) → http://${HOST}:${PORT}`);
  console.log(`  Ollama: http://${OLLAMA_HOST}:${OLLAMA_PORT}  model: ${DEFAULT_MODEL}`);
  console.log(`  RapidAPI secret: ${RAPIDAPI_SECRET ? "configured" : "NOT SET (open access)"}`);
});
