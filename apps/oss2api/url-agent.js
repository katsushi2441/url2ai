/**
 * url-agent.js — URL analysis skills for OSS2API gateway
 *
 * Skills:
 *   POST /url/analyze  — fetch + cheerio structured extraction
 *   POST /url/browse   — Playwright screenshot / dynamic extract
 *   POST /url/scan     — HTTP security headers + Shannon CLI (if available)
 */

import { exec } from "node:child_process";
import { promisify } from "node:util";
import * as cheerio from "cheerio";

const execAsync = promisify(exec);
const UA = "Mozilla/5.0 (compatible; OSS2API-url-agent/0.1)";

// ─── helpers ──────────────────────────────────────────────────────────────

function validateUrl(url) {
  if (!url) throw new Error("url is required");
  let parsed;
  try { parsed = new URL(url); } catch { throw new Error("url must be a valid absolute URL"); }
  if (!["http:", "https:"].includes(parsed.protocol)) {
    throw new Error("url must use http or https");
  }
  return parsed;
}

function htmlToText(html) {
  const $ = cheerio.load(html);
  $("script, style, nav, footer, header, aside, noscript").remove();
  return $("body").text().replace(/\s+/g, " ").trim();
}

function htmlToMarkdown(html) {
  const $ = cheerio.load(html);
  $("script, style, nav, footer, header, aside, noscript").remove();

  const parts = [];
  $("h1,h2,h3,h4,p,li,blockquote,pre,code").each((_, el) => {
    const tag = el.tagName.toLowerCase();
    const text = $(el).text().replace(/\s+/g, " ").trim();
    if (!text) return;
    if (tag === "h1") parts.push(`# ${text}`);
    else if (tag === "h2") parts.push(`## ${text}`);
    else if (tag === "h3") parts.push(`### ${text}`);
    else if (tag === "h4") parts.push(`#### ${text}`);
    else if (tag === "li") parts.push(`- ${text}`);
    else if (tag === "blockquote") parts.push(`> ${text}`);
    else if (tag === "pre" || tag === "code") parts.push(`\`\`\`\n${text}\n\`\`\``);
    else parts.push(text);
  });
  return parts.join("\n\n");
}

// ─── /url/analyze ─────────────────────────────────────────────────────────

