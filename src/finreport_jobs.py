from __future__ import annotations

import datetime as dt
import os
from typing import Any

import finreport_worker


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
    endpoint = str(getattr(finreport_worker, "OLLAMA_API", ""))
    model = str(getattr(finreport_worker, "OLLAMA_MODEL", ""))
    host = os.environ.get("FINREPORT_OLLAMA_HOST", "192.168.0.14").strip()
    return {
        "resource": "ollama",
        "resource_key": f"ollama:{host}:{model}",
        "ollama_host": host,
        "ollama_endpoint": endpoint,
        "ollama_model": model,
    }


def generate_report_job(
    ticker: str,
    source: str = "web",
    register_remote: bool = True,
    dry_run: bool = False,
    **_meta: Any,
) -> dict[str, Any]:
    ticker = (ticker or "").strip()
    if not ticker:
        raise ValueError("ticker is required")
    if dry_run:
        return standard_result(
            ok=True,
            status="ok",
            items=0,
            metrics={"created": 0, "dry_run": 1},
            note=f"dry_run ticker={ticker}",
            **ollama_resource(),
            source=source,
            ticker=ticker,
            dry_run=True,
            registered_remote=False,
            created_at=dt.datetime.now(dt.timezone.utc).isoformat(),
        )

    result = finreport_worker.generate_finreport(ticker)
    if not result.get("report"):
        raise RuntimeError("FinReport generation returned no report")

    source_item: dict[str, Any] = {}
    path = finreport_worker.save_finreport(ticker, result, source_item)
    item = finreport_worker.build_finreport_item(ticker, result, path)
    remote = None
    if register_remote:
        remote = finreport_worker.register_finreport_remote(ticker, result, source_item)
        remote_item = remote.get("item") if isinstance(remote, dict) else None
        if isinstance(remote_item, dict) and remote_item.get("id"):
            item.update(remote_item)
        elif isinstance(remote, dict) and not remote.get("ok"):
            raise RuntimeError(f"remote register failed: {remote}")

    return standard_result(
        ok=True,
        status="ok",
        items=1,
        metrics={"created": 1},
        note=f"FinReport created ticker={ticker}",
        artifacts=[{"type": "url", "label": "detail", "url": item.get("detail_url", "")}],
        **ollama_resource(),
        source=source,
        ticker=ticker,
        saved_path=path,
        detail_url=item.get("detail_url", ""),
        summary=result.get("summary", ""),
        registered_remote=bool(register_remote),
        created_at=dt.datetime.now(dt.timezone.utc).isoformat(),
    )


def worker_auto_cycle_job(dry_run: bool = False, **_meta: Any) -> dict[str, Any]:
    created = finreport_worker.process_candidates(dry_run=bool(dry_run))
    return standard_result(
        ok=True,
        status="ok",
        items=created,
        metrics={"created": created},
        note=f"FinReport auto cycle created={created}",
        **ollama_resource(),
        source="worker_auto",
        dry_run=bool(dry_run),
        created=created,
        created_at=dt.datetime.now(dt.timezone.utc).isoformat(),
    )
