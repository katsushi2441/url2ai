import asyncio
import os
import re
from datetime import datetime

import httpx
from bs4 import BeautifulSoup
from duckduckgo_search import DDGS


OLLAMA_API = os.getenv("OLLAMA_API", "https://exbridge.ddns.net/api/generate")
OLLAMA_MODEL = os.getenv("OLLAMA_MODEL", "gemma4:e4b")
TODAY = datetime.now().strftime("%Y年%m月%d日")
YEAR = datetime.now().strftime("%Y")


def normalize_symbol(value: str) -> str:
    text = value.strip()
    if text.startswith("$"):
        text = text[1:]
    return text.strip()


def looks_like_crypto_symbol(value: str) -> bool:
    raw = value.strip()
    normalized = normalize_symbol(raw)
    if not normalized:
        return False
    if raw.startswith("$"):
        return True
    if len(normalized) <= 6 and normalized.upper() == normalized and normalized.isalnum():
        return True
    return False


REPORT_PROMPT = """\
あなたは金融投資アナリストです。本日は{today}です。
以下の直近1週間のWeb調査データをもとに、{ticker} の投資家向けレポートを**日本語Markdown**で作成してください。

## 制約
- 調査日（{today}）より1週間以上前の古い情報は使わないこと
- 断定表現は避け「〜と考えられる」「〜の可能性がある」を使うこと
- 数値・出典がある場合は明記すること

## 出力形式（以下の見出しを必ず使うこと）

# {ticker} 投資レポート（{today}）

## 概要
（銘柄・プロジェクトの概要を2〜3文で）

## 最新ニュース・直近の動向
（直近1週間の重要ニュース・イベントを箇条書き）

## 【分析軸1】業績・価格への影響
（最新ニュースが財務業績・株価・時価総額にどう影響する可能性があるか）

## 【分析軸2】市場トレンド・競合比較
（同業他社・競合プロジェクトと比較した相対的ポジション・市場シェア動向）

## 【分析軸3】投資リスク・機会評価
| 観点 | 内容 |
|------|------|
| 短期機会 | |
| 長期機会 | |
| 主なリスク | |
| 注意すべき規制・外部要因 | |

## 総合評価
（3軸を踏まえた中立的な投資家向けまとめ。強気・弱気・中立の判断根拠を示す）

---

## 調査データ（直近1週間）

{context}

---

上記の見出し構成に従い、投資家にとって価値あるMarkdownレポートを出力してください。"""


SUMMARY_PROMPT = """\
以下の投資レポートを読み、投資家向けに**3文以内**で要点をまとめてください。
- 最も重要なニュース1つ
- 投資判断に影響する要因1つ
- 総合的な見通し1文

{report}

要約のみ出力してください。"""


async def ollama_generate(prompt: str, timeout: int = 240) -> str:
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


def search_web(query: str, max_results: int = 6) -> list[dict]:
    results = []
    try:
        with DDGS() as ddgs:
            for item in ddgs.text(query, max_results=max_results, timelimit="w"):
                results.append(
                    {
                        "title": item.get("title", ""),
                        "url": item.get("href", ""),
                        "body": item.get("body", ""),
                    }
                )
    except Exception:
        pass
    return results


async def scrape_url(url: str, timeout: int = 8) -> str:
    try:
        async with httpx.AsyncClient(timeout=timeout, follow_redirects=True) as client:
            resp = await client.get(url, headers={"User-Agent": "Mozilla/5.0"})
            soup = BeautifulSoup(resp.text, "html.parser")
            for tag in soup(["script", "style", "nav", "footer", "header", "aside"]):
                tag.decompose()
            text = soup.get_text(separator="\n", strip=True)
            return re.sub(r"\n{3,}", "\n\n", text)[:3000]
    except Exception:
        return ""


async def gather_context(ticker: str) -> tuple[str, list[str]]:
    symbol = normalize_symbol(ticker)
    queries = [
        f"{symbol} 最新ニュース {YEAR} 株価 業績",
        f"{symbol} IR 決算 投資家向け情報 {YEAR}",
        f"{symbol} site:finance.yahoo.co.jp OR site:nikkei.com {YEAR}",
        f"{symbol} 競合比較 市場シェア トレンド {YEAR}",
        f"{symbol} リスク 規制 課題 {YEAR}",
        f"{symbol} cryptocurrency price analysis {YEAR}" if len(symbol) <= 6 else f"{symbol} 事業戦略 成長 {YEAR}",
    ]

    if looks_like_crypto_symbol(ticker):
        queries.extend(
            [
                f"{symbol} token Base Bankr x402 {YEAR}",
                f"${symbol} crypto token news {YEAR}",
                f"{symbol} bankr bot launch {YEAR}",
                f"{symbol} project tokenomics roadmap {YEAR}",
                f"site:bankr.bot {symbol} {YEAR}",
            ]
        )

    loop = asyncio.get_event_loop()
    search_tasks = [loop.run_in_executor(None, lambda q=query: search_web(q, max_results=5)) for query in queries]
    search_results = await asyncio.gather(*search_tasks)

    all_results = []
    for results in search_results:
        all_results.extend(results)

    seen = set()
    unique = []
    for result in all_results:
        if result["url"] and result["url"] not in seen:
            seen.add(result["url"])
            unique.append(result)

    sources = [result["url"] for result in unique[:10]]
    scraped = await asyncio.gather(*[scrape_url(result["url"]) for result in unique[:8]])

    context_parts = []
    for result, body in zip(unique[:8], scraped):
        snippet = body if body else result["body"]
        if snippet.strip():
            context_parts.append(f"### {result['title']}\n出典: {result['url']}\n{snippet[:2500]}")

    return "\n\n---\n\n".join(context_parts), sources


async def generate_report_data(ticker: str) -> dict:
    symbol = ticker.strip()
    if not symbol:
        raise ValueError("ticker is required")

    context, sources = await gather_context(symbol)
    if not context:
        raise RuntimeError("検索結果が取得できませんでした")

    report = await ollama_generate(REPORT_PROMPT.format(ticker=symbol, today=TODAY, context=context), timeout=360)
    summary = await ollama_generate(SUMMARY_PROMPT.format(report=report), timeout=120)

    return {
        "ok": True,
        "ticker": symbol,
        "markdown": report,
        "summary": summary,
        "sources": sources,
        "generated_at": TODAY,
    }
