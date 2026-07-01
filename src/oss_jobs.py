from __future__ import annotations

import datetime as dt
import os
from typing import Any

import oss_worker


def standard_result(
    *,
    ok: bool,
    status: str,
    items: int = 0,
    metrics: dict[str, Any] | None = None,
    note: str = "",
    artifacts: list[dict[str, Any]] | None = None,
    error: Any = None,
    **extra: Any,
) -> dict[str, Any]:
    result = {
        "ok": bool(ok),
        "status": status,
        "items": int(items or 0),
        "metrics": metrics or {},
        "note": note,
        "artifacts": artifacts or [],
        "error": error,
    }
    result.update(extra)
    return result


def ai_resource() -> dict[str, str]:
    return dict(oss_worker.ai_resource())


def generate_register_job(
    github_url: str,
    source: str = "web",
    dry_run: bool = False,
    ai_provider: str = "",
    ai_model: str = "",
    claude_bin: str = "",
    **_meta: Any,
) -> dict[str, Any]:
    oss_worker.configure_ai_provider(ai_provider, ai_model, claude_bin)
    github_url = (github_url or "").strip()
    if "github.com/" not in github_url:
        raise ValueError("github_url must be a GitHub repository URL")

    repo_id = ""
    try:
        parts = github_url.split("github.com/", 1)[1].strip("/").split("/")
        if len(parts) >= 2:
            repo_id = f"{parts[0]}_{parts[1].split('?')[0].split('#')[0]}"
            repo_id = "".join(ch if ch.isalnum() or ch in "-_" else "-" for ch in repo_id)
    except Exception:
        repo_id = ""

    if not dry_run and oss_worker.check_exists(github_url):
        detail_url = f"https://aiknowledgecms.exbridge.jp/oss.php?id={repo_id}" if repo_id else "https://aiknowledgecms.exbridge.jp/oss.php"
        return standard_result(
            ok=True,
            status="warn",
            items=0,
            metrics={"created": 0, "duplicate": 1, "remote_status": "duplicate"},
            note=f"OSS already registered: {github_url}",
            artifacts=[
                {"type": "url", "label": "github", "url": github_url},
                {"type": "url", "label": "oss_detail", "url": detail_url},
            ],
            **ai_resource(),
            source=source,
            github_url=github_url,
            remote_status="duplicate",
            id=repo_id,
            created_at=dt.datetime.now(dt.timezone.utc).isoformat(),
        )

    readme = oss_worker.fetch_github_readme(github_url)
    fallback = github_url.replace("https://github.com/", "").strip("/")
    title = oss_worker.extract_title_from_readme(readme, fallback) if readme else fallback
    snippet = ""

    if dry_run:
        return standard_result(
            ok=True,
            status="ok",
            items=0,
            metrics={"created": 0, "dry_run": 1},
            note=f"dry_run OSS title={title}",
            **ai_resource(),
            source=source,
            github_url=github_url,
            title=title,
            dry_run=True,
            created_at=dt.datetime.now(dt.timezone.utc).isoformat(),
        )

    analysis = oss_worker.make_analysis(title, github_url, readme, snippet)
    post_text = oss_worker.strip_hashtags_from_text(oss_worker.make_post_text(title, github_url, readme, snippet))
    if not analysis or not post_text:
        raise RuntimeError("OSS AI generation returned empty text")

    tags = []
    post_full = post_text.rstrip() + "\n" + github_url
    result = oss_worker.save_to_cms(title, github_url, analysis, post_full, tags)
    status = result.get("status", "")
    if status not in {"ok", "updated", "duplicate"}:
        raise RuntimeError(f"saveoss failed: {result}")

    if status == "duplicate":
        return standard_result(
            ok=True,
            status="warn",
            items=0,
            metrics={"created": 0, "duplicate": 1, "remote_status": status},
            note=f"OSS already registered after save attempt: {github_url}",
            artifacts=[{"type": "url", "label": "github", "url": github_url}],
            **ai_resource(),
            source=source,
            github_url=github_url,
            title=title,
            remote_status=status,
            id=result.get("id", repo_id),
            sns_notice=result.get("sns_notice"),
            created_at=dt.datetime.now(dt.timezone.utc).isoformat(),
        )

    created = 1 if status in {"ok", "updated"} else 0
    return standard_result(
        ok=True,
        status="ok",
        items=created,
        metrics={"created": created, "remote_status": status},
        note=f"OSS registered status={status} title={title}",
        artifacts=[{"type": "url", "label": "github", "url": github_url}],
        **ai_resource(),
        source=source,
        github_url=github_url,
        title=title,
        remote_status=status,
        id=result.get("id", ""),
        sns_notice=result.get("sns_notice"),
        created_at=dt.datetime.now(dt.timezone.utc).isoformat(),
    )


def worker_auto_cycle_job(
    period: str = "daily",
    top_n: int | None = None,
    dry_run: bool = False,
    ai_provider: str = "",
    ai_model: str = "",
    claude_bin: str = "",
    **_meta: Any,
) -> dict[str, Any]:
    oss_worker.configure_ai_provider(ai_provider, ai_model, claude_bin)
    period = (period or "daily").strip()
    if period not in {"daily", "weekly", "monthly"}:
        raise ValueError("period must be daily, weekly, or monthly")
    if top_n is None:
        top_n = oss_worker.WEEKLY_TOP_N if period == "weekly" else oss_worker.DAILY_TOP_N
    top_n = max(1, min(10, int(top_n)))
    attempts: list[dict[str, Any]] = []
    created = 0
    if not dry_run:
        periods = [period]
        if period == "daily":
            periods.extend(["weekly", "monthly"])
        for attempt_period in periods:
            created = oss_worker.run_job(period=attempt_period, top_n=top_n)
            attempts.append({"period": attempt_period, "created": created})
            if created > 0:
                period = attempt_period
                break
    return standard_result(
        ok=True,
        status="ok",
        items=created,
        metrics={"created": created, "top_n": top_n, "period": period},
        note=f"OSS auto cycle created={created} period={period} top_n={top_n}",
        **ai_resource(),
        source="worker_auto",
        period=period,
        top_n=top_n,
        dry_run=bool(dry_run),
        created=created,
        attempts=attempts,
        created_at=dt.datetime.now(dt.timezone.utc).isoformat(),
    )
