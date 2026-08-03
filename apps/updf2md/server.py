import io
import json
import os
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Annotated
from uuid import uuid4

import fitz  # pymupdf
import pdf_inspector
import pytesseract
from fastapi import APIRouter, FastAPI, File, Form, HTTPException, UploadFile
from fastapi.responses import JSONResponse
from PIL import Image
from pydantic import BaseModel

from finreport.core import generate_report_data


HOST = os.getenv("HOST", "0.0.0.0")
PORT = int(os.getenv("PORT", "8020"))
OUTPUT_DIR = Path(os.getenv("OUTPUT_DIR", "./outputs")).expanduser()
SAVE_BY_DEFAULT = os.getenv("SAVE_BY_DEFAULT", "false").lower() == "true"
OCR_LANG = os.getenv("OCR_LANG", "jpn+eng")
OCR_DPI = int(os.getenv("OCR_DPI", "200"))
# 横向き(90/270度)のページを検出して立て直してからOCRする。
# 用紙は縦なのに中身だけ回転しているPDF(パワポの横スライドをA4縦に出力したもの等)は
# そのままOCRすると全ページ判読不能な文字列になる。2026-08-03に実測で確認。
OCR_AUTO_ROTATE = os.getenv("OCR_AUTO_ROTATE", "true").lower() == "true"
# OSDの確信度がこれ未満なら回転させない。誤検出で正しい向きを崩さないため。
OCR_ROTATE_MIN_CONFIDENCE = float(os.getenv("OCR_ROTATE_MIN_CONFIDENCE", "1.5"))


class ConvertResponse(BaseModel):
    ok: bool
    request_id: str
    filename: str
    pdf_type: str
    confidence: float
    page_count: int
    processing_time_ms: int
    pages_needing_ocr: list[int]
    pages_with_tables: list[int]
    pages_with_columns: list[int]
    has_encoding_issues: bool
    is_complex_layout: bool
    title: str | None
    saved_markdown_path: str | None = None
    saved_metadata_path: str | None = None
    markdown: str | None = None
    ocr_applied: bool = False
    ocr_rotated_pages: dict[str, int] = {}


class ReportRequest(BaseModel):
    ticker: str


class ReportResponse(BaseModel):
    ok: bool
    ticker: str
    markdown: str
    summary: str
    sources: list[str]
    generated_at: str


def parse_pages(value: str | None) -> list[int] | None:
    if not value:
        return None
    pages: set[int] = set()
    for chunk in value.split(","):
        chunk = chunk.strip()
        if not chunk:
            continue
        if "-" in chunk:
            start_text, end_text = chunk.split("-", 1)
            start = int(start_text)
            end = int(end_text)
            if start < 1 or end < start:
                raise ValueError(f"Invalid page range: {chunk}")
            pages.update(range(start, end + 1))
            continue
        page = int(chunk)
        if page < 1:
            raise ValueError(f"Invalid page number: {chunk}")
        pages.add(page)
    return sorted(pages)


def detect_rotation(img: "Image.Image") -> int:
    """このページを正しい向きにするために必要な時計回りの角度を返す。

    tesseract の OSD は文字が少ないページで例外を投げるため、失敗は 0 度として
    扱う（回転させないほうが安全側）。
    """
    if not OCR_AUTO_ROTATE:
        return 0
    try:
        osd = pytesseract.image_to_osd(img, output_type=pytesseract.Output.DICT)
    except Exception:
        return 0
    try:
        confidence = float(osd.get("orientation_conf", 0) or 0)
        rotate = int(osd.get("rotate", 0) or 0) % 360
    except (TypeError, ValueError):
        return 0
    if rotate == 0 or confidence < OCR_ROTATE_MIN_CONFIDENCE:
        return 0
    return rotate


def ocr_pdf_bytes(data: bytes, selected_pages: list[int] | None = None) -> tuple[str, dict[int, int]]:
    doc = fitz.open(stream=data, filetype="pdf")
    texts = []
    rotations: dict[int, int] = {}
    total = doc.page_count
    page_indices = [p - 1 for p in selected_pages if 1 <= p <= total] if selected_pages else list(range(total))
    mat = fitz.Matrix(OCR_DPI / 72, OCR_DPI / 72)
    for i in page_indices:
        page = doc.load_page(i)
        pix = page.get_pixmap(matrix=mat)
        img = Image.frombytes("RGB", [pix.width, pix.height], pix.samples)
        rotate = detect_rotation(img)
        if rotate:
            # PIL の rotate は反時計回りなので符号を反転する
            img = img.rotate(-rotate, expand=True)
            rotations[i + 1] = rotate
        text = pytesseract.image_to_string(img, lang=OCR_LANG)
        texts.append(f"## Page {i + 1}\n\n{text.strip()}")
    doc.close()
    return "\n\n".join(texts), rotations


def build_metadata(result, filename: str, request_id: str) -> dict:
    return {
        "request_id": request_id,
        "filename": filename,
        "pdf_type": result.pdf_type,
        "confidence": result.confidence,
        "page_count": result.page_count,
        "processing_time_ms": result.processing_time_ms,
        "pages_needing_ocr": list(result.pages_needing_ocr or []),
        "pages_with_tables": list(result.pages_with_tables or []),
        "pages_with_columns": list(result.pages_with_columns or []),
        "has_encoding_issues": bool(result.has_encoding_issues),
        "is_complex_layout": bool(result.is_complex_layout),
        "title": result.title,
        "generated_at": datetime.now(timezone.utc).isoformat(),
    }


