#!/usr/bin/env python3
import argparse
import json
from pathlib import Path

import pdf_inspector


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


def build_payload(result, source: Path) -> dict:
    return {
        "source": str(source),
        "filename": source.name,
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
        "markdown": result.markdown,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Convert PDF to Markdown using pdf-inspector.")
    parser.add_argument("input", help="Path to the input PDF")
    parser.add_argument("-o", "--output", help="Path to write the markdown output")
    parser.add_argument("--json", action="store_true", help="Emit JSON instead of markdown")
    parser.add_argument("--pages", help="1-indexed page list such as 1,3,5-8")
    args = parser.parse_args()

    source = Path(args.input).expanduser().resolve()
    if not source.is_file():
        raise FileNotFoundError(f"PDF not found: {source}")

    selected_pages = parse_pages(args.pages)
    result = pdf_inspector.process_pdf(str(source), pages=selected_pages)
    payload = build_payload(result, source)

    if args.json:
        print(json.dumps(payload, ensure_ascii=False, indent=2))
        return 0

    markdown = result.markdown or ""
    if args.output:
        destination = Path(args.output).expanduser().resolve()
    else:
        destination = source.with_suffix(".md")

    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text(markdown, encoding="utf-8")
    print(destination)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