export async function analyzeUrl(body) {
  const { url, format = "json", depth = "basic" } = body;
  validateUrl(url);

  const resp = await fetch(url, {
    headers: { "User-Agent": UA },
    redirect: "follow",
    signal: AbortSignal.timeout(20000),
  });

  if (!resp.ok) throw new Error(`HTTP ${resp.status} from ${url}`);

  const contentType = resp.headers.get("content-type") || "";
  const html = await resp.text();
  const $ = cheerio.load(html);

  const title =
    $("title").first().text().trim() ||
    $('meta[property="og:title"]').attr("content") ||
    $("h1").first().text().trim() ||
    "";

  const description =
    $('meta[name="description"]').attr("content") ||
    $('meta[property="og:description"]').attr("content") ||
    "";

  if (format === "markdown") {
    return { ok: true, url, title, description, markdown: htmlToMarkdown(html) };
  }

  const headings = [];
  $("h1,h2,h3").each((_, el) => {
    const t = $(el).text().trim();
    if (t) headings.push({ tag: el.tagName.toLowerCase(), text: t });
  });

  const links = [];
  $("a[href]").each((_, el) => {
    const href = $(el).attr("href") || "";
    const abs = (() => {
      try { return new URL(href, url).href; } catch { return null; }
    })();
    if (abs && (abs.startsWith("http://") || abs.startsWith("https://"))) {
      links.push({ text: $(el).text().trim().slice(0, 80), href: abs });
    }
  });

  const text = htmlToText(html);

  const entities = [];
  const emailRe = /\b[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}\b/g;
  const urlRe = /https?:\/\/[^\s<>"{}|\\^`[\]]+/g;
  (text.match(emailRe) || []).slice(0, 5).forEach((v) => entities.push({ type: "email", value: v }));
  (text.match(urlRe) || []).slice(0, 5).forEach((v) => entities.push({ type: "url", value: v }));

  const result = {
    ok: true,
    url,
    title,
    description,
    headings: headings.slice(0, 30),
    links: links.slice(0, 50),
    entities,
    summary: text.slice(0, 600),
  };

  if (depth === "full") {
    result.content = text.slice(0, 8000);
  }

  return result;
}

// ─── /url/browse ──────────────────────────────────────────────────────────

export async function browseUrl(body) {
  const { url, action = "screenshot", wait_until = "networkidle" } = body;
  validateUrl(url);

  let chromium;
  try {
    ({ chromium } = await import("playwright"));
  } catch {
    throw new Error("playwright is not installed. Run: npm install playwright && npx playwright install chromium");
  }

  const browser = await chromium.launch({
    args: ["--no-sandbox", "--disable-setuid-sandbox", "--disable-dev-shm-usage"],
  });

  try {
    const page = await browser.newPage({
      userAgent: UA,
      viewport: { width: 1280, height: 800 },
    });

    await page.goto(url, {
      waitUntil: wait_until === "load" ? "load" : "networkidle",
      timeout: 30000,
    });

    if (action === "screenshot") {
      const buf = await page.screenshot({ type: "png", fullPage: false });
      return {
        ok: true,
        url,
        action: "screenshot",
        title: await page.title(),
        screenshot: buf.toString("base64"),
        content_type: "image/png",
      };
    }

    if (action === "extract") {
      const title = await page.title();
      const content = await page.evaluate(() => document.body.innerText || "");
      const links = await page.evaluate(() =>
        Array.from(document.querySelectorAll("a[href]"))
          .map((a) => ({ text: a.textContent.trim().slice(0, 80), href: a.href }))
          .filter((l) => l.href.startsWith("http"))
          .slice(0, 50)
      );
      return { ok: true, url, action: "extract", title, content: content.slice(0, 8000), links };
    }

    throw new Error(`Unknown action: ${action}. Use screenshot or extract.`);
  } finally {
    await browser.close();
  }
}

// ─── /url/scan (Shannon-like multi-phase) ─────────────────────────────────

const OLLAMA_API = process.env.OLLAMA_API || "https://exbridge.ddns.net/api";
const OLLAMA_MODEL = process.env.OLLAMA_MODEL || "gemma4:e4b";

async function ollamaChat(userPrompt) {
  try {
    const resp = await fetch(`${OLLAMA_API}/chat`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        model: OLLAMA_MODEL,
        stream: false,
        format: "json",
        messages: [
          { role: "system", content: "You are a web security expert. Output only valid JSON, no markdown." },
          { role: "user", content: userPrompt },
        ],
        options: { temperature: 0.1, num_ctx: 8192 },
      }),
      signal: AbortSignal.timeout(120000),
    });
    const data = await resp.json();
    return JSON.parse(data.message?.content || "{}");
  } catch {
    return {};
  }
}