def persist_result(filename: str, markdown: str | None, metadata: dict) -> tuple[str, str]:
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    stem = Path(filename).stem or "document"
    safe_stem = "".join(ch if ch.isalnum() or ch in {"-", "_"} else "_" for ch in stem).strip("_") or "document"
    markdown_path = OUTPUT_DIR / f"{safe_stem}.md"
    metadata_path = OUTPUT_DIR / f"{safe_stem}.json"
    markdown_path.write_text(markdown or "", encoding="utf-8")
    metadata_path.write_text(json.dumps(metadata, ensure_ascii=False, indent=2), encoding="utf-8")
    return str(markdown_path.resolve()), str(metadata_path.resolve())


def process_and_respond(
    data: bytes,
    filename: str,
    selected_pages: list[int] | None,
    save_output: bool,
    include_markdown: bool,
    force_ocr: bool,
) -> dict:
    request_id = uuid4().hex
    try:
        result = pdf_inspector.process_pdf_bytes(data, pages=selected_pages)
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc)) from exc

    markdown = result.markdown
    ocr_applied = False
    ocr_rotated_pages: dict[int, int] = {}
    needs_ocr = (markdown is None or markdown.strip() == "") and bool(result.pages_needing_ocr)

    if force_ocr or needs_ocr:
        try:
            markdown, ocr_rotated_pages = ocr_pdf_bytes(data, selected_pages)
            ocr_applied = True
        except Exception as exc:
            raise HTTPException(status_code=500, detail=f"OCR failed: {exc}") from exc

    metadata = build_metadata(result, filename, request_id)
    saved_markdown_path = None
    saved_metadata_path = None
    if save_output:
        saved_markdown_path, saved_metadata_path = persist_result(filename, markdown, metadata)

    return {
        "ok": True,
        "request_id": request_id,
        "filename": filename,
        "pdf_type": result.pdf_type,
        "confidence": result.confidence,
        "page_count": result.page_count,
        "processing_time_ms": result.processing_time_ms,
        "pages_needing_ocr": list(result.pages_needing_ocr or []),
        "pages_with_tables": list(result.pages_with_tables or []),
        "pages_with_columns": list(result.pages_with_columns or []),
        "has_encoding_issues": bool(result.has_encoding_issues),
        "is_complex_layout": bool(result.is_complex_layout),
        "title": result.title,
        "saved_markdown_path": saved_markdown_path,
        "saved_metadata_path": saved_metadata_path,
        "markdown": markdown if include_markdown else None,
        "ocr_applied": ocr_applied,
        "ocr_rotated_pages": {str(page): deg for page, deg in sorted(ocr_rotated_pages.items())},
    }


router = APIRouter()


@router.get("/healthz")
def healthz() -> dict:
    return {
        "ok": True,
        "service": "updf2md",
        "output_dir": str(OUTPUT_DIR),
        "save_by_default": SAVE_BY_DEFAULT,
        "ocr_lang": OCR_LANG,
    }


@router.post("/convert")
async def convert_pdf(
    file: Annotated[UploadFile, File(...)],
    pages: Annotated[str | None, Form()] = None,
    save_output: Annotated[bool | None, Form()] = None,
    include_markdown: Annotated[bool, Form()] = True,
    force_ocr: Annotated[bool, Form()] = False,
) -> JSONResponse:
    filename = file.filename or "document.pdf"
    if not filename.lower().endswith(".pdf"):
        raise HTTPException(status_code=400, detail="Only PDF files are supported.")
    try:
        selected_pages = parse_pages(pages)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc
    data = await file.read()
    if not data:
        raise HTTPException(status_code=400, detail="Uploaded file is empty.")
    should_save = SAVE_BY_DEFAULT if save_output is None else save_output
    resp = process_and_respond(data, filename, selected_pages, should_save, include_markdown, force_ocr)
    return JSONResponse(content=resp)


@router.post("/convert-url")
async def convert_pdf_url(req: dict) -> JSONResponse:
    pdf_url = req.get("pdf_url", "").strip()
    if not pdf_url:
        raise HTTPException(status_code=400, detail="pdf_url is required.")
    pages = req.get("pages", None)
    force_ocr = bool(req.get("force_ocr", False))
    include_markdown = bool(req.get("include_markdown", True))

    try:
        selected_pages = parse_pages(pages)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc

    try:
        with urllib.request.urlopen(pdf_url, timeout=30) as r:
            data = r.read()
    except Exception as exc:
        raise HTTPException(status_code=400, detail=f"Failed to fetch PDF: {exc}") from exc

    filename = pdf_url.rstrip("/").split("/")[-1] or "document.pdf"
    if not filename.lower().endswith(".pdf"):
        filename += ".pdf"

    resp = process_and_respond(data, filename, selected_pages, False, include_markdown, force_ocr)
    return JSONResponse(content=resp)


@router.post("/report", response_model=ReportResponse)
async def generate_ticker_report(req: ReportRequest) -> ReportResponse:
    try:
        result = await generate_report_data(req.ticker)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc
    except RuntimeError as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc
    return ReportResponse(**result)


def create_app() -> FastAPI:
    app = FastAPI(title="updf2md", version="0.2.0")
    app.include_router(router)
    return app


app = create_app()


if __name__ == "__main__":
    import uvicorn
    uvicorn.run("server:app", host=HOST, port=PORT)
