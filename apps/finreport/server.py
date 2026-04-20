from fastapi import FastAPI, HTTPException
from fastapi.responses import JSONResponse
from pydantic import BaseModel
import httpx
import asyncio
import os
import re
from duckduckgo_search import DDGS
from bs4 import BeautifulSoup

app = FastAPI()

OLLAMA_API   = os.getenv("OLLAMA_API",   "https://exbridge.ddns.net/api/generate")
OLLAMA_MODEL = os.getenv("OLLAMA_MODEL", "gemma4:e4b")
HOST         = os.getenv("HOST", "0.0.0.0")
PORT         = int(os.getenv("PORT", "8013"))


class ReportRequest(BaseModel):
    ticker: str


async def ollama_generate(prompt: str, timeout: int = 180) -> str:
    payload = {
        "model": OLLAMA_MODEL,
        "prompt": prompt,
        "stream": False,
        "options": {
            "num_ctx": 8192,
            "temperature": 0.3,
            "top_k": 40,
            "top_p": 0.9,
        },
    }
    async with httpx.AsyncClient(timeout=timeout) as client:
        resp = await client.post(OLLAMA_API, json=payload)
        resp.raise_for_status()
        return resp.json().get("response", "").strip()


def search_web(query: str, max_results: int = 8) -> list[dict]:
    results = []
    with DDGS() as ddgs:
        for r in ddgs.text(query, max_results=max_results):
            results.append({
                "title": r.get("title", ""),
                "url":   r.get("href",  ""),
                "body":  r.get("body",  ""),
            })
    return results


async def scrape_url(url: str, timeout: int = 10) -> str:
    try:
        async with httpx.AsyncClient(timeout=timeout, follow_redirects=True) as client:
            resp = await client.get(url, headers={"User-Agent": "Mozilla/5.0"})
            soup = BeautifulSoup(resp.text, "html.parser")
            for tag in soup(["script", "style", "nav", "footer", "header"]):
                tag.decompose()
            text = soup.get_text(separator="\n", strip=True)
            # 先頭3000字だけ使う
            return re.sub(r"\n{3,}", "\n\n", text)[:3000]
    except Exception:
        return ""


async def gather_context(ticker: str) -> tuple[str, list[str]]:
    queries = [
        f"{ticker} 価格 最新ニュース 投資分析 2025",
        f"{ticker} cryptocurrency fundamentals market cap tokenomics",
        f"{ticker} リスク 将来性 投資家向け",
    ]

    all_results = []
    for q in queries:
        all_results.extend(search_web(q, max_results=4))

    # URL重複排除
    seen = set()
    unique = []
    for r in all_results:
        if r["url"] not in seen:
            seen.add(r["url"])
            unique.append(r)

    sources = [r["url"] for r in unique[:8]]

    # スクレイプ（並列）
    scrape_tasks = [scrape_url(r["url"]) for r in unique[:6]]
    scraped = await asyncio.gather(*scrape_tasks)

    context_parts = []
    for r, body in zip(unique[:6], scraped):
        snippet = body if body else r["body"]
        if snippet:
            context_parts.append(f"### {r['title']}\nURL: {r['url']}\n{snippet[:2000]}")

    return "\n\n---\n\n".join(context_parts), sources


REPORT_PROMPT = """あなたは金融投資アナリストです。以下のウェブ調査結果をもとに、{ticker} についての投資家向けレポートを日本語 Markdown で作成してください。

## レポート構成（必ず以下の見出しを使うこと）

# {ticker} 投資レポート

## 概要
（プロジェクト・コインの概要を2〜3文で）

## 現在の市場状況
（価格動向・時価総額・出来高など）

## ファンダメンタルズ分析
（技術・ユースケース・チーム・ロードマップ）

## 投資ポイント
（強み・弱み・機会・リスクをリスト形式で）

## まとめ・投資判断
（中立的な視点でまとめる。断定は避け「〜と考えられる」表現を使う）

---

## 調査データ

{context}

---

上記の見出し構成に従い、投資家向けに客観的・簡潔なMarkdownレポートを出力してください。"""

SUMMARY_PROMPT = """以下の投資レポートを読み、投資家向けに3文以内で要点をまとめてください。

{report}

要約のみ出力してください。"""


@app.post("/report")
async def generate_report(req: ReportRequest):
    ticker = req.ticker.strip()
    if not ticker:
        raise HTTPException(status_code=400, detail="ticker is required")

    context, sources = await gather_context(ticker)

    prompt = REPORT_PROMPT.format(ticker=ticker, context=context)
    report = await ollama_generate(prompt, timeout=300)

    summary_prompt = SUMMARY_PROMPT.format(report=report)
    summary = await ollama_generate(summary_prompt, timeout=120)

    return JSONResponse(content={
        "ticker":  ticker,
        "report":  report,
        "summary": summary,
        "sources": sources,
    })


@app.get("/health")
async def health():
    return {"status": "ok"}


if __name__ == "__main__":
    import uvicorn
    uvicorn.run("server:app", host=HOST, port=PORT, reload=False)
