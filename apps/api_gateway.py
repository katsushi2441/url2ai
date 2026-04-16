import importlib.util
import os
from pathlib import Path

from fastapi import FastAPI


HOST = os.getenv("HOST", "0.0.0.0")
PORT = int(os.getenv("PORT", "8010"))
BASE_DIR = Path(__file__).resolve().parent


def load_module(name: str, relative_path: str):
    path = BASE_DIR / relative_path
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Unable to load module from {path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


ernie_image_module = load_module("ernie_image_turbo_server", "ernie-image-turbo/server.py")
updf2md_module = load_module("updf2md_server", "updf2md/server.py")


app = FastAPI(title="url2ai API Gateway", version="0.1.0")
app.include_router(ernie_image_module.router, prefix="/image", tags=["image"])
app.include_router(updf2md_module.router, prefix="/pdf", tags=["pdf"])


@app.get("/healthz")
def healthz() -> dict:
    return {
        "ok": True,
        "service": "url2ai-api-gateway",
        "routes": {
            "image": "/image",
            "pdf": "/pdf",
        },
    }


if __name__ == "__main__":
    import uvicorn

    uvicorn.run("api_gateway:app", host=HOST, port=PORT)
