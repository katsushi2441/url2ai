#!/usr/bin/env python3
"""
finrep2pg.py - FinReport watcher for Paragraph

Poll finreport.php API, generate bilingual Markdown (Japanese + English),
and post new reports to Paragraph.

Examples:
  python3 finrep2pg.py --once
  python3 finrep2pg.py --status published --interval 300
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_PATH = os.path.join(SCRIPT_DIR, "config.yaml")
STATE_PATH = os.path.join(SCRIPT_DIR, "finrep2pg_state.json")
LOG_PREFIX = "[finrep2pg]"


def log(message: str) -> None:
    stamp = dt.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    print(f"{stamp} {LOG_PREFIX} {message}", flush=True)


def load_config() -> dict:
    conf = {}
    section = ""
    if not os.path.exists(CONFIG_PATH):
        return conf
    with open(CONFIG_PATH, encoding="utf-8") as fh:
        for line in fh:
            line = line.rstrip("\n")
            if not line.strip() or line.lstrip().startswith("#"):
                continue
            if not line.startswith(" ") and line.endswith(":"):
                section = line[:-1].strip()
                conf.setdefault(section, {})
                continue
            if line.startswith("  ") and ":" in line:
                key, _, value = line.strip().partition(":")
                conf.setdefault(section, {})[key.strip()] = value.strip().strip("'\"")
    return conf


CONF = load_config()
SITE_BASE_URL = CONF.get("site", {}).get("base_url", "https://aiknowledgecms.exbridge.jp")
FINREPORT_FEED_URL = os.environ.get(
    "FINREPORT_FEED_URL",
    f"{SITE_BASE_URL}/finreport.php?api=recent&with_report=1&limit=10",
)
FINREPORT_MARK_URL = os.environ.get(
    "FINREPORT_MARK_URL",
    f"{SITE_BASE_URL}/finreport.php?api=mark_paragraph",
)
FINREPORT_MARK_REMOTE = os.environ.get("FINREPORT_MARK_REMOTE", "").lower() in {"1", "true", "yes"}
OLLAMA_API = os.environ.get(
    "OLLAMA_API",
    CONF.get("ollama", {}).get("api_url", "https://exbridge.ddns.net/api/generate"),
)
OLLAMA_MODEL = os.environ.get(
    "OLLAMA_MODEL",
    CONF.get("ollama", {}).get("default_model", "gemma4:e4b"),
)
PARAGRAPH_API_KEY = os.environ.get(
    "PARAGRAPH_API_KEY",
    CONF.get("paragraph", {}).get("api_key", ""),
)
PARAGRAPH_PUBLICATION_SLUG = os.environ.get(
    "PARAGRAPH_PUBLICATION_SLUG",
    CONF.get("paragraph", {}).get("publication_slug", ""),
)
PARAGRAPH_API_URL = "https://public.api.paragraph.com/api/v1/posts"


def http_json(url: str, payload: dict | None = None, headers: dict | None = None, timeout: int = 120) -> dict:
    req_headers = {"User-Agent": "finrep2pg/1.0"}
    if headers:
        req_headers.update(headers)
    data = None
    if payload is not None:
        data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        req_headers.setdefault("Content-Type", "application/json")
    req = urllib.request.Request(url, data=data, headers=req_headers)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode("utf-8")
            try:
                return json.loads(raw)
            except json.JSONDecodeError as exc:
                snippet = raw[:300].replace("\n", " ")
                raise RuntimeError(f"Non-JSON response from {url}: {snippet}") from exc
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="ignore")
        raise RuntimeError(f"HTTP {exc.code} from {url}: {body[:300]}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"Network error for {url}: {exc}") from exc


def load_state() -> dict:
    if not os.path.exists(STATE_PATH):
        return {"posted_ids": [], "last_created_ts": 0}
    with open(STATE_PATH, encoding="utf-8") as fh:
        data = json.load(fh)
    if not isinstance(data, dict):
        return {"posted_ids": [], "last_created_ts": 0}
    data.setdefault("posted_ids", [])
    data.setdefault("last_created_ts", 0)
    return data


def save_state(state: dict) -> None:
    with open(STATE_PATH, "w", encoding="utf-8") as fh:
        json.dump(state, fh, ensure_ascii=False, indent=2)


def fetch_recent_reports(since_ts: int) -> list[dict]:
    sep = "&" if "?" in FINREPORT_FEED_URL else "?"
    url = f"{FINREPORT_FEED_URL}{sep}since={int(since_ts)}"
    data = http_json(url, timeout=60)
    items = data.get("items", [])
    if not isinstance(items, list):
        return []
    return items


def build_bilingual_markdown(item: dict) -> tuple[str, str]:
    ticker = item.get("ticker", "")
    summary = item.get("summary", "")
    report = item.get("report", "")
    sources = item.get("sources", [])
    detail_url = item.get("detail_url", "")
    source_lines = "\n".join(f"- {src}" for src in sources) if sources else "- No sources provided"
    prompt = f"""You are preparing a bilingual Paragraph post from a financial research report.

