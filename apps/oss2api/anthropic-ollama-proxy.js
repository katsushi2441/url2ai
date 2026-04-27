/**
 * anthropic-ollama-proxy.js
 *
 * Anthropic API ↔ Ollama 完全変換プロキシ
 * - メッセージ形式変換
 * - ツール使用（tool_use / tool_result）変換
 * - JSON 強制モード
 */

import http from "node:http";

const OLLAMA_BASE  = process.env.OLLAMA_API   || "https://exbridge.ddns.net/api";
const DEFAULT_MODEL = process.env.OLLAMA_MODEL || "gemma4:e4b";
const PORT  = parseInt(process.env.PROXY_PORT || "8098", 10);
const HOST  = process.env.HOST || "0.0.0.0";
const DEBUG = process.env.DEBUG === "1";

function log(...args) { if (DEBUG) console.log("[proxy]", ...args); }

function resolveModel(_claudeModel) { return DEFAULT_MODEL; }

// ─── HTTP helpers ──────────────────────────────────────────────────────────

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    req.on("data", (c) => chunks.push(c));
    req.on("end", () => resolve(Buffer.concat(chunks).toString("utf8")));
    req.on("error", reject);
  });
}

function jsonRes(res, status, data) {
  const body = JSON.stringify(data);
  res.writeHead(status, { "Content-Type": "application/json", "Access-Control-Allow-Origin": "*" });
  res.end(body);
}

// ─── Content extraction ────────────────────────────────────────────────────

function extractText(content) {
  if (!content) return "";
  if (typeof content === "string") return content;
  if (Array.isArray(content)) {
    return content.filter((b) => b.type === "text").map((b) => b.text || "").join("\n");
  }
  return String(content);
}

// ─── Tool format conversion: Anthropic → Ollama ───────────────────────────

function anthropicToolsToOllama(tools) {
  if (!tools || !tools.length) return undefined;
  return tools.map((t) => ({
    type: "function",
    function: {
      name: t.name,
      description: t.description || "",
      parameters: t.input_schema || { type: "object", properties: {} },
    },
  }));
}

// ─── Message conversion: Anthropic → Ollama ───────────────────────────────

function convertMessages(messages) {
  const out = [];
  for (const msg of messages) {
    const { role, content } = msg;

    // assistant with tool_use blocks
    if (role === "assistant" && Array.isArray(content)) {
      const textParts = content.filter((b) => b.type === "text").map((b) => b.text).join("\n");
      const toolCalls = content
        .filter((b) => b.type === "tool_use")
        .map((b) => ({
          id: b.id || `call_${Date.now()}`,
          type: "function",
          function: { name: b.name, arguments: b.input ?? {} },
        }));
      if (toolCalls.length > 0) {
        out.push({ role: "assistant", content: textParts || "", tool_calls: toolCalls });
        continue;
      }
    }

    // user with tool_result blocks
    if (role === "user" && Array.isArray(content)) {
      const toolResults = content.filter((b) => b.type === "tool_result");
      if (toolResults.length > 0) {
        for (const tr of toolResults) {
          const resultContent = Array.isArray(tr.content)
            ? tr.content.filter((b) => b.type === "text").map((b) => b.text).join("\n")
            : (tr.content || "");
          out.push({ role: "tool", content: resultContent, tool_call_id: tr.tool_use_id });
        }
        // remaining non-tool content
        const text = content.filter((b) => b.type !== "tool_result").map((b) => extractText(b)).join("\n");
        if (text.trim()) out.push({ role: "user", content: text });
        continue;
      }
    }

    out.push({ role, content: extractText(content) });
  }
  return out;
}

// ─── Response conversion: Ollama → Anthropic ──────────────────────────────

function ollamaToAnthropic(ollama, claudeModel) {
  const msg = ollama.message || {};
  const toolCalls = msg.tool_calls || [];

  const content = [];
  if (msg.content && msg.content.trim()) {
    content.push({ type: "text", text: msg.content });
  }
  for (const tc of toolCalls) {
    const fn = tc.function || {};
    content.push({
      type: "tool_use",
      id: tc.id || `toolu_${Date.now()}_${Math.random().toString(36).slice(2)}`,
      name: fn.name || "",
      input: typeof fn.arguments === "string" ? JSON.parse(fn.arguments) : (fn.arguments ?? {}),
    });
  }

  return {
    id: `msg_ollama_${Date.now()}`,
    type: "message",
    role: "assistant",
    content: content.length ? content : [{ type: "text", text: "" }],
    model: claudeModel || DEFAULT_MODEL,
    stop_reason: toolCalls.length > 0 ? "tool_use" : "end_turn",
    stop_sequence: null,
    usage: {
      input_tokens:  ollama.prompt_eval_count || 0,
      output_tokens: ollama.eval_count        || 0,
    },
  };
}

