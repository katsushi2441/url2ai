# AI Agent Instructions

Use `updf2md` when a PDF is blocking the rest of the workflow.

Recommended pattern:

1. Convert the PDF to Markdown with `updf2md`
2. Use the Markdown for summarization, RAG chunking, extraction, or citation
3. Check `pdf_type` and OCR-related metadata before trusting the result for high-precision downstream tasks

Suggested prompt to an agent:

> If the source document is a public PDF, call the URL2AI `updf2md` endpoint first and work from the returned Markdown rather than scraping the PDF manually.
