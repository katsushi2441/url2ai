import importlib.util
import asyncio
import json
import os
from pathlib import Path
from typing import Any

import httpx
from fastapi import FastAPI, HTTPException, Request, Response


HOST = os.getenv("HOST", "0.0.0.0")
PORT = int(os.getenv("PORT", "8010"))
ERNIE_BASE_URL = os.getenv("ERNIE_BASE_URL", "http://127.0.0.1:8011").rstrip("/")
ERNIE_REQUEST_TIMEOUT = float(os.getenv("ERNIE_REQUEST_TIMEOUT", "900"))
RQDB4AI_URL = os.getenv("RQDB4AI_URL", "http://192.168.0.3:18300").rstrip("/")
RQDB4AI_TOKEN = os.getenv("RQDB4AI_TOKEN", "").strip()
RQDB4AI_ERNIE_QUEUE = os.getenv("RQDB4AI_ERNIE_QUEUE", "ernie-192-168-0-11-web").strip()
RQDB4AI_ERNIE_FUNCTION = os.getenv("RQDB4AI_ERNIE_FUNCTION", "url2ai_ernie_jobs.generate_image_job").strip()
RQDB4AI_POLL_INTERVAL = max(0.2, float(os.getenv("RQDB4AI_POLL_INTERVAL", "2")))
RQDB4AI_WAIT_TIMEOUT = max(30.0, float(os.getenv("RQDB4AI_WAIT_TIMEOUT", "1200")))
PDF_BASE_URL = os.getenv("PDF_BASE_URL", "").rstrip("/")
PDF_REQUEST_TIMEOUT = float(os.getenv("PDF_REQUEST_TIMEOUT", "900"))
BASE_DIR = Path(__file__).resolve().parent


