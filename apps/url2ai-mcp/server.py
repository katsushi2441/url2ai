from __future__ import annotations

import os
import re
from typing import Any

import httpx
from fastmcp import FastMCP


DEFAULT_UPDF2MD_API_URL = "http://exbridge.ddns.net:8010/pdf/convert"
DEFAULT_IMAGE_API_URL = "http://exbridge.ddns.net:8011/generate"
DEFAULT_TEXT_API_URL = "https://exbridge.ddns.net/api/generate"
DEFAULT_TEXT_MODEL = "gemma4:e4b"
DEFAULT_HOST = "0.0.0.0"
DEFAULT_PORT = 8012

NEGATIVE_PROMPT = (
    "horror, creepy, ghost photo, grotesque, gore, blood, disturbing mouth, "
    "realistic oral cavity, deformed face, extra limbs, bad anatomy, blurry, "
    "low quality, dark horror, zombie, uncanny"
)

mcp = FastMCP("URL2AI MCP Server")


def get_updf2md_api_url() -> str:
    return os.getenv("URL2AI_UPDF2MD_API_URL", DEFAULT_UPDF2MD_API_URL)


def get_image_api_url() -> str:
    return os.getenv("URL2AI_IMAGE_API_URL", DEFAULT_IMAGE_API_URL)


def get_text_api_url() -> str:
    return os.getenv("URL2AI_TEXT_API_URL", DEFAULT_TEXT_API_URL)


def get_text_model() -> str:
    return os.getenv("URL2AI_TEXT_MODEL", DEFAULT_TEXT_MODEL)


def extract_tweet_id(value: str) -> str:
    patterns = [
        r"(?:https?://)?(?:www\.)?(?:x|twitter)\.com/(?:i/web/)?[^/?#]+/status(?:es)?/(\d{15,20})",
        r"(?:https?://)?(?:www\.)?(?:x|twitter)\.com/i/status/(\d{15,20})",
        r"status(?:es)?/(\d{15,20})",
        r"\b(\d{15,20})\b",
    ]
    for pattern in patterns:
        match = re.search(pattern, value, re.IGNORECASE)
        if match:
            return match.group(1)
    return ""


def strip_html(value: str) -> str:
    value = re.sub(r"<script[\s\S]*?</script>", " ", value, flags=re.IGNORECASE)
    value = re.sub(r"<style[\s\S]*?</style>", " ", value, flags=re.IGNORECASE)
    value = re.sub(r"<[^>]+>", " ", value)
    value = re.sub(r"\s+", " ", value)
    return value.strip()


def extract_meta(html: str, key: str, attr: str) -> str:
    pattern = rf'<meta[^>]+{attr}=["\']{re.escape(key)}["\'][^>]+content=["\']([^"\']+)["\'][^>]*>'
    match = re.search(pattern, html, re.IGNORECASE)
    return match.group(1).strip() if match else ""


def extract_title(html: str) -> str:
    match = re.search(r"<title[^>]*>([\s\S]*?)</title>", html, re.IGNORECASE)
    return strip_html(match.group(1)) if match else ""


async def fetch_x_thread(tweet_url: str) -> str:
    tweet_id = extract_tweet_id(tweet_url)
    if not tweet_id:
        raise ValueError("tweet_url must contain a valid X post ID")

    seen: set[str] = set()
    thread: list[str] = []
    current_id = tweet_id
    depth = 0

    async with httpx.AsyncClient(timeout=20.0, follow_redirects=True) as client:
        while current_id and depth < 16 and current_id not in seen:
            seen.add(current_id)
            response = await client.get(
                f"https://api.fxtwitter.com/i/status/{current_id}",
                headers={
                    "User-Agent": "URL2AI-MCP/1.0",
                    "Accept": "application/json",
                },
            )
            response.raise_for_status()
            payload = response.json()
            tweet = payload.get("tweet") or {}
            if not tweet:
                raise ValueError("X thread response did not include tweet data")
            author = ((tweet.get("author") or {}).get("screen_name")) or "unknown"
            text = tweet.get("text") or ""
            thread.insert(0, f"@{author}: {text}".strip())
            current_id = tweet.get("replying_to_status") or ""
            depth += 1

    return "\n\n".join(thread)


async def fetch_url_context(source_url: str) -> str:
    async with httpx.AsyncClient(timeout=20.0, follow_redirects=True) as client:
        response = await client.get(
            source_url,
            headers={"User-Agent": "URL2AI-MCP/1.0"},
        )
        response.raise_for_status()
        html = response.text

    title = (
        extract_meta(html, "og:title", "property")
        or extract_meta(html, "twitter:title", "name")
        or extract_title(html)
    )
    description = (
        extract_meta(html, "og:description", "property")
        or extract_meta(html, "twitter:description", "name")
        or extract_meta(html, "description", "name")
    )
    body = strip_html(html)[:1200]

    return "\n\n".join(part for part in [title, description, body] if part)


