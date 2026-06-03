from __future__ import annotations

import datetime as dt
import os
from typing import Any

import polymarket_worker


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


def ollama_resource() -> dict[str, str]:
    endpoint = str(getattr(polymarket_worker, "OLLAMA_API", ""))
    model = str(getattr(polymarket_worker, "OLLAMA_MODEL", ""))
    host = os.environ.get("POLYMARKET_OLLAMA_HOST", "192.168.0.14").strip()
    return {
        "resource": "ollama",
        "resource_key": f"ollama:{host}:{model}",
        "ollama_host": host,
        "ollama_endpoint": endpoint,
        "ollama_model": model,
    }


def generate_report_job(
    query: str,
    depth: str = "medium",
    source: str = "web",
    register_remote: bool = True,
    post_paragraph: bool = False,
    dry_run: bool = False,
    **_meta: Any,
) -> dict[str, Any]:
    if post_paragraph:
        raise RuntimeError("Paragraph posting is disabled for Polymarket jobs")
    query = (query or "").strip()
    depth = (depth or "medium").strip()
    if not query:
        raise ValueError("query is required")
    if dry_run:
        return standard_result(
            ok=True,
            status="ok",
            items=0,
            metrics={"created": 0, "dry_run": 1},
            note=f"dry_run query={query}",
            **ollama_resource(),
            source=source,
            query=query,
            depth=depth,
            dry_run=True,
            registered_remote=False,
            paragraph_posted=False,
            created_at=dt.datetime.now(dt.timezone.utc).isoformat(),
        )

    result = polymarket_worker.generate_polymarket_report(query, depth=depth)
    if not result.get("report"):
        raise RuntimeError("Polymarket report generation returned no report")
    ok, reason = polymarket_worker.is_actionable_report(result, query)
    if not ok:
        raise RuntimeError(f"Polymarket report is not actionable: {reason}")

    path = polymarket_worker.save_polymarketreport(query, result)
    item = polymarket_worker.build_pm_item(query, result, path)
    remote = None

    if register_remote:
        remote = polymarket_worker.register_saved_remote(path)
        remote_item = remote.get("item") if isinstance(remote, dict) else None
        if isinstance(remote_item, dict) and remote_item.get("id"):
            item.update(remote_item)
        elif isinstance(remote, dict) and not remote.get("ok"):
            raise RuntimeError(f"remote register failed: {remote}")

    return standard_result(
        ok=True,
        status="ok",
        items=1,
        metrics={"created": 1, "matched_market_count": len(result.get("matched_markets") or [])},
        note=f"Polymarket report created: {query}",
        artifacts=[{"type": "url", "label": "detail", "url": item.get("detail_url", "")}],
        **ollama_resource(),
        source=source,
        query=query,
        depth=depth,
        saved_path=path,
        detail_url=item.get("detail_url", ""),
        summary=result.get("summary", ""),
        matched_market_count=len(result.get("matched_markets") or []),
        registered_remote=bool(register_remote),
        paragraph_posted=False,
        created_at=dt.datetime.now(dt.timezone.utc).isoformat(),
    )


def worker_auto_cycle_job(dry_run: bool = False, **_meta: Any) -> dict[str, Any]:
    created = polymarket_worker.process_candidates(dry_run=bool(dry_run))
    return standard_result(
        ok=True,
        status="ok",
        items=created,
        metrics={"created": created},
        note=f"Polymarket auto cycle created={created}",
        **ollama_resource(),
        source="worker_auto",
        dry_run=bool(dry_run),
        created=created,
        created_at=dt.datetime.now(dt.timezone.utc).isoformat(),
    )
