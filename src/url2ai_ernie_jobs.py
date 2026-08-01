from __future__ import annotations

import json
from typing import Any

import requests


def generate_image_job(
    payload: dict[str, Any],
    ernie_api: str = "http://192.168.0.11:18300/generate",
    request_timeout: int = 900,
    source: str = "rqdb4ai",
    **_: Any,
) -> dict[str, Any]:
    """RQDB4AI entrypoint for one serialized ERNIE image generation."""
    if not isinstance(payload, dict) or not str(payload.get("prompt") or "").strip():
        raise RuntimeError("ERNIE prompt is required")
    endpoint = str(ernie_api or "").rstrip("/")
    if not endpoint:
        raise RuntimeError("ernie_api is required")
    response = requests.post(endpoint, json=payload, timeout=(15, int(request_timeout)))
    if response.status_code >= 400:
        raise RuntimeError(
            json.dumps(
                {
                    "error": "ernie_http_error",
                    "status_code": response.status_code,
                    "detail": response.text[:1000],
                    "source": source,
                },
                ensure_ascii=False,
            )
        )
    data = response.json()
    if not isinstance(data, dict) or not data.get("image_base64"):
        raise RuntimeError("ERNIE returned no image_base64")
    return data
