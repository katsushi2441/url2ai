import http from "node:http";
import { spawn } from "node:child_process";
import { promises as fs } from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { Marp } from "@marp-team/marp-core";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const HOST = process.env.HOST || "0.0.0.0";
const PORT = Number.parseInt(process.env.PORT || "8022", 10);
const MAX_BODY_BYTES = Number.parseInt(process.env.MAX_BODY_BYTES || `${4 * 1024 * 1024}`, 10);
const THEME_PATH = path.join(__dirname, "exbridge.css");
const MARP_BIN = path.join(__dirname, "node_modules", ".bin", "marp");
const CHROME_PATH = process.env.CHROME_PATH || "/usr/bin/google-chrome";

// ══════════════════════════════════════════════════════════════════
//  HTTP helpers
// ══════════════════════════════════════════════════════════════════

function json(res, status, data) {
  res.writeHead(status, { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-store", "Access-Control-Allow-Origin": "*" });
  res.end(JSON.stringify(data, null, 2));
}
function binary(res, status, buffer, contentType, filename) {
  res.writeHead(status, { "Content-Type": contentType, "Content-Length": buffer.length, "Content-Disposition": `attachment; filename="${filename}"`, "Cache-Control": "no-store", "Access-Control-Allow-Origin": "*" });
  res.end(buffer);
}
function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = []; let size = 0;
    req.on("data", (chunk) => { size += chunk.length; if (size > MAX_BODY_BYTES) { reject(new Error("Request body too large")); req.destroy(); return; } chunks.push(chunk); });
    req.on("end", () => resolve(Buffer.concat(chunks).toString("utf8")));
    req.on("error", reject);
  });
}
async function readJson(req) { const b = await readBody(req); if (!b.trim()) return {}; return JSON.parse(b); }

function normalizePost(input) {
  const post = input && typeof input === "object" ? input : {};
  const slides = Array.isArray(post.slides) ? post.slides : [];
  return {
    title: String(post.title || "USlideBlog").trim(),
    description: String(post.description || "").trim(),
    source_url: String(post.source_url || "").trim(),
    tags: Array.isArray(post.tags) ? post.tags.map((t) => String(t).trim()).filter(Boolean) : [],
    slides: slides.map((s) => ({ title: String(s?.title || "").trim(), body: String(s?.body || "").trim(), note: String(s?.note || "").trim(), layout: String(s?.layout || "points").trim() })).filter((s) => s.title || s.body),
  };
}

// ══════════════════════════════════════════════════════════════════
//  Markdown 生成
// ══════════════════════════════════════════════════════════════════

function escapeMd(v) { return String(v || "").replace(/\r\n/g, "\n").trim(); }

/**
 * GFMテーブルの区切り行を自動補完する。
 * データには `| a | b |` の行だけで `|---|---|` が無いため、
 * 連続するテーブル行ブロックの先頭行の直後に区切り行を挿入する。
 */
function normalizeTables(body) {
  const lines = body.split("\n");
  const out = [];
  let inTable = false;
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const isRow = /^\s*\|.*\|\s*$/.test(line);
    const isDelim = /^\s*\|[\s:|-]+\|\s*$/.test(line);
    if (isRow && !inTable && !isDelim) {
      // テーブルブロックの開始（ヘッダー行）
      out.push(line);
      const cols = line.split("|").filter((_, idx, arr) => idx > 0 && idx < arr.length - 1).length || (line.match(/\|/g).length - 1);
      // 次行が既に区切り行ならそのまま、なければ挿入
      const next = lines[i + 1] || "";
      if (!/^\s*\|[\s:|-]+\|\s*$/.test(next)) {
        out.push("|" + Array(cols).fill("---").join("|") + "|");
      }
      inTable = true;
    } else if (isRow) {
      out.push(line);
    } else {
      inTable = false;
      out.push(line);
    }
  }
  return out.join("\n");
}

function getMarpClass(slide, index, total) {
  if (index === 0) return "lead";
  if (index === total - 1) return "chapter";
  if (slide.layout === "cover") return "chapter";
  return null;
}

