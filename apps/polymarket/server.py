import os

from fastapi import FastAPI, HTTPException
from fastapi.responses import JSONResponse
from pydantic import BaseModel

from core import OLLAMA_MODEL, generate_report_data


HOST = os.getenv("HOST", "0.0.0.0")
PORT = int(os.getenv("PORT", "8015"))


class ReportRequest(BaseModel):
    query: str
    depth: str = "medium"


app = FastAPI()


@app.post("/report")
async def generate_report(req: ReportRequest):
    query = req.query.strip()
    if not query:
        raise HTTPException(status_code=400, detail="query is required")

    try:
        result = await generate_report_data(query, req.depth)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc
    except RuntimeError as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc

    return JSONResponse(content={
        "query":           result["query"],
        "depth":           result["depth"],
        "report":          result["markdown"],
        "summary":         result["summary"],
        "matched_markets": result["matched_markets"],
        "sources":         result["sources"],
        "generated_at":    result["generated_at"],
    })


@app.get("/health")
async def health():
    return {"status": "ok", "model": OLLAMA_MODEL}


if __name__ == "__main__":
    import uvicorn
    uvicorn.run("server:app", host=HOST, port=PORT, reload=False)
