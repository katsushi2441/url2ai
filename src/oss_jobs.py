from __future__ import annotations

import datetime as dt
import os
from typing import Any

import oss_worker


def ollama_resource() -> dict[str, str]:
    endpoint = str(getattr(oss_worker, "OLLAMA", ""))
    model = str(getattr(oss_worker, "MODEL", ""))
    host = os.environ.get("OSS_OLLAMA_HOST", "192.168.0.14").strip()
    return {
        "resource": "ollama",
        "resource_key": f"ollama:{host}:{model}",
        "ollama_host": host,
        "ollama_endpoint": endpoint,
        "ollama_model": model,
    }


def generate_register_job(
    github_url: str,
    source: str = "web",
    dry_run: bool = False,
    **_meta: Any,
) -> dict[str, Any]:
    github_url = (github_url or "").strip()
    if "github.com/" not in github_url:
        raise ValueError("github_url must be a GitHub repository URL")

    readme = oss_worker.fetch_github_readme(github_url)
    fallback = github_url.replace("https://github.com/", "").strip("/")
    title = oss_worker.extract_title_from_readme(readme, fallback) if readme else fallback
    snippet = ""

    if dry_run:
        return {
            "ok": True,
            **ollama_resource(),
            "source": source,
            "github_url": github_url,
            "title": title,
            "dry_run": True,
            "created_at": dt.datetime.now(dt.timezone.utc).isoformat(),
        }

    analysis = oss_worker.make_analysis(title, github_url, readme, snippet)
    post_text = oss_worker.make_post_text(title, github_url, readme, snippet)
    if not analysis or not post_text:
        raise RuntimeError("OSS AI generation returned empty text")

    tags = oss_worker.extract_tags(post_text, github_url)
    tag_str = " ".join("#" + t for t in tags)
    post_full = post_text.rstrip() + "\n" + tag_str + "\n" + github_url
    result = oss_worker.save_to_cms(title, github_url, analysis, post_full, tags)
    status = result.get("status", "")
    if status not in {"ok", "updated", "duplicate"}:
        raise RuntimeError(f"saveoss failed: {result}")

    return {
        "ok": True,
        **ollama_resource(),
        "source": source,
        "github_url": github_url,
        "title": title,
        "status": status,
        "id": result.get("id", ""),
        "sns_notice": result.get("sns_notice"),
        "created_at": dt.datetime.now(dt.timezone.utc).isoformat(),
    }


def worker_auto_cycle_job(
    period: str = "daily",
    top_n: int | None = None,
    dry_run: bool = False,
    **_meta: Any,
) -> dict[str, Any]:
    period = (period or "daily").strip()
    if period not in {"daily", "weekly", "monthly"}:
        raise ValueError("period must be daily, weekly, or monthly")
    if top_n is None:
        top_n = oss_worker.WEEKLY_TOP_N if period == "weekly" else oss_worker.DAILY_TOP_N
    top_n = max(1, min(10, int(top_n)))
    created = 0 if dry_run else oss_worker.run_job(period=period, top_n=top_n)
    return {
        "ok": True,
        **ollama_resource(),
        "source": "worker_auto",
        "period": period,
        "top_n": top_n,
        "dry_run": bool(dry_run),
        "created": created,
        "created_at": dt.datetime.now(dt.timezone.utc).isoformat(),
    }
