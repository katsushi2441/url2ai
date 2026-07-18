import importlib.util
import os
from pathlib import Path

import httpx
from fastapi import FastAPI, HTTPException, Request, Response


HOST = os.getenv("HOST", "0.0.0.0")
PORT = int(os.getenv("PORT", "8010"))
ERNIE_BASE_URL = os.getenv("ERNIE_BASE_URL", "http://127.0.0.1:8011").rstrip("/")
ERNIE_REQUEST_TIMEOUT = float(os.getenv("ERNIE_REQUEST_TIMEOUT", "900"))
BASE_DIR = Path(__file__).resolve().parent


def load_module(name: str, relative_path: str):
    path = BASE_DIR / relative_path
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Unable to load module from {path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


updf2md_module = load_module("updf2md_server", "updf2md/server.py")


app = FastAPI(title="url2ai API Gateway", version="0.1.0")
app.include_router(updf2md_module.router, prefix="/pdf", tags=["pdf"])


@app.api_route("/image/{path:path}", methods=["GET", "POST"])
async def proxy_ernie_image(path: str, request: Request) -> Response:
    """Preserve the gateway API while keeping one ERNIE model owner on port 8011."""
    target_url = f"{ERNIE_BASE_URL}/{path}"
    if request.url.query:
        target_url = f"{target_url}?{request.url.query}"

    headers = {}
    for header_name in ("accept", "content-type"):
        if header_name in request.headers:
            headers[header_name] = request.headers[header_name]

    timeout = httpx.Timeout(ERNIE_REQUEST_TIMEOUT, connect=10.0)
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
            detail=f"ERNIE image service is unavailable: {exc}",
        ) from exc

    response_headers = {}
    if "content-type" in upstream.headers:
        response_headers["content-type"] = upstream.headers["content-type"]
    return Response(
        content=upstream.content,
        status_code=upstream.status_code,
        headers=response_headers,
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
    }


if __name__ == "__main__":
    import uvicorn

    uvicorn.run("api_gateway:app", host=HOST, port=PORT)
