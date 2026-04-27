import http from "node:http";
import { Buffer } from "node:buffer";
import { removeBackground } from "@imgly/background-removal-node";
import sharp from "sharp";
import { analyzeUrl, browseUrl, scanUrl } from "./url-agent.js";

const HOST = process.env.HOST || "0.0.0.0";
const PORT = Number.parseInt(process.env.PORT || "8015", 10);
const MAX_BODY_BYTES = Number.parseInt(process.env.MAX_BODY_BYTES || `${12 * 1024 * 1024}`, 10);
const DEFAULT_MODEL = process.env.BG_REMOVAL_MODEL || "medium";
const OSS_NAME = "imgly/background-removal-js";
const OSS_LICENSE = "AGPL-3.0";

function json(res, status, data) {
  const body = JSON.stringify(data, null, 2);
  res.writeHead(status, {
    "Content-Type": "application/json; charset=utf-8",
    "Cache-Control": "no-store",
  });
  res.end(body);
}

function image(res, status, buffer, contentType = "image/png") {
  res.writeHead(status, {
    "Content-Type": contentType,
    "Cache-Control": "no-store",
    "Content-Length": buffer.length,
  });
  res.end(buffer);
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on("data", (chunk) => {
      size += chunk.length;
      if (size > MAX_BODY_BYTES) {
        reject(new Error("Request body too large"));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on("end", () => resolve(Buffer.concat(chunks).toString("utf8")));
    req.on("error", reject);
  });
}

async function readJson(req) {
  const body = await readBody(req);
  if (!body.trim()) return {};
  return JSON.parse(body);
}

function parseDataUrl(value) {
  const match = String(value || "").match(/^data:([^;,]+)?(;base64)?,(.*)$/);
  if (!match) return null;
  return Buffer.from(match[3], match[2] ? "base64" : "utf8");
}

function decodeBase64(value) {
  const dataUrl = parseDataUrl(value);
  if (dataUrl) return dataUrl;
  return Buffer.from(String(value || ""), "base64");
}

async function fetchBuffer(url, label = "image_url") {
  let parsed;
  try {
    parsed = new URL(url);
  } catch {
    throw new Error(`${label} must be a valid absolute URL`);
  }
  if (!["http:", "https:"].includes(parsed.protocol)) {
    throw new Error(`${label} must use http or https`);
  }
  const response = await fetch(parsed, {
    headers: { "User-Agent": "OSS2API-background-removal/0.1" },
  });
  if (!response.ok) {
    throw new Error(`Failed to download ${label} (${response.status})`);
  }
  return Buffer.from(await response.arrayBuffer());
}

async function resolveImageInput(body) {
  if (body.image_base64) return decodeBase64(body.image_base64);
  if (body.image_url) return fetchBuffer(body.image_url);
  throw new Error("Provide image_url or image_base64");
}

async function resolveBackgroundImage(body) {
  if (body.background_image_base64) return decodeBase64(body.background_image_base64);
  if (body.background_image_url) return fetchBuffer(body.background_image_url, "background_image_url");
  return null;
}

function normalizeMode(mode) {
  const normalized = String(mode || "remove").trim().toLowerCase();
  if (["remove", "replace", "blur"].includes(normalized)) return normalized;
  throw new Error("mode must be one of: remove, replace, blur");
}

function normalizeOutputFormat(value) {
  const format = String(value || "png").trim().toLowerCase();
  if (["png", "webp", "jpeg", "jpg"].includes(format)) return format === "jpg" ? "jpeg" : format;
  throw new Error("output_format must be png, webp, or jpeg");
}

async function encode(buffer, format, quality) {
  const pipeline = sharp(buffer);
  if (format === "webp") return pipeline.webp({ quality }).toBuffer();
  if (format === "jpeg") return pipeline.jpeg({ quality }).toBuffer();
  return pipeline.png().toBuffer();
}

async function removeBackgroundPng(inputBuffer, body) {
  const metadata = await sharp(inputBuffer).metadata();
  const mime = metadata.format ? `image/${metadata.format === "jpg" ? "jpeg" : metadata.format}` : "image/png";
  const inputBlob = new Blob([inputBuffer], { type: mime });
  const blob = await removeBackground(inputBlob, {
    model: body.model || DEFAULT_MODEL,
    output: {
      format: "image/png",
      type: "foreground",
      quality: Number(body.quality || 0.9),
    },
  });
  return Buffer.from(await blob.arrayBuffer());
}

async function replaceBackground(foregroundPng, inputBuffer, body) {
  const meta = await sharp(inputBuffer).metadata();
  const width = meta.width || 1024;
  const height = meta.height || 1024;
  const bgImage = await resolveBackgroundImage(body);

  let background;
  if (bgImage) {
    background = await sharp(bgImage).resize(width, height, { fit: "cover" }).png().toBuffer();
  } else {
    background = await sharp({
      create: {
        width,
        height,
        channels: 4,
        background: body.background_color || "#ffffff",
      },
    }).png().toBuffer();
  }

  return sharp(background).composite([{ input: foregroundPng }]).png().toBuffer();
}

async function blurBackground(foregroundPng, inputBuffer, body) {
  const meta = await sharp(inputBuffer).metadata();
  const width = meta.width || 1024;
  const height = meta.height || 1024;
  const sigma = Math.max(1, Math.min(Number(body.blur_sigma || 18), 80));
  const background = await sharp(inputBuffer)
    .resize(width, height, { fit: "cover" })
    .blur(sigma)
    .png()
    .toBuffer();
  return sharp(background).composite([{ input: foregroundPng }]).png().toBuffer();
}

async function processImage(body) {
  const mode = normalizeMode(body.mode);
  const outputFormat = normalizeOutputFormat(body.output_format);
  const quality = Math.max(1, Math.min(Number(body.output_quality || 90), 100));
  const input = await resolveImageInput(body);
  const foreground = await removeBackgroundPng(input, body);

  let output = foreground;
  if (mode === "replace") output = await replaceBackground(foreground, input, body);
  if (mode === "blur") output = await blurBackground(foreground, input, body);

  const encoded = await encode(output, outputFormat, quality);
  return {
    mode,
    outputFormat,
    buffer: encoded,
    contentType: `image/${outputFormat}`,
  };
}

function manifest() {
  return {
    name: "oss2api-gateway",
    family: "OSS2API",
    description: "OSS2API gateway: background removal (imgly) + URL agent (extract / browse / scan).",
    skills: {
      "background-removal": {
        oss: { name: OSS_NAME, package: "@imgly/background-removal-node", license: OSS_LICENSE },
        endpoints: { remove: "/image/remove-background", legacy: "/run" },
        capabilities: ["background.remove", "background.replace", "background.blur"],
      },
      "url-agent": {
        oss: ["cheerio", "playwright"],
        endpoints: {
          analyze: "/url/analyze",
          browse: "/url/browse",
          scan: "/url/scan",
        },
        capabilities: ["url.extract", "url.screenshot", "url.security_scan"],
      },
    },
    endpoints: {
      health: "/health",
      manifest: "/.well-known/oss2api.json",
      openapi: "/openapi.json",
    },
  };
}

function openapi() {
  return {
    openapi: "3.1.0",
    info: {
      title: "OSS2API Background Removal",
      version: "0.1.0",
      description: "API gateway for background-removal-js.",
      license: { name: OSS_LICENSE, url: "https://github.com/imgly/background-removal-js" },
    },
    paths: {
      "/health": { get: { responses: { "200": { description: "OK" } } } },
      "/healthz": { get: { responses: { "200": { description: "OK" } } } },
      "/.well-known/oss2api.json": { get: { responses: { "200": { description: "Manifest" } } } },
      "/image/remove-background": {
        post: {
          summary: "Remove, replace, or blur image background",
          requestBody: {
            required: true,
            content: {
              "application/json": {
                schema: {
                  type: "object",
                  properties: {
                    image_url: { type: "string", description: "URL of the source image" },
                    image_base64: { type: "string", description: "Base64-encoded source image" },
                    mode: { enum: ["remove", "replace", "blur"], default: "remove" },
                    background_color: { type: "string", description: "CSS color for replace mode" },
                    background_image_url: { type: "string" },
                    background_image_base64: { type: "string" },
                    blur_sigma: { type: "number", default: 10 },
                    output_format: { enum: ["png", "webp", "jpeg"], default: "png" },
                    response: { enum: ["binary", "json"], default: "binary" },
                  },
                },
              },
            },
          },
          responses: {
            "200": { description: "Processed image (binary or base64 JSON)" },
            "400": { description: "Invalid request" },
            "500": { description: "Processing error" },
          },
        },
      },
      "/run": {
        post: {
          summary: "Background removal (legacy alias for /image/remove-background)",
          requestBody: { "$ref": "#/paths/~1image~1remove-background/post/requestBody" },
          responses: { "200": { description: "Processed image" } },
        },
      },
    },
  };
}

async function handle(req, res) {
  const url = new URL(req.url || "/", `http://${req.headers.host || "localhost"}`);
  if (req.method === "GET" && ["/health", "/healthz"].includes(url.pathname)) {
    return json(res, 200, { ok: true, service: "oss2api", skills: ["background-removal", "url-agent"] });
  }
  if (req.method === "GET" && url.pathname === "/.well-known/oss2api.json") {
    return json(res, 200, manifest());
  }
  if (req.method === "GET" && url.pathname === "/openapi.json") {
    return json(res, 200, openapi());
  }
  // ── URL agent routes ──────────────────────────────────────────────────
  if (req.method === "POST" && url.pathname === "/url/analyze") {
    try {
      const body = await readJson(req);
      const result = await analyzeUrl(body);
      return json(res, 200, result);
    } catch (error) {
      return json(res, 400, { ok: false, error: error.message || String(error) });
    }
  }

  if (req.method === "POST" && url.pathname === "/url/browse") {
    try {
      const body = await readJson(req);
      const result = await browseUrl(body);
      return json(res, 200, result);
    } catch (error) {
      return json(res, 400, { ok: false, error: error.message || String(error) });
    }
  }

  if (req.method === "POST" && url.pathname === "/url/scan") {
    try {
      const body = await readJson(req);
      const result = await scanUrl(body);
      return json(res, 200, result);
    } catch (error) {
      return json(res, 400, { ok: false, error: error.message || String(error) });
    }
  }

  // ── Image processing ──────────────────────────────────────────────────
  if (req.method === "POST" && ["/image/remove-background", "/run"].includes(url.pathname)) {
    try {
      const body = await readJson(req);
      const result = await processImage(body);
      if (body.response === "json") {
        return json(res, 200, {
          ok: true,
          mode: result.mode,
          content_type: result.contentType,
          image_base64: result.buffer.toString("base64"),
        });
      }
      return image(res, 200, result.buffer, result.contentType);
    } catch (error) {
      return json(res, 400, { ok: false, error: error.message || String(error) });
    }
  }

  return json(res, 404, { error: "Not found" });
}

http.createServer((req, res) => {
  handle(req, res).catch((error) => {
    json(res, 500, { ok: false, error: error.message || String(error) });
  });
}).listen(PORT, HOST, () => {
  console.log(`OSS2API gateway listening on http://${HOST}:${PORT}`);
});
