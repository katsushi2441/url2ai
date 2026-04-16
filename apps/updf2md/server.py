import json
import os
from datetime import datetime, timezone
from pathlib import Path
from typing import Annotated
from uuid import uuid4

import pdf_inspector
from fastapi import APIRouter, FastAPI, File, Form, HTTPException, UploadFile
from pydantic import BaseModel


HOST = os.getenv("HOST", "0.0.0.0")
PORT = int(os.getenv("PORT", "8020"))
OUTPUT_DIR = Path(os.getenv("OUTPUT_DIR", "./outputs")).expanduser()
SAVE_BY_DEFAULT = os.getenv("SAVE_BY_DEFAULT", "false").lower() == "true"


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


router = APIRouter()


@router.get("/healthz")
def healthz() -> dict:
    return {
        "ok": True,
        "service": "updf2md",
        "output_dir": str(OUTPUT_DIR),
        "save_by_default": SAVE_BY_DEFAULT,
    }


@router.post("/convert", response_model=ConvertResponse)
async def convert_pdf(
    file: Annotated[UploadFile, File(...)],
    pages: Annotated[str | None, Form()] = None,
    save_output: Annotated[bool | None, Form()] = None,
    include_markdown: Annotated[bool, Form()] = True,
) -> ConvertResponse:
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

    request_id = uuid4().hex
    try:
        result = pdf_inspector.process_pdf_bytes(data, pages=selected_pages)
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc)) from exc

    metadata = build_metadata(result, filename, request_id)
    should_save = SAVE_BY_DEFAULT if save_output is None else save_output
    saved_markdown_path = None
    saved_metadata_path = None
    if should_save:
        saved_markdown_path, saved_metadata_path = persist_result(filename, result.markdown, metadata)

    return ConvertResponse(
        ok=True,
        request_id=request_id,
        filename=filename,
        pdf_type=result.pdf_type,
        confidence=result.confidence,
        page_count=result.page_count,
        processing_time_ms=result.processing_time_ms,
        pages_needing_ocr=list(result.pages_needing_ocr or []),
        pages_with_tables=list(result.pages_with_tables or []),
        pages_with_columns=list(result.pages_with_columns or []),
        has_encoding_issues=bool(result.has_encoding_issues),
        is_complex_layout=bool(result.is_complex_layout),
        title=result.title,
        saved_markdown_path=saved_markdown_path,
        saved_metadata_path=saved_metadata_path,
        markdown=result.markdown if include_markdown else None,
    )


def create_app() -> FastAPI:
    app = FastAPI(title="updf2md", version="0.1.0")
    app.include_router(router)
    return app


app = create_app()


if __name__ == "__main__":
    import uvicorn

    uvicorn.run("server:app", host=HOST, port=PORT)