def load_module(name: str, relative_path: str):
    path = BASE_DIR / relative_path
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Unable to load module from {path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


app = FastAPI(title="url2ai API Gateway", version="0.1.0")


def rqdb4ai_headers() -> dict[str, str]:
    if not RQDB4AI_TOKEN:
        raise HTTPException(status_code=503, detail="RQDB4AI token is not configured")
    return {"Authorization": f"Bearer {RQDB4AI_TOKEN}", "Content-Type": "application/json"}


async def queued_ernie_generate(request: Request) -> Response:
    """Run ERNIE through the central RQDB4AI queue; never bypass the queue."""
    try:
        payload = await request.json()
    except Exception as exc:
        raise HTTPException(status_code=400, detail=f"invalid JSON body: {exc}") from exc
    if not isinstance(payload, dict):
        raise HTTPException(status_code=400, detail="JSON object is required")

    enqueue_payload: dict[str, Any] = {
        "queue": RQDB4AI_ERNIE_QUEUE,
        "function": RQDB4AI_ERNIE_FUNCTION,
        "kwargs": {
            "payload": payload,
            "ernie_api": ERNIE_BASE_URL + "/generate",
            "request_timeout": int(ERNIE_REQUEST_TIMEOUT),
            "source": "url2ai_gateway",
        },
        "meta": {
            "project": "url2ai",
            "app": "url2ai-api-gateway",
            "kind": "image_generation",
            "resource": "ernie",
            "resource_key": "ernie:192.168.0.11:18300",
            "resource_host": "192.168.0.11",
            "source": "web_online",
            "queue_class": "web",
            "priority_class": "interactive",
        },
        "timeout": int(ERNIE_REQUEST_TIMEOUT) + 60,
        "result_ttl": 600,
        "failure_ttl": 604800,
    }
    headers = rqdb4ai_headers()
    api_timeout = httpx.Timeout(30.0, connect=10.0)
    try:
        async with httpx.AsyncClient(timeout=api_timeout) as client:
            response = await client.post(f"{RQDB4AI_URL}/api/enqueue", json=enqueue_payload, headers=headers)
            response.raise_for_status()
            queued = response.json()
    except (httpx.RequestError, httpx.HTTPStatusError, ValueError) as exc:
        raise HTTPException(status_code=503, detail=f"RQDB4AI enqueue failed: {exc}") from exc

    job_id = str((queued.get("job") or {}).get("id") or "")
    if not job_id:
        raise HTTPException(status_code=502, detail="RQDB4AI enqueue returned no job id")

    deadline = asyncio.get_running_loop().time() + RQDB4AI_WAIT_TIMEOUT
    status = "queued"
    while asyncio.get_running_loop().time() < deadline:
        if await request.is_disconnected():
            raise HTTPException(status_code=499, detail=f"client disconnected; RQDB4AI job={job_id}")
        await asyncio.sleep(RQDB4AI_POLL_INTERVAL)
        try:
            async with httpx.AsyncClient(timeout=api_timeout) as client:
                detail_response = await client.get(f"{RQDB4AI_URL}/api/jobs/{job_id}", headers=headers)
                detail_response.raise_for_status()
                detail = detail_response.json().get("job") or {}
        except (httpx.RequestError, httpx.HTTPStatusError, ValueError) as exc:
            raise HTTPException(status_code=502, detail=f"RQDB4AI status failed for {job_id}: {exc}") from exc
        status = str(detail.get("status") or "unknown")
        if status == "finished":
            try:
                async with httpx.AsyncClient(timeout=api_timeout) as client:
                    result_response = await client.get(f"{RQDB4AI_URL}/api/jobs/{job_id}/result", headers=headers)
                    result_response.raise_for_status()
                    result = result_response.json().get("result")
            except (httpx.RequestError, httpx.HTTPStatusError, ValueError) as exc:
                raise HTTPException(status_code=502, detail=f"RQDB4AI result failed for {job_id}: {exc}") from exc
            if not isinstance(result, dict) or not result.get("image_base64"):
                raise HTTPException(status_code=502, detail=f"RQDB4AI returned invalid ERNIE result: {job_id}")
            return Response(
                content=json.dumps(result, ensure_ascii=False),
                media_type="application/json",
                headers={"X-RQDB4AI-Job-Id": job_id, "X-RQDB4AI-Queue": RQDB4AI_ERNIE_QUEUE},
            )
        if status in {"failed", "stopped", "canceled"}:
            error = ((detail.get("error") or {}).get("message") or detail.get("exc_info") or status)
            raise HTTPException(status_code=502, detail=f"ERNIE RQDB4AI job {job_id} {status}: {str(error)[:500]}")
    raise HTTPException(status_code=504, detail=f"ERNIE RQDB4AI job timed out: {job_id} status={status}")


async def proxy_request(
    base_url: str,
    path: str,
    request: Request,
    timeout_seconds: float,
    service_name: str,
) -> Response:
    target_url = f"{base_url}/{path}"
    if request.url.query:
        target_url = f"{target_url}?{request.url.query}"

    headers = {}
    for header_name in ("accept", "content-type"):
        if header_name in request.headers:
            headers[header_name] = request.headers[header_name]

    timeout = httpx.Timeout(timeout_seconds, connect=10.0)
    try:
        async with httpx.AsyncClient(timeout=timeout) as client:
            upstream = await client.request(
                request.method,
                target_url,
                content=await request.body(),
                headers=headers,
            )
    except httpx.RequestError as exc:
        raise HTTPException(
            status_code=502,
            detail=f"{service_name} is unavailable: {exc}",
        ) from exc

    response_headers = {}
    if "content-type" in upstream.headers:
        response_headers["content-type"] = upstream.headers["content-type"]
    return Response(
        content=upstream.content,
        status_code=upstream.status_code,
        headers=response_headers,
    )


if PDF_BASE_URL:
    @app.api_route("/pdf/{path:path}", methods=["GET", "POST"])
    async def proxy_pdf(path: str, request: Request) -> Response:
        return await proxy_request(
            PDF_BASE_URL,
            path,
            request,
            PDF_REQUEST_TIMEOUT,
            "PDF service",
        )
else:
    updf2md_module = load_module("updf2md_server", "updf2md/server.py")
    app.include_router(updf2md_module.router, prefix="/pdf", tags=["pdf"])


@app.api_route("/image/{path:path}", methods=["GET", "POST"])
async def proxy_ernie_image(path: str, request: Request) -> Response:
    """Queue generation while preserving direct health and metadata routes."""
    if request.method == "POST" and path.strip("/") == "generate":
        return await queued_ernie_generate(request)
    return await proxy_request(
        ERNIE_BASE_URL,
        path,
        request,
        ERNIE_REQUEST_TIMEOUT,
        "ERNIE image service",
    )


@app.get("/healthz")
def healthz() -> dict:
    return {
        "ok": True,
        "service": "url2ai-api-gateway",
        "routes": {
            "image": "/image (proxied to the ERNIE service)",
            "pdf": "/pdf",
        },
        "ernie_base_url": ERNIE_BASE_URL,
        "ernie_execution": "rqdb4ai",
        "ernie_queue": RQDB4AI_ERNIE_QUEUE,
        "rqdb4ai_url": RQDB4AI_URL,
        "rqdb4ai_token_configured": bool(RQDB4AI_TOKEN),
        "pdf_base_url": PDF_BASE_URL or "local",
    }


if __name__ == "__main__":
    import uvicorn

    uvicorn.run("api_gateway:app", host=HOST, port=PORT)
