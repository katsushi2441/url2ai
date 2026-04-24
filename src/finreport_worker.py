#!/usr/bin/env python3
"""
finreport_worker.py

Daily worker that discovers talked-about crypto assets and listed companies
from news feeds, generates FinReport entries, saves them in the same format as
finreport.php, and posts them to Paragraph.

Examples:
  python3 finreport_worker.py --once
  python3 finreport_worker.py --once --dry-run
  python3 finreport_worker.py --interval 3600
"""

from __future__ import annotations

import argparse
import datetime as dt
import email.utils
import json
import os
import re
import sys
import time
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET


SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_PATH = os.path.join(SCRIPT_DIR, "config.yaml")
STATE_PATH = os.path.join(SCRIPT_DIR, "finreport_worker_state.json")
DATA_DIR = os.path.join(SCRIPT_DIR, "data")
LOG_PREFIX = "[finreport_worker]"


def log(message: str) -> None:
    stamp = dt.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    print(f"{stamp} {LOG_PREFIX} {message}", flush=True)


def load_config() -> dict:
    conf: dict[str, dict[str, str]] = {}
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
FINREPORT_API = os.environ.get(
    "FINREPORT_API",
    CONF.get("finreport", {}).get("api_url", "http://exbridge.ddns.net:8014/report"),
)
OLLAMA_API = os.environ.get(
    "OLLAMA_API",
    CONF.get("ollama", {}).get("api_url", "https://exbridge.ddns.net/api/generate"),
)
OLLAMA_MODEL = os.environ.get(
    "OLLAMA_MODEL",
    CONF.get("ollama", {}).get("default_model", "gemma4:e4b"),
)
FINREPORT_PARAGRAPH_STATUS = os.environ.get("FINREPORT_PARAGRAPH_STATUS", "published")
RUN_HOURS_JST = [3, 9, 15, 21]
MAX_ITEMS_PER_DAY = int(os.environ.get("FINREPORT_WORKER_MAX_ITEMS", "4"))
BANKR_DISCOVER_URL = "https://bankr.bot/discover/0xDaecDda6AD112f0E1E4097fB735dD01D9C33cBA3"

GOOGLE_NEWS_FEEDS = [
    {
        "kind": "crypto",
        "url": "https://news.google.com/rss/search?q=crypto+OR+bitcoin+OR+ethereum+OR+solana+when:1d&hl=en-US&gl=US&ceid=US:en",
    },
    {
        "kind": "equity",
        "url": "https://news.google.com/rss/search?q=stocks+OR+earnings+OR+guidance+OR+shares+when:1d&hl=en-US&gl=US&ceid=US:en",
    },
]


def http_json(url: str, payload: dict | None = None, headers: dict | None = None, timeout: int = 120) -> dict:
    req_headers = {"User-Agent": "finreport_worker/1.0"}
    if headers:
        req_headers.update(headers)
    data = None
    if payload is not None:
        data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        req_headers.setdefault("Content-Type", "application/json")
    req = urllib.request.Request(url, data=data, headers=req_headers)
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        raw = resp.read().decode("utf-8")
        return json.loads(raw)