// Phase 1: HTTP ヘッダー検査 (Shannon: pre-recon相当)
async function phaseHeaders(url) {
  const parsed = new URL(url);
  const findings = [];
  let risk = 0;
  let headers = {};

  if (parsed.protocol !== "https:") {
    findings.push({ type: "transport", severity: "high", detail: "No HTTPS — plaintext transmission risk" });
    risk += 30;
  }
  try {
    const resp = await fetch(url, { method: "HEAD", headers: { "User-Agent": UA }, redirect: "follow", signal: AbortSignal.timeout(10000) });
    headers = Object.fromEntries(resp.headers.entries());
  } catch (e) {
    findings.push({ type: "recon", severity: "info", detail: `Header fetch failed: ${e.message}` });
  }

  const checks = [
    ["content-security-policy",   "Missing Content-Security-Policy (XSS risk)",   "xss",       15],
    ["x-frame-options",           "Missing X-Frame-Options (clickjacking risk)",   "authz",     10],
    ["x-content-type-options",    "Missing X-Content-Type-Options",                "injection",  5],
    ["referrer-policy",           "Missing Referrer-Policy (info leakage risk)",   "recon",      5],
    ["permissions-policy",        "Missing Permissions-Policy",                    "authz",      5],
    ["strict-transport-security", "Missing HSTS (SSL stripping risk)",             "transport", 10],
  ];
  for (const [h, msg, type, w] of checks) {
    if (!headers[h] && (h !== "strict-transport-security" || parsed.protocol === "https:")) {
      findings.push({ type, severity: w >= 10 ? "medium" : "low", detail: msg });
      risk += w;
    }
  }
  if (headers["server"])       findings.push({ type: "recon", severity: "low", detail: `Server version exposed: ${headers["server"]}` });
  if (headers["x-powered-by"]) findings.push({ type: "recon", severity: "low", detail: `X-Powered-By exposed: ${headers["x-powered-by"]}` });

  return { headers, findings, risk };
}