function slideMarkdown(post) {
  const lines = ["---", "marp: true", "theme: exbridge", "paginate: true", `title: ${post.title}`, "---", ""];
  const total = post.slides.length + (post.source_url ? 1 : 0);
  post.slides.forEach((slide, i) => {
    if (i > 0) lines.push("---", "");
    const cls = getMarpClass(slide, i, total);
    if (cls) lines.push(`<!-- _class: ${cls} -->`, "");
    lines.push(`# ${escapeMd(slide.title)}`, "");
    if (slide.body) lines.push(normalizeTables(escapeMd(slide.body)), "");
    if (slide.note) lines.push("<!--", escapeMd(slide.note), "-->", "");
  });
  if (post.source_url) lines.push("---", "", "# 参考リンク", "", post.source_url, "");
  return lines.join("\n");
}

// ══════════════════════════════════════════════════════════════════
//  Marp HTML（WEB表示用）
// ══════════════════════════════════════════════════════════════════

let THEME_CSS = null;
async function loadTheme() { if (THEME_CSS === null) THEME_CSS = await fs.readFile(THEME_PATH, "utf8"); return THEME_CSS; }

async function marpHtml(markdown) {
  const marp = new Marp({ html: true });
  marp.themeSet.add(await loadTheme());
  const { html, css } = marp.render(markdown);
  return `<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style></head><body>${html}</body></html>`;
}

// ══════════════════════════════════════════════════════════════════
//  PPTX — marp-cli でHTML/CSSを画像化して埋め込む
//  → WEB表示と完全一致する高品質スライド
// ══════════════════════════════════════════════════════════════════

async function pptxBuffer(markdown) {
  const dir = await fs.mkdtemp(path.join(os.tmpdir(), "uslide-"));
  const mdPath = path.join(dir, "slides.md");
  const outPath = path.join(dir, "slides.pptx");
  try {
    await fs.writeFile(mdPath, markdown, "utf8");
    await runMarp([mdPath, "--theme", THEME_PATH, "--pptx", "--allow-local-files", "--no-stdin", "-o", outPath]);
    return await fs.readFile(outPath);
  } finally {
    fs.rm(dir, { recursive: true, force: true }).catch(() => {});
  }
}

function runMarp(args) {
  return new Promise((resolve, reject) => {
    const child = spawn(MARP_BIN, args, {
      env: { ...process.env, CHROME_PATH, CHROME_NO_SANDBOX: "true" },
      stdio: ["ignore", "pipe", "pipe"],
    });
    let stderr = "";
    child.stderr.on("data", (d) => { stderr += d.toString(); });
    child.on("error", reject);
    child.on("close", (code) => {
      if (code === 0) resolve();
      else reject(new Error(`marp-cli exited ${code}: ${stderr.slice(-500)}`));
    });
  });
}

// ══════════════════════════════════════════════════════════════════
//  HTTP server
// ══════════════════════════════════════════════════════════════════

const server = http.createServer(async (req, res) => {
  try {
    if (req.method === "OPTIONS") {
      res.writeHead(204, { "Access-Control-Allow-Origin": "*", "Access-Control-Allow-Methods": "GET,POST,OPTIONS", "Access-Control-Allow-Headers": "Content-Type" });
      res.end(); return;
    }
    const url = new URL(req.url, `http://${req.headers.host || "localhost"}`);
    if (req.method === "GET" && url.pathname === "/health") {
      json(res, 200, { ok: true, service: "uslideblog", renderer: ["Marp", "marp-cli/PPTX"] }); return;
    }
    if (req.method !== "POST") { json(res, 404, { ok: false, error: "Not found" }); return; }

    const post = normalizePost(await readJson(req));
    const markdown = slideMarkdown(post);

    if (url.pathname === "/api/uslideblog/markdown") {
      json(res, 200, { ok: true, markdown }); return;
    }
    if (url.pathname === "/api/uslideblog/marp-html") {
      json(res, 200, { ok: true, markdown, html: await marpHtml(markdown) }); return;
    }
    if (url.pathname === "/api/uslideblog/pptx") {
      binary(res, 200, await pptxBuffer(markdown), "application/vnd.openxmlformats-officedocument.presentationml.presentation", "uslideblog.pptx"); return;
    }
    json(res, 404, { ok: false, error: "Not found" });
  } catch (error) {
    json(res, 500, { ok: false, error: error.message || String(error) });
  }
});

server.listen(PORT, HOST, () => console.log(`USlideBlog renderer listening on http://${HOST}:${PORT}`));