def http_text(url: str, timeout: int = 60) -> str:
    req = urllib.request.Request(url, headers={"User-Agent": "finreport_worker/1.0"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return resp.read().decode("utf-8", errors="ignore")


def load_state() -> dict:
    if not os.path.exists(STATE_PATH):
        return {"last_run_date": "", "last_run_slot": "", "processed_news_ids": [], "queries_today": []}
    with open(STATE_PATH, encoding="utf-8") as fh:
        data = json.load(fh)
    if not isinstance(data, dict):
        return {"last_run_date": "", "last_run_slot": "", "processed_news_ids": [], "queries_today": []}
    data.setdefault("last_run_date", "")
    data.setdefault("last_run_slot", "")
    data.setdefault("processed_news_ids", [])
    data.setdefault("queries_today", [])
    return data


def save_state(state: dict) -> None:
    with open(STATE_PATH, "w", encoding="utf-8") as fh:
        json.dump(state, fh, ensure_ascii=False, indent=2)


def strip_html(value: str) -> str:
    return re.sub(r"<[^>]+>", "", value or "").strip()


def parse_pubdate(raw: str) -> int:
    if not raw:
        return 0
    parsed = email.utils.parsedate_tz(raw)
    if not parsed:
        return 0
    return int(email.utils.mktime_tz(parsed))


def fetch_news_items() -> list[dict]:
    items: list[dict] = []
    seen_links: set[str] = set()
    for feed in GOOGLE_NEWS_FEEDS:
        try:
            xml_text = http_text(feed["url"], timeout=45)
            root = ET.fromstring(xml_text)
        except Exception as exc:
            log(f"feed error ({feed['kind']}): {exc}")
            continue
        for node in root.findall("./channel/item"):
            title = (node.findtext("title") or "").strip()
            link = (node.findtext("link") or "").strip()
            desc = strip_html(node.findtext("description") or "")
            pub_date = parse_pubdate(node.findtext("pubDate") or "")
            if not title or not link or link in seen_links:
                continue
            seen_links.add(link)
            items.append(
                {
                    "kind": feed["kind"],
                    "title": title,
                    "link": link,
                    "summary": desc,
                    "pub_ts": pub_date,
                    "news_id": link,
                }
            )
    items.sort(key=lambda x: x.get("pub_ts", 0), reverse=True)
    return items


def slugify(text: str) -> str:
    return re.sub(r"[^a-zA-Z0-9_-]", "_", text.strip().lower())


def report_exists_today(query: str) -> bool:
    slug = slugify(query)
    if not slug:
        return False
    today = dt.datetime.now().strftime("%Y%m%d")
    path = os.path.join(DATA_DIR, f"finreport_{slug}_{today}.json")
    return os.path.exists(path)


def save_finreport(query: str, response: dict, source_item: dict) -> str:
    os.makedirs(DATA_DIR, exist_ok=True)
    today = dt.datetime.now().strftime("%Y%m%d")
    path = os.path.join(DATA_DIR, f"finreport_{slugify(query)}_{today}.json")
    payload = {
        "ticker": query,
        "report": response.get("report", ""),
        "summary": response.get("summary", ""),
        "sources": response.get("sources", []),
        "created_at": dt.datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "news_kind": source_item.get("kind", ""),
        "news_title": source_item.get("title", ""),
        "news_link": source_item.get("link", ""),
        "news_summary": source_item.get("summary", ""),
    }
    with open(path, "w", encoding="utf-8") as fh:
        json.dump(payload, fh, ensure_ascii=False, indent=2)
    return path


def call_ollama(prompt: str) -> str:
    payload = {"model": OLLAMA_MODEL, "prompt": prompt, "stream": False}
    res = http_json(OLLAMA_API, payload=payload, timeout=300)
    return (res.get("response") or "").strip()


def select_report_queries(news_items: list[dict], limit: int) -> list[dict]:
    if not news_items:
        return []
    lines = []
    for idx, item in enumerate(news_items[:20], 1):
        lines.append(
            f"{idx}. [{item['kind']}] {item['title']}\n"
            f"   Summary: {item['summary'][:240]}\n"
            f"   Link: {item['link']}"
        )
    prompt = f"""You are selecting daily FinReport topics from news headlines.

Return JSON only in this shape:
{{"items":[{{"index":1,"query":"NVIDIA","kind":"equity","reason":"earnings/news relevance"}}]}}

Rules:
- Pick up to {limit} items total
- Include a mix of crypto and listed companies when possible
- query must be a single company name, stock ticker, crypto asset name, or token/project name that FinReport can research
- Skip vague macro topics with no clear target
- Avoid duplicates
- Prefer talked-about names from the headlines below

News:
{chr(10).join(lines)}
"""
    raw = call_ollama(prompt)
    match = re.search(r"\{.*\}", raw, re.S)
    if not match:
        log(f"ollama selection parse failed: {raw[:200]}")
        return []
    try:
        data = json.loads(match.group(0))
    except Exception:
        log(f"ollama selection invalid json: {raw[:200]}")
        return []
    results: list[dict] = []
    seen_queries: set[str] = set()
    for item in data.get("items", []):
        if not isinstance(item, dict):
            continue
        idx = int(item.get("index", 0) or 0)
        query = (item.get("query") or "").strip()
        if idx < 1 or idx > len(news_items) or not query:
            continue
        key = query.lower()
        if key in seen_queries:
            continue
        seen_queries.add(key)
        merged = dict(news_items[idx - 1])
        merged["query"] = query
        merged["reason"] = (item.get("reason") or "").strip()
        results.append(merged)
    return results[:limit]


def generate_finreport(query: str) -> dict:
    return http_json(FINREPORT_API, payload={"ticker": query}, timeout=600)


def process_candidates(dry_run: bool) -> int:
    state = load_state()
    today = dt.datetime.now().strftime("%Y-%m-%d")
    if state.get("last_run_date") != today:
        state["last_run_date"] = today
        state["queries_today"] = []

    processed_news_ids = set(state.get("processed_news_ids", []))
    queries_today = set(state.get("queries_today", []))
    fresh_news = [item for item in fetch_news_items() if item["news_id"] not in processed_news_ids]
    if not fresh_news:
        log("new news items: 0")
        return 0

    candidates = select_report_queries(fresh_news, MAX_ITEMS_PER_DAY)
    if not candidates:
        log("selected report topics: 0")
        return 0

    created = 0
    for item in candidates:
        query = item["query"]
        processed_news_ids.add(item["news_id"])
        if query.lower() in queries_today or report_exists_today(query):
            log(f"skip duplicate query: {query}")
            continue
        if dry_run:
            log(f"dry-run candidate: {query} <- {item['title']}")
            queries_today.add(query.lower())
            created += 1
            continue
        try:
            result = generate_finreport(query)
        except Exception as exc:
            log(f"generate failed for {query}: {exc}")
            continue
        if not result.get("report"):
            log(f"empty report for {query}")
            continue
        path = save_finreport(query, result, item)
        log(f"saved report: {query} -> {path}")
        queries_today.add(query.lower())
        created += 1

    state["processed_news_ids"] = list(processed_news_ids)[-500:]
    state["queries_today"] = list(queries_today)
    save_state(state)

    if created > 0 and not dry_run:
        try:
            import finrep2pg

            original_builder = finrep2pg.build_bilingual_markdown

            def build_with_bankr_link(item: dict) -> tuple[str, str]:
                title, markdown = original_builder(item)
                bankr_section = (
                    "\n\n---\n"
                    "Bankr / URL2AI:\n"
                    f"- Discover URL2AI on Bankr: {BANKR_DISCOVER_URL}\n"
                )
                return title, markdown.rstrip() + bankr_section

            finrep2pg.build_bilingual_markdown = build_with_bankr_link
            posted = finrep2pg.process_new_reports(status=FINREPORT_PARAGRAPH_STATUS, dry_run=False)
            log(f"paragraph posts created: {posted}")
        except Exception as exc:
            log(f"paragraph posting error: {exc}")

    return created


def should_run_now(now: dt.datetime) -> bool:
    state = load_state()
    slot = now.strftime("%Y-%m-%d") + f"-{now.hour:02d}"
    return now.hour in RUN_HOURS_JST and state.get("last_run_slot") != slot


def mark_run_slot(now: dt.datetime) -> None:
    state = load_state()
    state["last_run_slot"] = now.strftime("%Y-%m-%d") + f"-{now.hour:02d}"
    save_state(state)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--once", action="store_true", help="Run one cycle immediately")
    parser.add_argument("--dry-run", action="store_true", help="Discover candidates without generating reports")
    parser.add_argument("--interval", type=int, default=3600, help="Loop interval in seconds")
    args = parser.parse_args()

    if args.once:
        process_candidates(dry_run=args.dry_run)
        return 0

    log(f"watching schedule; run_hours={RUN_HOURS_JST} interval={args.interval}s dry_run={args.dry_run}")
    while True:
        now = dt.datetime.now()
        try:
            if should_run_now(now):
                process_candidates(dry_run=args.dry_run)
                mark_run_slot(now)
            else:
                log("idle")
        except Exception as exc:
            log(f"error: {exc}")
        time.sleep(max(args.interval, 60))


if __name__ == "__main__":
    sys.exit(main())