async def build_image_prompt(source_text: str) -> str:
    prompt = f"""以下の内容をもとに、URL2AI ERNIE Image API 用の画像生成プロンプトを日本語で1本だけ作成してください。

条件:
- 出力は画像生成モデルにそのまま渡せるプロンプト本文のみ
- 明るく、見やすく、広告ビジュアルやポップイラスト寄り
- 不気味、ホラー、グロテスク、心霊写真風は避ける
- 被写体、背景、構図、光、色、雰囲気を具体的に書く
- 誇張表現が含まれていても安全でユーモラスな比喩に置き換える

---
{source_text}
---"""

    async with httpx.AsyncClient(timeout=40.0, follow_redirects=True) as client:
        response = await client.post(
            get_text_api_url(),
            json={
                "model": get_text_model(),
                "prompt": prompt,
                "stream": False,
                "options": {
                    "num_ctx": 2048,
                    "temperature": 0.7,
                    "top_k": 40,
                    "top_p": 0.9,
                },
            },
            headers={"Content-Type": "application/json"},
        )
        response.raise_for_status()
        payload = response.json()

    result = (payload.get("response") or "").strip()
    if not result:
        raise ValueError("Prompt generation did not return a usable prompt")
    return result


async def call_image_backend(prompt: str, width: int, height: int) -> dict[str, Any]:
    async with httpx.AsyncClient(timeout=120.0, follow_redirects=True) as client:
        response = await client.post(
            get_image_api_url(),
            json={
                "prompt": prompt,
                "negative_prompt": NEGATIVE_PROMPT,
                "width": width,
                "height": height,
                "num_inference_steps": 8,
                "guidance_scale": 1.0,
                "use_pe": True,
                "output_format": "png",
            },
            headers={"Content-Type": "application/json", "Accept": "application/json"},
        )
        response.raise_for_status()
        return response.json()


@mcp.tool
async def convert_pdf_to_markdown(
    pdf_url: str,
    pages: str = "",
    filename: str = "",
) -> dict[str, Any]:
    """Convert a public PDF URL into Markdown for RAG and document workflows."""
    async with httpx.AsyncClient(timeout=180.0, follow_redirects=True) as client:
        response = await client.post(
            get_updf2md_api_url(),
            files=None,
            data={
                "pdf_url": pdf_url,
                "pages": pages,
                "filename": filename,
            },
        )
        response.raise_for_status()
        return response.json()


@mcp.tool
async def generate_image_from_text(
    text: str,
    width: int = 1024,
    height: int = 1024,
) -> dict[str, Any]:
    """Generate an image from direct text input using ERNIE-Image-Turbo."""
    prompt = await build_image_prompt(text.strip())
    image_result = await call_image_backend(prompt, width, height)
    return {
        "ok": True,
        "input_type": "text",
        "source_text": text,
        "prompt": prompt,
        "model": image_result.get("model_id") or "ERNIE-Image-Turbo",
        "image_base64": image_result.get("image_base64"),
        "output_format": image_result.get("output_format") or "png",
        "width": image_result.get("width") or width,
        "height": image_result.get("height") or height,
        "processing_time_ms": image_result.get("processing_time_ms"),
    }


@mcp.tool
async def generate_image_from_url(
    url: str,
    width: int = 1024,
    height: int = 1024,
) -> dict[str, Any]:
    """Generate an image from a public URL by fetching and summarizing its contents."""
    source_text = await fetch_url_context(url.strip())
    prompt = await build_image_prompt(source_text)
    image_result = await call_image_backend(prompt, width, height)
    return {
        "ok": True,
        "input_type": "url",
        "source_url": url,
        "source_text": source_text,
        "prompt": prompt,
        "model": image_result.get("model_id") or "ERNIE-Image-Turbo",
        "image_base64": image_result.get("image_base64"),
        "output_format": image_result.get("output_format") or "png",
        "width": image_result.get("width") or width,
        "height": image_result.get("height") or height,
        "processing_time_ms": image_result.get("processing_time_ms"),
    }


@mcp.tool
async def generate_image_from_x_post(
    tweet_url: str,
    width: int = 1024,
    height: int = 1024,
) -> dict[str, Any]:
    """Generate an image from an X post URL by reading its thread context."""
    source_text = await fetch_x_thread(tweet_url.strip())
    prompt = await build_image_prompt(source_text)
    image_result = await call_image_backend(prompt, width, height)
    return {
        "ok": True,
        "input_type": "x_post",
        "source_url": tweet_url,
        "source_text": source_text,
        "prompt": prompt,
        "model": image_result.get("model_id") or "ERNIE-Image-Turbo",
        "image_base64": image_result.get("image_base64"),
        "output_format": image_result.get("output_format") or "png",
        "width": image_result.get("width") or width,
        "height": image_result.get("height") or height,
        "processing_time_ms": image_result.get("processing_time_ms"),
    }


if __name__ == "__main__":
    host = os.getenv("URL2AI_MCP_HOST", DEFAULT_HOST)
    port = int(os.getenv("URL2AI_MCP_PORT", str(DEFAULT_PORT)))
    mcp.run(transport="http", host=host, port=port)
