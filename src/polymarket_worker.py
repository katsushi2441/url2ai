#!/usr/bin/env python3
"""
polymarket_worker.py

Daily worker that fetches trending Polymarket markets by volume,
generates intelligence reports, saves them in the same format as
polymarket.php. It does not post to Paragraph.

Examples:
  python3 polymarket_worker.py --once
  python3 polymarket_worker.py --once --dry-run
  python3 polymarket_worker.py --interval 600
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_PATH = os.path.join(SCRIPT_DIR, "config.yaml")
STATE_PATH  = os.path.join(SCRIPT_DIR, "polymarket_worker_state.json")
DATA_DIR    = os.path.join(SCRIPT_DIR, "data")
LOG_PREFIX  = "[polymarket_worker]"

GAMMA_API = "https://gamma-api.polymarket.com/markets"


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
DASHBOARD_REPORT_URL = "http://exbridge.ddns.net:8081/worker/report"


def report_worker(name: str, status: str, items: int, note: str = "") -> None:
    try:
        payload = json.dumps({"name": name, "status": status, "items": items, "note": note}).encode()
        req = urllib.request.Request(
            DASHBOARD_REPORT_URL, data=payload, headers={"Content-Type": "application/json"}, method="POST")
        urllib.request.urlopen(req, timeout=10)
    except Exception as exc:
        log(f"dashboard report失敗: {exc}")


POLYMARKET_API = os.environ.get(
    "POLYMARKET_API",
    CONF.get("polymarket", {}).get("api_url", "http://exbridge.ddns.net:8016/report"),
)
POLYMARKET_SAVE_URL = os.environ.get(
    "POLYMARKET_SAVE_URL",
    f"{SITE_BASE_URL}/polymarket.php?api=save",
)
POLYMARKET_REGISTER_REMOTE = os.environ.get("POLYMARKET_REGISTER_REMOTE", "1").lower() in {"1", "true", "yes"}
OLLAMA_API = os.environ.get(
    "OLLAMA_API",
    CONF.get("ollama", {}).get("api_url", "https://exbridge.ddns.net/api/generate"),
)
OLLAMA_MODEL = os.environ.get(
    "OLLAMA_MODEL",
    CONF.get("ollama", {}).get("default_model", "gemma4:e4b"),
)
RUN_TIMES_JST     = [(10, 0)]
MAX_ITEMS_PER_DAY = int(os.environ.get("POLYMARKET_WORKER_MAX_ITEMS", "1"))
MAX_CANDIDATES_PER_RUN = int(os.environ.get("POLYMARKET_WORKER_CANDIDATES", "10"))
FETCH_POOL_SIZE   = int(os.environ.get("POLYMARKET_WORKER_POOL_SIZE", "50"))


# ---------------------------------------------------------------------------
# HTTP helpers
# ---------------------------------------------------------------------------

def http_json(url: str, payload: dict | None = None, headers: dict | None = None, timeout: int = 120) -> dict:
    req_headers = {"User-Agent": "polymarket_worker/1.0"}
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
                raise RuntimeError(f"non-json response from {url}: {snippet}") from exc
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="ignore")
        raise RuntimeError(f"HTTP {exc.code} from {url}: {body[:300]}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"network error for {url}: {exc}") from exc


# ---------------------------------------------------------------------------
# Gamma API — top markets by volume
# ---------------------------------------------------------------------------

def fetch_top_markets(limit: int = 50) -> list[dict]:
    params = urllib.parse.urlencode({
        "active":    "true",
        "closed":    "false",
        "order":     "volume",
        "ascending": "false",
        "limit":     str(limit),
    })
    req = urllib.request.Request(
        f"{GAMMA_API}?{params}",
        headers={"User-Agent": "polymarket_worker/1.0"},
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        data = json.loads(resp.read().decode("utf-8"))
    return data if isinstance(data, list) else []


# ---------------------------------------------------------------------------
# State
# ---------------------------------------------------------------------------

def load_state() -> dict:
    if not os.path.exists(STATE_PATH):
        return {
            "last_run_date":   "",
            "last_run_slot":   "",
            "processed_slugs": [],
            "queries_today":   [],
        }
    with open(STATE_PATH, encoding="utf-8") as fh:
        data = json.load(fh)
    if not isinstance(data, dict):
        return {
            "last_run_date":   "",
            "last_run_slot":   "",
            "processed_slugs": [],
            "queries_today":   [],
        }
    data.setdefault("last_run_date",   "")
    data.setdefault("last_run_slot",   "")
    data.setdefault("processed_slugs", [])
    data.setdefault("queries_today",   [])
    return data


def save_state(state: dict) -> None:
    with open(STATE_PATH, "w", encoding="utf-8") as fh:
        json.dump(state, fh, ensure_ascii=False, indent=2)


# ---------------------------------------------------------------------------
# Storage helpers
# ---------------------------------------------------------------------------

def slugify(text: str) -> str:
    return re.sub(r"[^a-zA-Z0-9_-]", "_", text.strip().lower())


def report_exists_today(query: str) -> bool:
    slug = slugify(query)
    if not slug:
        return False
    today = dt.datetime.now().strftime("%Y%m%d")
    return os.path.exists(os.path.join(DATA_DIR, f"polymarket_{slug}_{today}.json"))


def save_polymarketreport(query: str, response: dict) -> str:
    os.makedirs(DATA_DIR, exist_ok=True)
    today = dt.datetime.now().strftime("%Y%m%d")
    path  = os.path.join(DATA_DIR, f"polymarket_{slugify(query)}_{today}.json")
    payload = {
        "query":           query,
        "depth":           response.get("depth", "medium"),
        "report":          response.get("report", ""),
        "summary":         response.get("summary", ""),
        "matched_markets": response.get("matched_markets", []),
        "sources":         response.get("sources", []),
        "created_at":      dt.datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
    }
    with open(path, "w", encoding="utf-8") as fh:
        json.dump(payload, fh, ensure_ascii=False, indent=2)
    return path


def load_saved_report(saved_path: str) -> dict:
    if not saved_path or not os.path.exists(saved_path):
        return {}
    with open(saved_path, encoding="utf-8") as fh:
        return json.load(fh)


def build_pm_item(query: str, response: dict, saved_path: str) -> dict:
    created_ts = int(os.path.getmtime(saved_path))
    return {
        "id":              slugify(query) + "-" + dt.datetime.fromtimestamp(created_ts).strftime("%Y%m%d%H%M%S"),
        "query":           query,
        "slug":            slugify(query),
        "depth":           response.get("depth", "medium"),
        "summary":         response.get("summary", ""),
        "report":          response.get("report", ""),
        "matched_markets": response.get("matched_markets", []),
        "sources":         response.get("sources", []),
        "created_at":      dt.datetime.fromtimestamp(created_ts).isoformat(),
        "created_ts":      created_ts,
        "detail_url":      f"{SITE_BASE_URL}/polymarket.php?query={urllib.parse.quote(query)}",
        "_saved_path":        saved_path,
    }


# ---------------------------------------------------------------------------
# Remote registration
# ---------------------------------------------------------------------------

def register_remote(query: str, response: dict) -> dict:
    payload = {
        "query":           query,
        "depth":           response.get("depth", "medium"),
        "report":          response.get("report", ""),
        "summary":         response.get("summary", ""),
        "matched_markets": response.get("matched_markets", []),
        "sources":         response.get("sources", []),
        "created_at":      dt.datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
    }
    return http_json(POLYMARKET_SAVE_URL, payload=payload, timeout=120)


def register_saved_remote(saved_path: str) -> dict:
    payload = load_saved_report(saved_path)
    if not payload:
        return {"ok": False, "error": "saved report not found"}
    return http_json(POLYMARKET_SAVE_URL, payload=payload, timeout=120)


# ---------------------------------------------------------------------------
# Ollama helpers
# ---------------------------------------------------------------------------

def call_ollama(prompt: str, timeout: int = 300) -> str:
    payload = {"model": OLLAMA_MODEL, "prompt": prompt, "stream": False}
    res = http_json(OLLAMA_API, payload=payload, timeout=timeout)
    return (res.get("response") or "").strip()


def generate_polymarket_report(query: str, depth: str = "medium") -> dict:
    return http_json(POLYMARKET_API, payload={"query": query, "depth": depth}, timeout=600)


# ---------------------------------------------------------------------------
# Main processing loop
# ---------------------------------------------------------------------------

def process_candidates(dry_run: bool) -> int:
    state = load_state()
    today = dt.datetime.now().strftime("%Y-%m-%d")
    if state.get("last_run_date") != today:
        state["last_run_date"] = today
        state["queries_today"] = []

    processed_slugs = set(state.get("processed_slugs", []))
    queries_today   = set(state.get("queries_today", []))

    try:
        pool = fetch_top_markets(FETCH_POOL_SIZE)
    except Exception as exc:
        log(f"fetch top markets failed: {exc}")
        return 0
    log(f"fetched {len(pool)} markets from Gamma API")

    # Walk down the volume-ranked list, skipping already-processed slugs
    candidates = []
    for m in pool:
        if len(candidates) >= MAX_CANDIDATES_PER_RUN:
            break
        slug  = m.get("slug", "")
        query = (m.get("question") or m.get("title") or "").strip()
        if not slug or not query:
            continue
        if slug in processed_slugs:
            log(f"skip processed slug: {slug}")
            continue
        if query.lower() in queries_today or report_exists_today(query):
            log(f"skip duplicate query: {query}")
            continue
        candidates.append({"slug": slug, "query": query})

    if not candidates:
        log("no fresh markets to report")
        return 0
    log(f"selected {len(candidates)} candidates")

    created = 0
    generated_items: list[dict] = []
    for item in candidates:
        if created >= MAX_ITEMS_PER_DAY:
            break
        slug  = item["slug"]
        query = item["query"]
        processed_slugs.add(slug)

        if dry_run:
            log(f"dry-run candidate: {query}  (slug={slug})")
            queries_today.add(query.lower())
            created += 1
            continue

        try:
            result = generate_polymarket_report(query)
        except Exception as exc:
            log(f"generate failed for {query}: {exc}")
            continue
        if not result.get("report"):
            log(f"empty report for {query}")
            continue
        if not result.get("matched_markets"):
            log(f"no matched markets for {query}, skipping")
            continue

        path        = save_polymarketreport(query, result)
        local_item  = build_pm_item(query, result, path)
        report_item = local_item
        if POLYMARKET_REGISTER_REMOTE:
            try:
                remote_res  = register_remote(query, result)
                remote_item = remote_res.get("item") if isinstance(remote_res, dict) else None
                if isinstance(remote_item, dict) and remote_item.get("id"):
                    report_item = remote_item
                    report_item["_saved_path"] = path
                else:
                    log(f"remote register failed for {query}: {remote_res}")
            except Exception as exc:
                log(f"remote register error for {query}: {exc}")
        log(f"registered report: {query} -> {report_item.get('detail_url', '')}")
        generated_items.append(report_item)
        queries_today.add(query.lower())
        created += 1

    # Rolling window: keep last 500 slugs (~125 days at 4/day)
    state["processed_slugs"] = list(processed_slugs)[-500:]
    state["queries_today"]   = list(queries_today)
    save_state(state)

    return created


def should_run_now(now: dt.datetime) -> bool:
    state = load_state()
    for h, m in RUN_TIMES_JST:
        if now.hour == h and now.minute >= m:
            slot = f"{now.strftime('%Y-%m-%d')}-{h:02d}{m:02d}"
            return state.get("last_run_slot") != slot
    return False


def mark_run_slot(now: dt.datetime) -> None:
    state = load_state()
    for h, m in RUN_TIMES_JST:
        if now.hour == h and now.minute >= m:
            state["last_run_slot"] = f"{now.strftime('%Y-%m-%d')}-{h:02d}{m:02d}"
            break
    save_state(state)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--once",     action="store_true", help="Run one cycle immediately")
    parser.add_argument("--dry-run",  action="store_true", help="Discover candidates without generating reports")
    parser.add_argument("--interval", type=int, default=600, help="Loop interval in seconds")
    args = parser.parse_args()

    if args.once:
        n = process_candidates(dry_run=args.dry_run)
        report_worker("polymarket_worker", "ok", n or 0, "once完了 %d件" % (n or 0))
        return 0

    log(f"watching schedule; run_times={RUN_TIMES_JST} interval={args.interval}s dry_run={args.dry_run}")
    while True:
        now = dt.datetime.now()
        try:
            if should_run_now(now):
                report_worker("polymarket_worker", "running", 0, "実行中")
                n = process_candidates(dry_run=args.dry_run)
                report_worker("polymarket_worker", "ok", n or 0, "完了 %d件" % (n or 0))
                mark_run_slot(now)
            else:
                log("idle")
        except Exception as exc:
            log(f"error: {exc}")
            report_worker("polymarket_worker", "error", 0, str(exc)[:80])
        time.sleep(max(args.interval, 60))


if __name__ == "__main__":
    sys.exit(main())