// Phase 2: 静的コンテンツ解析 (Shannon: recon + vuln-analysis相当)
async function phaseStaticAnalysis(url, html) {
  const $ = cheerio.load(html);
  const findings = [];
  let risk = 0;

  // XSS パターン
  const scripts = $("script:not([src])").map((_, el) => $(el).html() || "").get().join("\n");
  if (/document\.write\s*\(/.test(scripts))  { findings.push({ type: "xss",       severity: "medium",   detail: "document.write() detected (XSS sink)" }); risk += 15; }
  if (/innerHTML\s*=/.test(scripts))         { findings.push({ type: "xss",       severity: "medium",   detail: "innerHTML assignment detected (XSS sink)" }); risk += 10; }
  if (/eval\s*\(/.test(scripts))             { findings.push({ type: "injection",  severity: "high",     detail: "eval() usage detected (code injection risk)" }); risk += 20; }

  // フォーム解析 (auth / injection)
  const forms = [];
  $("form").each((_, el) => {
    const action = $(el).attr("action") || "";
    const method = ($(el).attr("method") || "GET").toUpperCase();
    const inputs = $("input", el).map((_, i) => ({ name: $(i).attr("name") || "", type: $(i).attr("type") || "text" })).get();
    forms.push({ action, method, inputs });
  });

  for (const f of forms.filter(f => f.inputs.some(i => i.type === "password"))) {
    if (f.action && !f.action.startsWith("https") && url.startsWith("https")) {
      findings.push({ type: "auth", severity: "high", detail: `Login form posts to non-HTTPS: ${f.action}` }); risk += 20;
    }
  }
  if (forms.some(f => f.inputs.some(i => ["search","q","query","keyword","s"].includes((i.name||"").toLowerCase())))) {
    findings.push({ type: "xss", severity: "info", detail: "Search input found — potential reflected XSS test point" });
  }

  // SQL エラー露出
  const bodyText = $.text().toLowerCase();
  for (const pat of ["sql syntax","mysql_fetch","ora-0","sqlite_","pg_query","syntax error near","unclosed quotation"]) {
    if (bodyText.includes(pat)) { findings.push({ type: "injection", severity: "critical", detail: `SQL error exposed: "${pat}"` }); risk += 30; break; }
  }

  // デバッグ情報
  if (/stack trace|traceback|debug=true|exception in|undefined variable/i.test(bodyText)) {
    findings.push({ type: "recon", severity: "medium", detail: "Debug/stack trace information exposed" }); risk += 10;
  }

  // SSRF: URLパラメータ
  for (const [k] of new URL(url).searchParams) {
    if (/^(url|uri|redirect|callback|next|src|href|endpoint|proxy|dest)$/i.test(k)) {
      findings.push({ type: "ssrf", severity: "medium", detail: `URL parameter "${k}" may be SSRF vector` }); risk += 15; break;
    }
  }

  // 外部スクリプト数
  const extScripts = $("script[src]").map((_, el) => $(el).attr("src") || "").get()
    .filter(s => { try { return new URL(s).hostname !== new URL(url).hostname; } catch { return false; } });
  if (extScripts.length > 5) {
    findings.push({ type: "recon", severity: "low", detail: `${extScripts.length} external scripts loaded (supply chain risk)` });
  }

  return { forms, findings, risk };
}

// Phase 3: Ollama AI分析 (Shannon: AI agent相当)
async function phaseAiAnalysis(url, html, prevFindings) {
  const $ = cheerio.load(html);
  $("script,style,nav,footer,header").remove();
  const snippet = $.text().replace(/\s+/g, " ").trim().slice(0, 2500);
  const detected = prevFindings.map(f => `[${f.type}/${f.severity}] ${f.detail}`).join("\n") || "None";

  const result = await ollamaChat(
    `Analyze this web page for security vulnerabilities.

URL: ${url}
Page content (excerpt): ${snippet}

Already detected:
${detected}

Find additional issues: SQL injection, XSS, CSRF, auth bypass, authorization flaws, SSRF, sensitive data exposure.

Respond ONLY with this JSON structure:
{
  "additional_findings": [
    {"type": "injection|xss|auth|authz|ssrf|recon", "severity": "critical|high|medium|low|info", "detail": "specific finding description"}
  ],
  "risk_adjustment": 0,
  "summary": "one-sentence overall security assessment",
  "recommendations": ["specific action 1", "specific action 2"]
}`
  );

  return {
    findings:        (result.additional_findings || []).filter(f => f.detail && f.severity),
    riskAdjustment:  Math.max(-20, Math.min(30, Number(result.risk_adjustment) || 0)),
    summary:         result.summary || "",
    recommendations: result.recommendations || [],
  };
}

export async function scanUrl(body) {
  const { url } = body;
  validateUrl(url);

  // Phase 1
  const { findings: hF, risk: hR } = await phaseHeaders(url);

  // HTML取得
  let html = "";
  try {
    const r = await fetch(url, { headers: { "User-Agent": UA }, redirect: "follow", signal: AbortSignal.timeout(15000) });
    html = await r.text();
  } catch { /* ignore */ }

  // Phase 2
  const { forms, findings: sF, risk: sR } = html ? await phaseStaticAnalysis(url, html) : { forms: [], findings: [], risk: 0 };

  // Phase 3
  const ai = html ? await phaseAiAnalysis(url, html, [...hF, ...sF]) : { findings: [], riskAdjustment: 0, summary: "", recommendations: [] };

  const all  = [...hF, ...sF, ...ai.findings];
  const risk = Math.min(100, hR + sR + ai.riskAdjustment);
  const bySev = (s) => all.filter(f => f.severity === s).map(f => `[${f.type}] ${f.detail}`);

  return {
    ok: true,
    url,
    scanner: "shannon-like (headers + static + ollama-ai)",
    risk_score: risk,
    summary: ai.summary || `${all.length} issue(s) detected across 3 analysis phases`,
    findings: { critical: bySev("critical"), high: bySev("high"), medium: bySev("medium"), low: bySev("low"), info: bySev("info") },
    findings_flat: all.map(f => `[${f.severity.toUpperCase()}/${f.type}] ${f.detail}`),
    forms_detected: forms.length,
    actions: ai.recommendations.length ? ai.recommendations
      : risk > 50 ? ["Conduct full penetration test", "Harden HTTP security headers"]
      : risk > 20 ? ["Review HTTP headers", "Validate all user inputs"]
      : ["No critical issues — maintain regular security reviews"],
    phases: ["1:header-analysis", "2:static-content-analysis", "3:ollama-ai-analysis"],
  };
}
