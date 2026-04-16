import base64
import io
import os
from threading import Lock
from typing import Optional

import torch
from fastapi import APIRouter, FastAPI, HTTPException
from pydantic import BaseModel, Field
from diffusers import ErnieImagePipeline
from PIL import Image


MODEL_ID = os.getenv("MODEL_ID", "baidu/ERNIE-Image-Turbo")
DEVICE = os.getenv("DEVICE", "cuda")
HOST = os.getenv("HOST", "0.0.0.0")
PORT = int(os.getenv("PORT", "8010"))

_pipeline = None
_pipeline_lock = Lock()


class GenerateRequest(BaseModel):
    prompt: str = Field(..., min_length=1)
    negative_prompt: Optional[str] = None
    width: int = Field(848, ge=256, le=2048)
    height: int = Field(1264, ge=256, le=2048)
    num_inference_steps: int = Field(8, ge=1, le=100)
    guidance_scale: float = Field(1.0, ge=0.0, le=20.0)
    use_pe: bool = True
    seed: Optional[int] = None
    output_format: str = Field("png", pattern="^(png|jpeg)$")


def resolve_dtype() -> torch.dtype:
    if DEVICE == "cuda" and torch.cuda.is_available():
        if torch.cuda.is_bf16_supported():
            return torch.bfloat16
        return torch.float16
    return torch.float32


def get_pipeline() -> ErnieImagePipeline:
    global _pipeline
    if _pipeline is not None:
        return _pipeline

    with _pipeline_lock:
        if _pipeline is None:
            dtype = resolve_dtype()
            _pipeline = ErnieImagePipeline.from_pretrained(
                MODEL_ID,
                torch_dtype=dtype,
            )
            _pipeline = _pipeline.to(DEVICE)
        return _pipeline


def image_to_base64(image: Image.Image, output_format: str) -> str:
    buffer = io.BytesIO()
    image.save(buffer, format=output_format.upper())
    return base64.b64encode(buffer.getvalue()).decode("ascii")


router = APIRouter()


@router.get("/healthz")
def healthz() -> dict:
    gpu_name = None
    if torch.cuda.is_available():
        gpu_name = torch.cuda.get_device_name(0)
    return {
        "ok": True,
        "model_id": MODEL_ID,
        "device": DEVICE,
        "cuda_available": torch.cuda.is_available(),
        "gpu_name": gpu_name,
    }


@router.post("/generate")
def generate(req: GenerateRequest) -> dict:
    if DEVICE == "cuda" and not torch.cuda.is_available():
        raise HTTPException(status_code=500, detail="CUDA is not available on this host.")

    try:
        pipe = get_pipeline()
        generator = None
        used_seed = req.seed
        if used_seed is not None:
            generator = torch.Generator(device=DEVICE).manual_seed(used_seed)

        result = pipe(
            prompt=req.prompt,
            negative_prompt=req.negative_prompt,
            width=req.width,
            height=req.height,
            num_inference_steps=req.num_inference_steps,
            guidance_scale=req.guidance_scale,
            use_pe=req.use_pe,
            generator=generator,
        )
        image = result.images[0]
        return {
            "ok": True,
            "model_id": MODEL_ID,
            "width": req.width,
            "height": req.height,
            "num_inference_steps": req.num_inference_steps,
            "guidance_scale": req.guidance_scale,
            "use_pe": req.use_pe,
            "seed": used_seed,
            "output_format": req.output_format,
            "image_base64": image_to_base64(image, req.output_format),
        }
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc)) from exc


def create_app() -> FastAPI:
    app = FastAPI(title="ERNIE-Image-Turbo API", version="0.1.0")
    app.include_router(router)
    return app


app = create_app()


if __name__ == "__main__":
    import uvicorn

    uvicorn.run("server:app", host=HOST, port=PORT)