Write Markdown in this exact high-level structure:

# 日本語
## 概要
## 主なポイント
## レポート本文
## 参考リンク

# English
## Overview
## Key Points
## Full Report
## Sources

Rules:
- Keep the meaning faithful to the original data
- Preserve important facts and uncertainty
- Do not invent sources
- Use clear Japanese and natural English
- Keep Markdown clean and readable
- Include the detail URL near the end of both language sections

Ticker / Query: {ticker}
Created at: {item.get("created_at", "")}
Detail URL: {detail_url}

Summary:
{summary}

Report:
{report}

Sources:
{source_lines}
"""
    payload = {"model": OLLAMA_MODEL, "prompt": prompt, "stream": False}
    res = http_json(OLLAMA_API, payload=payload, timeout=300)
    content = (res.get("response") or "").strip()
    if not content:
        raise RuntimeError("Ollama returned empty content")
    if is_invalid_generated_content(content):
        raise RuntimeError(f"Invalid generated content from Ollama: {content[:200]}")
    title = f"{ticker} FinReport | 日本語 + English"
    return title, content


def is_invalid_generated_content(content: str) -> bool:
    text = (content or "").strip()
    if len(text) < 300:
        return True
    lowered = text.lower()
    bad_markers = [
        "internal server error",
        "server error",
        "error 500",
        "502 bad gateway",
        "503 service unavailable",
        "traceback",
        "<html",
        "<body",
        "nginx",
        "cloudflare",
    ]
    for marker in bad_markers:
        if marker in lowered:
            return True
    return False


def post_to_paragraph(title: str, markdown: str, status: str) -> dict:
    if not PARAGRAPH_API_KEY:
        raise RuntimeError("PARAGRAPH_API_KEY is not set")
    payload = {"title": title, "markdown": markdown, "status": status}
    headers = {"Authorization": f"Bearer {PARAGRAPH_API_KEY}"}
    return http_json(PARAGRAPH_API_URL, payload=payload, headers=headers, timeout=60)


def resolve_paragraph_post_url(paragraph_post_id: str) -> str:
    publication_slug = (PARAGRAPH_PUBLICATION_SLUG or "").strip()
    paragraph_post_id = (paragraph_post_id or "").strip()
    if not publication_slug or not paragraph_post_id:
        return ""
    publication = http_json(
        "https://public.api.paragraph.com/api/v1/publications/slug/"
        + urllib.parse.quote(publication_slug),
        timeout=60,
    )
    publication_id = str(publication.get("id") or "")
    if not publication_id:
        return ""
    publication_slug_clean = str(publication.get("slug") or publication_slug).lstrip("@")
    custom_domain = str(publication.get("customDomain") or "").strip()
    base_url = custom_domain.rstrip("/") if custom_domain else f"https://paragraph.com/@{publication_slug_clean}"
    posts_data = http_json(
        "https://public.api.paragraph.com/api/v1/publications/"
        + urllib.parse.quote(publication_id)
        + "/posts?limit=100",
        timeout=60,
    )
    for post in posts_data.get("items", []):
        if str(post.get("id") or "") == paragraph_post_id and post.get("slug"):
            return base_url + "/" + str(post["slug"]).lstrip("/")
    return ""


def mark_paragraph_posted(item: dict, para_res: dict) -> dict:
    paragraph_url = para_res.get("url") or para_res.get("canonicalUrl") or ""
    paragraph_post_id = str(para_res.get("id") or para_res.get("postId") or "")
    if not paragraph_url and not paragraph_post_id:
        raise RuntimeError(f"Paragraph response missing url/id: {json.dumps(para_res, ensure_ascii=False)[:300]}")
    if not paragraph_url and paragraph_post_id:
        paragraph_url = resolve_paragraph_post_url(paragraph_post_id)
    if not FINREPORT_MARK_REMOTE:
        return {
            "ok": True,
            "ticker": item.get("ticker", ""),
            "paragraph_url": paragraph_url,
            "paragraph_post_id": paragraph_post_id,
            "remote_mark_skipped": True,
        }
    payload = {
        "ticker": item.get("ticker", ""),
        "paragraph_url": paragraph_url,
        "paragraph_post_id": paragraph_post_id,
    }
    return http_json(FINREPORT_MARK_URL, payload=payload, timeout=60)


def normalize_items(items: list[dict]) -> list[dict]:
    normalized = []
    for item in items:
        if not isinstance(item, dict):
            continue
        if not item.get("id") or not item.get("report"):
            continue
        if item.get("paragraph_url") or item.get("paragraph_post_id"):
            continue
        normalized.append(item)
    normalized.sort(key=lambda x: x.get("created_ts", 0))
    return normalized


def process_new_reports(status: str, dry_run: bool) -> int:
    state = load_state()
    posted_ids = set(state.get("posted_ids", []))
    since_ts = int(state.get("last_created_ts", 0) or 0)
    items = normalize_items(fetch_recent_reports(since_ts))
    if not items:
        log("new reports: 0")
        return 0

    posted_count = 0
    max_created_ts = since_ts
    for item in items:
        item_id = item["id"]
        created_ts = int(item.get("created_ts", 0) or 0)
        if created_ts > max_created_ts:
            max_created_ts = created_ts
        if item_id in posted_ids:
            continue

        title, markdown = build_bilingual_markdown(item)
        if dry_run:
            log(f"dry-run prepared: {title}")
        else:
            res = post_to_paragraph(title, markdown, status)
            para_id = res.get("id") or res.get("postId") or ""
            para_url = res.get("url") or res.get("canonicalUrl") or ""
            mark_paragraph_posted(item, res)
            log(f"posted: {item['ticker']} paragraph_id={para_id} url={para_url}")
            posted_ids.add(item_id)
            posted_count += 1
        if dry_run:
            continue

    if not dry_run:
        state["posted_ids"] = sorted(posted_ids)
        state["last_created_ts"] = max_created_ts
        save_state(state)
    return posted_count


def main() -> int:
    parser = argparse.ArgumentParser(description="Watch FinReport API and post bilingual content to Paragraph.")
    parser.add_argument("--once", action="store_true", help="Run one cycle and exit.")
    parser.add_argument("--interval", type=int, default=300, help="Polling interval in seconds.")
    parser.add_argument("--status", choices=["draft", "published"], default="draft", help="Paragraph post status.")
    parser.add_argument("--dry-run", action="store_true", help="Generate content without posting.")
    args = parser.parse_args()

    if args.once:
        process_new_reports(status=args.status, dry_run=args.dry_run)
        return 0

    log(f"watching {FINREPORT_FEED_URL} every {args.interval}s status={args.status} dry_run={args.dry_run}")
    while True:
        try:
            process_new_reports(status=args.status, dry_run=args.dry_run)
        except Exception as exc:
            log(f"error: {exc}")
        time.sleep(max(args.interval, 30))


if __name__ == "__main__":
    sys.exit(main())