// ─── Main handler ──────────────────────────────────────────────────────────

async function handleMessages(body) {
  const { model, messages = [], system, tools, max_tokens } = body;

  const systemText = system ? extractText(system) : "";
  const hasTools   = tools && tools.length > 0;

  // システムプロンプト構築
  let systemContent = systemText;
  if (!hasTools) {
    // ツールなしの場合は JSON 出力を強制
    systemContent += systemContent
      ? "\n\nCRITICAL: Output valid JSON only. No markdown, no prose. Raw JSON matching the requested schema."
      : "Output valid JSON only. No markdown, no prose.";
  }

  const ollamaMessages = [{ role: "system", content: systemContent }];
  ollamaMessages.push(...convertMessages(messages));

  const ollamaTools = anthropicToolsToOllama(tools);

  const payload = {
    model:    resolveModel(model),
    messages: ollamaMessages,
    stream:   false,
    ...(ollamaTools ? { tools: ollamaTools } : { format: "json" }),
    options: {
      num_ctx:     16384,
      temperature: hasTools ? 0.2 : 0.1,
      top_k:   40,
      top_p:   0.9,
      ...(max_tokens ? { num_predict: max_tokens } : {}),
    },
  };

  // 常にリクエストをファイルに記録（デバッグ用）
  const fs = await import("node:fs");
  const reqLog = { ts: new Date().toISOString(), tools: tools?.map(t=>t.name), msgs: messages?.length, system: systemText?.slice(0,200) };
  fs.appendFileSync("/tmp/shannon-proxy.log", JSON.stringify(reqLog) + "\n");
  log("→ Ollama", JSON.stringify(payload).slice(0, 300));

  const resp = await fetch(`${OLLAMA_BASE}/chat`, {
    method:  "POST",
    headers: { "Content-Type": "application/json" },
    body:    JSON.stringify(payload),
    signal:  AbortSignal.timeout(300_000),
  });

  if (!resp.ok) {
    const err = await resp.text().catch(() => resp.statusText);
    throw new Error(`Ollama error ${resp.status}: ${err}`);
  }

  const ollama = await resp.json();
  log("← Ollama", JSON.stringify(ollama).slice(0, 300));

  return ollamaToAnthropic(ollama, model);
}

// ─── HTTP server ───────────────────────────────────────────────────────────

async function handle(req, res) {
  const url = new URL(req.url || "/", "http://localhost");

  if (req.method === "OPTIONS") {
    res.writeHead(204, { "Access-Control-Allow-Origin": "*", "Access-Control-Allow-Headers": "*" });
    return res.end();
  }

  if (req.method === "GET" && url.pathname === "/health") {
    return jsonRes(res, 200, { ok: true, service: "anthropic-ollama-proxy", model: DEFAULT_MODEL });
  }

  if (req.method === "GET" && url.pathname === "/v1/models") {
    return jsonRes(res, 200, {
      data: [{ id: DEFAULT_MODEL, object: "model", created: 0, owned_by: "ollama" }],
    });
  }

  if (req.method === "POST" && url.pathname === "/v1/messages") {
    const raw  = await readBody(req);
    const body = JSON.parse(raw);
    log("Anthropic request tools:", body.tools?.length ?? 0, "messages:", body.messages?.length ?? 0);
    const result = await handleMessages(body);
    return jsonRes(res, 200, result);
  }

  jsonRes(res, 404, { error: { type: "not_found", message: "Not found" } });
}

http
  .createServer((req, res) => {
    handle(req, res).catch((err) => {
      console.error("[proxy error]", err.message);
      jsonRes(res, 500, { error: { type: "server_error", message: err.message } });
    });
  })
  .listen(PORT, HOST, () => {
    console.log(`Anthropic→Ollama proxy  http://${HOST}:${PORT}  model=${DEFAULT_MODEL}`);
    console.log(`Ollama: ${OLLAMA_BASE}`);
  });
