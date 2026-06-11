from __future__ import annotations

import datetime as dt
import json
import subprocess
from typing import Any

from oss_jobs import standard_result

AINEWS_API_URL = "https://aiknowledgecms.exbridge.jp/saveainews.php"
AITECH_API_URL = "https://aiknowledgecms.exbridge.jp/saveaitech.php"


def _post_json(url: str, payload: dict[str, Any]) -> dict[str, Any]:
    body = json.dumps(payload, ensure_ascii=False)
    result = subprocess.run(
        [
            "curl",
            "-sS",
            "--max-time",
            "180",
            url,
            "-H",
            "Content-Type: application/json",
            "-d",
            body,
        ],
        capture_output=True,
        text=True,
        timeout=190,
    )
    raw = (result.stdout or "").strip()
    if result.returncode != 0:
        raise RuntimeError(f"register request failed: {result.stderr.strip()[:300]}")
    try:
        data = json.loads(raw)
    except Exception as exc:
        raise RuntimeError(f"register response invalid json: {raw[:300]}") from exc
    return data


def ainews_register_job(tweet_url: str, worker_issued_at: int, worker_sig: str, dry_run: bool = False, **_meta: Any) -> dict[str, Any]:
    tweet_url = (tweet_url or "").strip()
    if not tweet_url:
        raise ValueError("tweet_url is required")
    if dry_run:
        return standard_result(
            ok=True,
            status="ok",
            items=0,
            metrics={"created": 0, "dry_run": 1},
            note=f"dry_run AINews register {tweet_url}",
            artifacts=[{"type": "url", "label": "source", "url": tweet_url}],
            app="ainews",
            resource="web",
            resource_key="ainews:register",
            dry_run=True,
            created_at=dt.datetime.now(dt.timezone.utc).isoformat(),
        )
    res = _post_json(AINEWS_API_URL, {
        "action": "register",
        "tweet_url": tweet_url,
        "worker_issued_at": int(worker_issued_at),
        "worker_sig": worker_sig,
    })
    status = res.get("status", "")
    if status not in {"ok", "duplicate"}:
        raise RuntimeError(f"saveainews failed: {res}")
    created = 1 if status == "ok" else 0
    return standard_result(
        ok=True,
        status="ok" if status == "ok" else "warn",
        items=created,
        metrics={"created": created, "duplicate": 1 if status == "duplicate" else 0, "remote_status": status},
        note=f"AINews register status={status} title={res.get('title', '')}",
        artifacts=[{"type": "url", "label": "source", "url": tweet_url}],
        app="ainews",
        resource="web",
        resource_key="ainews:register",
        tweet_url=tweet_url,
        title=res.get("title", ""),
        remote_status=status,
        created_at=dt.datetime.now(dt.timezone.utc).isoformat(),
    )


def aitech_register_job(url: str, worker_issued_at: int, worker_sig: str, dry_run: bool = False, **_meta: Any) -> dict[str, Any]:
    url = (url or "").strip()
    if not url:
        raise ValueError("url is required")
    if dry_run:
        return standard_result(
            ok=True,
            status="ok",
            items=0,
            metrics={"created": 0, "dry_run": 1},
            note=f"dry_run AITech register {url}",
            artifacts=[{"type": "url", "label": "source", "url": url}],
            app="aitech",
            resource="web",
            resource_key="aitech:register",
            dry_run=True,
            created_at=dt.datetime.now(dt.timezone.utc).isoformat(),
        )
    res = _post_json(AITECH_API_URL, {
        "action": "register",
        "url": url,
        "worker_issued_at": int(worker_issued_at),
        "worker_sig": worker_sig,
    })
    status = res.get("status", "")
    if status not in {"ok", "duplicate"}:
        raise RuntimeError(f"saveaitech failed: {res}")
    created = 1 if status == "ok" else 0
    detail_url = ""
    if res.get("id"):
        detail_url = "https://aiknowledgecms.exbridge.jp/aitech.php?id=" + str(res.get("id"))
    artifacts = [{"type": "url", "label": "source", "url": url}]
    if detail_url:
        artifacts.append({"type": "url", "label": "detail", "url": detail_url})
    return standard_result(
        ok=True,
        status="ok" if status == "ok" else "warn",
        items=created,
        metrics={"created": created, "duplicate": 1 if status == "duplicate" else 0, "remote_status": status},
        note=f"AITech register status={status} title={res.get('title', '')}",
        artifacts=artifacts,
        app="aitech",
        resource="web",
        resource_key="aitech:register",
        url=url,
        title=res.get("title", ""),
        id=res.get("id", ""),
        remote_status=status,
        created_at=dt.datetime.now(dt.timezone.utc).isoformat(),
    )
