import base64
import io
import os
from threading import Lock
from typing import Optional

# Image generation must not starve SSH and the other production services on
# this shared 16-thread host.  Keep BLAS/OpenMP bounded before importing torch.
CPU_THREADS = max(1, int(os.getenv("ERNIE_CPU_THREADS", "4")))
CPU_INTEROP_THREADS = max(1, int(os.getenv("ERNIE_CPU_INTEROP_THREADS", "1")))
CPU_NICE = max(0, min(19, int(os.getenv("ERNIE_CPU_NICE", "10"))))
os.environ.setdefault("OMP_NUM_THREADS", str(CPU_THREADS))
os.environ.setdefault("MKL_NUM_THREADS", str(CPU_THREADS))

import torch
from fastapi import APIRouter, FastAPI, HTTPException
from pydantic import BaseModel, Field
from diffusers import ErnieImagePipeline
from PIL import Image


MODEL_ID = os.getenv("MODEL_ID", "baidu/ERNIE-Image-Turbo")
DEVICE = os.getenv("DEVICE", "cuda")
HOST = os.getenv("HOST", "0.0.0.0")
PORT = int(os.getenv("PORT", "8011"))
ENABLE_CPU_OFFLOAD = os.getenv("ENABLE_CPU_OFFLOAD", "1") == "1"
# 後方互換: OFFLOAD_MODE未指定なら従来のENABLE_CPU_OFFLOADに従う
OFFLOAD_MODE = os.getenv("OFFLOAD_MODE", "sequential" if ENABLE_CPU_OFFLOAD else "none")

_pipeline = None
_pipeline_lock = Lock()
_generate_lock = Lock()

torch.set_num_threads(CPU_THREADS)
try:
    torch.set_num_interop_threads(CPU_INTEROP_THREADS)
except RuntimeError:
    # A hosting process may already have initialized the interop pool.
    pass
if CPU_NICE:
    os.nice(CPU_NICE)


class GenerateRequest(BaseModel):
    prompt: str = Field(..., min_length=1)
    negative_prompt: Optional[str] = None
    width: int = Field(848, ge=256, le=2048)
    height: int = Field(1264, ge=256, le=2048)
    num_inference_steps: int = Field(8, ge=1, le=100)
    guidance_scale: float = Field(1.0, ge=0.0, le=20.0)
    use_pe: bool = False
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
            pipe = ErnieImagePipeline.from_pretrained(
                MODEL_ID,
                torch_dtype=dtype,
            )
            pipe.set_progress_bar_config(disable=True)
            pipe.enable_attention_slicing("max")
            if hasattr(pipe, "enable_vae_slicing"):
                pipe.enable_vae_slicing()
            if hasattr(pipe, "enable_vae_tiling"):
                pipe.enable_vae_tiling()

            # OFFLOAD_MODE:
            #   sequential(既定) … VRAM~1.2GB。常駐他サービス(Ollama/Audio8等)と安全に同居
            #                      できるが、CPU/PCIe往復が支配的で高負荷時は1枚10分超を実測。
            #   model            … VRAM~10.5GB常駐で大幅高速。ただしgemma4(8.7GB)が同時に
            #                      載っているとOOMする(2026-07-31実測)ので、呼び出し側が
            #                      keep_alive=0等でVRAMを空けられる場合のみ使う。
            #   none             … フルGPU。単独占有できる環境向け。
            if DEVICE == "cuda" and torch.cuda.is_available() and OFFLOAD_MODE == "sequential":
                pipe.enable_sequential_cpu_offload()
            elif DEVICE == "cuda" and torch.cuda.is_available() and OFFLOAD_MODE == "model":
                pipe.enable_model_cpu_offload()
            else:
                pipe = pipe.to(DEVICE)

            _pipeline = pipe
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
        "cpu_threads": torch.get_num_threads(),
        "cpu_interop_threads": torch.get_num_interop_threads(),
        "cpu_nice": os.getpriority(os.PRIO_PROCESS, 0),
        "offload_mode": OFFLOAD_MODE,
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

        # Diffusers pipelines are not thread-safe. FastAPI runs sync handlers
        # in a thread pool, so concurrent requests must be serialized here.
        with _generate_lock:
            def _run():
                return pipe(
                    prompt=req.prompt,
                    negative_prompt=req.negative_prompt,
                    width=req.width,
                    height=req.height,
                    num_inference_steps=req.num_inference_steps,
                    guidance_scale=req.guidance_scale,
                    use_pe=req.use_pe,
                    generator=generator,
                )
            try:
                result = _run()
            except torch.cuda.OutOfMemoryError:
                # 他プロセス(Ollama等)との一時的なVRAM競合。キャッシュを捨てて1回だけ
                # やり直す。それでも駄目なら503で返し、呼び出し側にリトライさせる。
                torch.cuda.empty_cache()
                try:
                    result = _run()
                except torch.cuda.OutOfMemoryError as oom:
                    torch.cuda.empty_cache()
                    raise HTTPException(status_code=503, detail=f"GPU busy (OOM): {oom}") from oom
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
