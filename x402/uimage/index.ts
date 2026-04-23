type InputType = "text" | "url" | "x_post" | "prompt";

type UImageRequest = {
  input_type?: InputType;
  prompt?: string;
  text?: string;
  url?: string;
  tweet_url?: string;
  width?: number;
  height?: number;
};

const DEFAULT_IMAGE_API_URL = "http://exbridge.ddns.net:8011/generate";
const DEFAULT_TEXT_API_URL = "https://exbridge.ddns.net/api/generate";
const DEFAULT_TEXT_MODEL = "gemma4:e4b";

function json(data: unknown, init?: ResponseInit): Response {
  return Response.json(data, init);
}

function getImageApiUrl(): string {
  return process.env.UIMAGE_API_URL || DEFAULT_IMAGE_API_URL;
}

function getTextApiUrl(): string {
  return process.env.UIMAGE_TEXT_API_URL || DEFAULT_TEXT_API_URL;
}

function getTextModel(): string {
  return process.env.UIMAGE_TEXT_MODEL || DEFAULT_TEXT_MODEL;
}

function extractTweetId(input: string): string {
  const patterns = [
    /(?:https?:\/\/)?(?:www\.)?(?:x|twitter)\.com\/(?:i\/web\/)?[^/?#]+\/status(?:es)?\/(\d{15,20})/i,
    /(?:https?:\/\/)?(?:www\.)?(?:x|twitter)\.com\/i\/status\/(\d{15,20})/i,
    /status(?:es)?\/(\d{15,20})/i,
    /\b(\d{15,20})\b/,
  ];
  for (const pattern of patterns) {
    const match = input.match(pattern);
    if (match) return match[1];
  }
  return "";
}

async function fetchXThread(tweetUrl: string): Promise<string> {
  const tweetId = extractTweetId(tweetUrl);
  if (!tweetId) {
    throw new Error("tweet_url must contain a valid X post ID");
  }

  const seen = new Set<string>();
  const thread: string[] = [];
  let currentId = tweetId;
  let depth = 0;

  while (currentId && depth < 16 && !seen.has(currentId)) {
    seen.add(currentId);
    const response = await fetch(`https://api.fxtwitter.com/i/status/${currentId}`, {
      headers: { "User-Agent": "URL2AI-ERNIE-Image-API/1.0", Accept: "application/json" },
    });
    if (!response.ok) {
      throw new Error(`Failed to fetch X post thread (${response.status})`);
    }
    const data = (await response.json()) as any;
    if (!data?.tweet) {
      throw new Error("X thread response did not include tweet data");
    }
    const tweet = data.tweet;
    thread.unshift(`@${tweet.author?.screen_name || "unknown"}: ${tweet.text || ""}`.trim());
    currentId = tweet.replying_to_status || "";
    depth += 1;
  }

  return thread.join("\n\n");
}

function stripHtml(html: string): string {
  return html
    .replace(/<script[\s\S]*?<\/script>/gi, " ")
    .replace(/<style[\s\S]*?<\/style>/gi, " ")
    .replace(/<[^>]+>/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

function extractMeta(html: string, key: string, attr: "name" | "property"): string {
  const escaped = key.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const pattern = new RegExp(
    `<meta[^>]+${attr}=["']${escaped}["'][^>]+content=["']([^"']+)["'][^>]*>`,
    "i",
  );
  const match = html.match(pattern);
  return match?.[1]?.trim() || "";
}

function extractTitle(html: string): string {
  const match = html.match(/<title[^>]*>([\s\S]*?)<\/title>/i);
  return match ? stripHtml(match[1]) : "";
}

async function fetchUrlContext(sourceUrl: string): Promise<string> {
  const url = new URL(sourceUrl);
  if (!["http:", "https:"].includes(url.protocol)) {
    throw new Error("url must use http or https");
  }

  const response = await fetch(url.toString(), {
    headers: { "User-Agent": "URL2AI-ERNIE-Image-API/1.0" },
  });
  if (!response.ok) {
    throw new Error(`Failed to fetch source URL (${response.status})`);
  }
  const html = await response.text();
  const title =
    extractMeta(html, "og:title", "property") ||
    extractMeta(html, "twitter:title", "name") ||
    extractTitle(html);
  const description =
    extractMeta(html, "og:description", "property") ||
    extractMeta(html, "twitter:description", "name") ||
    extractMeta(html, "description", "name");
  const body = stripHtml(html).slice(0, 1200);

  return [title, description, body].filter(Boolean).join("\n\n");
}

async function generatePrompt(sourceText: string): Promise<string> {
  const prompt = `以下の内容をもとに、URL2AI ERNIE Image API 用の画像生成プロンプトを日本語で1本だけ作成してください。

条件:
- 出力は画像生成モデルにそのまま渡せるプロンプト本文のみ
- 明るく、見やすく、広告ビジュアルやポップイラスト寄り
- 不気味、ホラー、グロテスク、心霊写真風は避ける
- 被写体、背景、構図、光、色、雰囲気を具体的に書く
- 誇張表現が含まれていても安全でユーモラスな比喩に置き換える

---
${sourceText}
---`;

  const response = await fetch(getTextApiUrl(), {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      model: getTextModel(),
      prompt,
      stream: false,
      options: {
        num_ctx: 2048,
        temperature: 0.7,
        top_k: 40,
        top_p: 0.9,
      },
    }),
  });

  if (!response.ok) {
    throw new Error(`Prompt generation backend failed (${response.status})`);
  }

  const data = (await response.json()) as any;
  const result = typeof data?.response === "string" ? data.response.trim() : "";
  if (!result) {
    throw new Error("Prompt generation did not return a usable prompt");
  }
  return result;
}

async function generateImage(prompt: string, width: number, height: number): Promise<any> {
  const response = await fetch(getImageApiUrl(), {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify({
      prompt,
      negative_prompt:
        "horror, creepy, ghost photo, grotesque, gore, blood, disturbing mouth, realistic oral cavity, deformed face, extra limbs, bad anatomy, blurry, low quality, dark horror, zombie, uncanny",
      width,
      height,
      num_inference_steps: 8,
      guidance_scale: 1.0,
      use_pe: true,
      output_format: "png",
    }),
  });

  const data = (await response.json()) as any;
  if (!response.ok) {
    throw new Error(data?.detail || `Image backend failed (${response.status})`);
  }
  return data;
}

function resolveInputType(body: UImageRequest): InputType | null {
  if (body.input_type) return body.input_type;
  if (body.prompt) return "prompt";
  if (body.tweet_url) return "x_post";
  if (body.url) return "url";
  if (body.text) return "text";
  return null;
}

export default async function handler(req: Request): Promise<Response> {
  if (req.method !== "POST") {
    return json({ error: "POST required" }, { status: 405 });
  }

  let body: UImageRequest;
  try {
    body = (await req.json()) as UImageRequest;
  } catch {
    return json({ error: "Invalid JSON body" }, { status: 400 });
  }

  const inputType = resolveInputType(body);
  if (!inputType) {
    return json(
      { error: "Provide one of: prompt, text, url, tweet_url, or input_type" },
      { status: 400 },
    );
  }

  const width = Math.max(256, Math.min(1536, Number(body.width) || 1024));
  const height = Math.max(256, Math.min(1536, Number(body.height) || 1024));

  try {
    let sourceText = "";
    let sourceUrl = "";
    let prompt = "";

    if (inputType === "prompt") {
      prompt = (body.prompt || "").trim();
      if (!prompt) {
        return json({ error: "prompt is required for input_type=prompt" }, { status: 400 });
      }
    } else if (inputType === "text") {
      sourceText = (body.text || "").trim();
      if (!sourceText) {
        return json({ error: "text is required for input_type=text" }, { status: 400 });
      }
    } else if (inputType === "url") {
      sourceUrl = (body.url || "").trim();
      if (!sourceUrl) {
        return json({ error: "url is required for input_type=url" }, { status: 400 });
      }
      sourceText = await fetchUrlContext(sourceUrl);
    } else if (inputType === "x_post") {
      sourceUrl = (body.tweet_url || body.url || "").trim();
      if (!sourceUrl) {
        return json({ error: "tweet_url is required for input_type=x_post" }, { status: 400 });
      }
      sourceText = await fetchXThread(sourceUrl);
    }

    if (!prompt) {
      prompt = await generatePrompt(sourceText);
    }
    const imageResult = await generateImage(prompt, width, height);

    return json({
      ok: true,
      input_type: inputType,
      source_url: sourceUrl || null,
      source_text: sourceText,
      prompt,
      model: imageResult?.model_id || "ERNIE-Image-Turbo",
      image_base64: imageResult?.image_base64 || null,
      output_format: imageResult?.output_format || "png",
      width: imageResult?.width || width,
      height: imageResult?.height || height,
      processing_time_ms: imageResult?.processing_time_ms || null,
    });
  } catch (error) {
    return json(
      {
        error: error instanceof Error ? error.message : "Unhandled uimage error",
      },
      { status: 502 },
    );
  }
}
