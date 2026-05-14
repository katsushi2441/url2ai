import http from "node:http";
import { Marp } from "@marp-team/marp-core";
import pptxgen from "pptxgenjs";

const HOST = process.env.HOST || "0.0.0.0";
const PORT = Number.parseInt(process.env.PORT || "8022", 10);
const MAX_BODY_BYTES = Number.parseInt(process.env.MAX_BODY_BYTES || `${4 * 1024 * 1024}`, 10);

function json(res, status, data) {
  res.writeHead(status, {
    "Content-Type": "application/json; charset=utf-8",
    "Cache-Control": "no-store",
    "Access-Control-Allow-Origin": "*",
  });
  res.end(JSON.stringify(data, null, 2));
}

function binary(res, status, buffer, contentType, filename) {
  res.writeHead(status, {
    "Content-Type": contentType,
    "Content-Length": buffer.length,
    "Content-Disposition": `attachment; filename="${filename}"`,
    "Cache-Control": "no-store",
    "Access-Control-Allow-Origin": "*",
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

function escapeMd(value) {
  return String(value || "").replace(/\r\n/g, "\n").trim();
}

function normalizePost(input) {
  const post = input && typeof input === "object" ? input : {};
  const slides = Array.isArray(post.slides) ? post.slides : [];
  return {
    title: String(post.title || "USlideBlog").trim(),
    description: String(post.description || "").trim(),
    source_url: String(post.source_url || "").trim(),
    tags: Array.isArray(post.tags) ? post.tags.map((t) => String(t).trim()).filter(Boolean) : [],
    slides: slides
      .map((slide) => ({
        title: String(slide?.title || "").trim(),
        body: String(slide?.body || "").trim(),
        note: String(slide?.note || "").trim(),
        layout: String(slide?.layout || "points").trim(),
      }))
      .filter((slide) => slide.title || slide.body),
  };
}

function slideMarkdown(post) {
  const lines = [
    "---",
    "marp: true",
    "theme: default",
    "paginate: true",
    `title: ${post.title}`,
    "---",
    "",
  ];

  post.slides.forEach((slide, index) => {
    if (index > 0) lines.push("---", "");
    if (slide.layout === "cover" || index === 0) {
      lines.push("<!-- _class: lead -->", "");
    }
    lines.push(`# ${escapeMd(slide.title)}`, "");
    if (slide.body) lines.push(escapeMd(slide.body), "");
    if (slide.note) lines.push("<!--", escapeMd(slide.note), "-->", "");
  });

  if (post.source_url) {
    lines.push("---", "", "# 参考リンク", "", post.source_url, "");
  }
  return lines.join("\n");
}

function marpHtml(markdown) {
  const marp = new Marp({ html: true });
  const { html, css } = marp.render(markdown);
  return `<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style></head><body>${html}</body></html>`;
}

function truncate(value, max) {
  const text = String(value || "");
  return text.length > max ? `${text.slice(0, max - 1)}…` : text;
}

async function pptxBuffer(post) {
  const pptx = new pptxgen();
  pptx.layout = "LAYOUT_WIDE";
  pptx.author = "USlideBlog";
  pptx.subject = post.description || post.title;
  pptx.title = post.title;
  pptx.company = "URL2AI";
  pptx.lang = "ja-JP";
  pptx.theme = {
    headFontFace: "Aptos Display",
    bodyFontFace: "Aptos",
    lang: "ja-JP",
  };

  post.slides.forEach((slide, index) => {
    const s = pptx.addSlide();
    const cover = index === 0 || slide.layout === "cover";
    s.background = { color: cover ? "EFF6FF" : "FFFFFF" };
    s.addText(slide.title || post.title, {
      x: 0.65,
      y: cover ? 1.15 : 0.48,
      w: 11.1,
      h: cover ? 1.25 : 0.65,
      fontFace: "Aptos Display",
      fontSize: cover ? 34 : 25,
      bold: true,
      color: "172033",
      fit: "shrink",
      margin: 0.03,
      breakLine: false,
    });
    if (slide.body) {
      s.addText(truncate(slide.body, 680), {
        x: 0.75,
        y: cover ? 2.65 : 1.45,
        w: 10.9,
        h: cover ? 2.5 : 4.4,
        fontSize: cover ? 20 : 17,
        color: "334155",
        breakLine: false,
        fit: "shrink",
        valign: "mid",
        margin: 0.08,
      });
    }
    if (slide.note) {
      s.addNotes(slide.note);
    }
  });

  return Buffer.from(await pptx.write({ outputType: "nodebuffer" }));
}

const server = http.createServer(async (req, res) => {
  try {
    if (req.method === "OPTIONS") {
      res.writeHead(204, {
        "Access-Control-Allow-Origin": "*",
        "Access-Control-Allow-Methods": "GET,POST,OPTIONS",
        "Access-Control-Allow-Headers": "Content-Type",
      });
      res.end();
      return;
    }

    const url = new URL(req.url, `http://${req.headers.host || "localhost"}`);

    if (req.method === "GET" && url.pathname === "/health") {
      json(res, 200, { ok: true, service: "uslideblog", renderer: ["Marp", "PptxGenJS"] });
      return;
    }

    if (req.method !== "POST") {
      json(res, 404, { ok: false, error: "Not found" });
      return;
    }

    const post = normalizePost(await readJson(req));

    if (url.pathname === "/api/uslideblog/markdown") {
      json(res, 200, { ok: true, markdown: slideMarkdown(post) });
      return;
    }

    if (url.pathname === "/api/uslideblog/marp-html") {
      const markdown = slideMarkdown(post);
      json(res, 200, { ok: true, markdown, html: marpHtml(markdown) });
      return;
    }

    if (url.pathname === "/api/uslideblog/pptx") {
      binary(res, 200, await pptxBuffer(post), "application/vnd.openxmlformats-officedocument.presentationml.presentation", "uslideblog.pptx");
      return;
    }

    json(res, 404, { ok: false, error: "Not found" });
  } catch (error) {
    json(res, 500, { ok: false, error: error.message || String(error) });
  }
});

server.listen(PORT, HOST, () => {
  console.log(`USlideBlog renderer listening on http://${HOST}:${PORT}`);
});
