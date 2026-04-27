import asyncio
import os
import re
import time
from datetime import datetime

import httpx
import yfinance as yf
from bs4 import BeautifulSoup


OLLAMA_API = os.getenv("OLLAMA_API", "https://exbridge.ddns.net/api/generate")
OLLAMA_MODEL = os.getenv("OLLAMA_MODEL", "gemma4:e4b")
TODAY = datetime.now().strftime("%Y年%m月%d日")
YEAR = datetime.now().strftime("%Y")

_JAPANESE_CODE_RE = re.compile(r'^\d{4}$')
_JAPANESE_TEXT_RE = re.compile(r'[　-鿿＀-￯]')


def looks_like_crypto(value: str) -> bool:
    v = value.strip().lstrip('$')
    return len(v) <= 6 and v.upper() == v and v.isalpha()


def resolve_yf_symbol(query: str) -> tuple[str, str]:
    """Return (yf_symbol, display_name). display_name may be empty if unknown."""
    q = query.strip()

    # 4-digit Japanese stock code → add .T
    if _JAPANESE_CODE_RE.match(q):
        return q + ".T", ""

    # Japanese company name → look up code via Yahoo Finance Japan
    if _JAPANESE_TEXT_RE.search(q):
        code = _resolve_japanese_name(q)
        if code:
            return code + ".T", q
        return q, q

    # Short all-uppercase alpha → treat as crypto
    if looks_like_crypto(q):
        sym = q.lstrip('$').upper()
        return sym + "-USD", sym

    # English name or US ticker → yfinance Search
    try:
        results = yf.Search(q, max_results=1)
        quotes = results.quotes or []
        if quotes:
            sym = quotes[0].get("symbol", q)
            name = quotes[0].get("longname") or quotes[0].get("shortname") or ""
            return sym, name
    except Exception:
        pass

    return q, q


def _resolve_japanese_name(name: str) -> str:
    """Scrape Yahoo Finance Japan to resolve Japanese company name → 4-digit code."""
    try:
        resp = httpx.get(
            "https://finance.yahoo.co.jp/search/",
            params={"query": name, "category": "stock"},
            headers={"User-Agent": "Mozilla/5.0"},
            timeout=8,
            follow_redirects=True,
        )
        codes = re.findall(r'code=(\d{4})', resp.text)
        if codes:
            return codes[0]
    except Exception:
        pass
    return ""


def extract_company_info(yf_sym: str, query: str, titles: list[str]) -> dict:
    """Extract company name and symbol from search result titles (regex, no LLM)."""
    for title in titles:
        clean = re.sub(r'^\s*(?:\(株\)|（株）|株式会社)\s*', '', title)
        m = re.match(r'^(.+?)(?:\s*[【（\[\(]|\s*[-–—]\s*\S|\s*[：:]\s*\S)', clean)
        if m:
            name = m.group(1).strip()
            if len(name) >= 2 and name.lower() != query.lower():
                sym_m = re.search(r'[【（\[](\w+)[】）\]]', title)
                sym = sym_m.group(1) if sym_m else query
                return {"company_name": name, "symbol": sym}
    return {"company_name": query, "symbol": yf_sym}


REPORT_PROMPT = """\
あなたは金融投資アナリストです。本日は{today}です。
以下のYahoo Finance・ニュース調査データをもとに、{ticker} の投資家向けレポートを**日本語Markdown**で作成してください。

## 制約
- 断定表現は避け「〜と考えられる」「〜の可能性がある」を使うこと
- 数値・出典がある場合は明記すること
- データに記載のない事実を捏造しないこと

## 出力形式（以下の見出しを必ず使うこと）

# {ticker} 投資レポート（{today}）

## 概要
（銘柄・企業の概要を2〜3文で）

## 最新ニュース・直近の動向
（提供データに含まれる重要ニュース・イベントを箇条書き）

## 【分析軸1】業績・価格への影響
（ニュースが財務業績・株価・時価総額にどう影響する可能性があるか）

## 【分析軸2】市場トレンド・競合比較
（同業他社・競合と比較した相対的ポジション・市場シェア動向）

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

## 調査データ

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


def _fetch_yf(yf_sym: str) -> tuple[dict, list[dict]]:
    try:
        t = yf.Ticker(yf_sym)
        info = t.info or {}
        news = t.news or []
        return info, news
    except Exception:
        return {}, []


async def gather_context(ticker: str, yf_sym: str) -> tuple[str, list[str], list[str]]:
    loop = asyncio.get_event_loop()
    info, news = await loop.run_in_executor(None, lambda: _fetch_yf(yf_sym))

    context_parts: list[str] = []
    sources: list[str] = []
    titles: list[str] = []

    # --- Company basic info from yfinance ---
    if info:
        name = info.get("longName") or info.get("shortName") or ticker
        desc = info.get("longBusinessSummary", "")
        price = info.get("currentPrice") or info.get("regularMarketPrice")
        market_cap = info.get("marketCap")
        sector = info.get("sector", "")
        industry = info.get("industry", "")
        prev_close = info.get("previousClose")
        fifty_two_week_high = info.get("fiftyTwoWeekHigh")
        fifty_two_week_low = info.get("fiftyTwoWeekLow")

        part = f"### {name} 基本情報\nシンボル: {yf_sym}\n"
        if price:
            part += f"現在株価/価格: {price:,.2f}\n"
        if prev_close:
            part += f"前日終値: {prev_close:,.2f}\n"
        if market_cap:
            part += f"時価総額: {market_cap:,}\n"
        if sector:
            part += f"セクター: {sector}\n"
        if industry:
            part += f"業種: {industry}\n"
        if fifty_two_week_high and fifty_two_week_low:
            part += f"52週高値/安値: {fifty_two_week_high:,.2f} / {fifty_two_week_low:,.2f}\n"
        if desc:
            part += f"\n事業概要:\n{desc[:2000]}\n"
        context_parts.append(part)
        titles.append(f"{name} 企業情報")

    # --- News from yfinance ---
    news_urls = [a.get("link") or a.get("url", "") for a in news if a.get("title")]
    scraped = await asyncio.gather(*[scrape_url(u) for u in news_urls[:6]])

    for article, body in zip(news[:6], scraped):
        title = article.get("title", "")
        url = article.get("link") or article.get("url", "")
        publisher = article.get("publisher", "")
        if not title:
            continue
        titles.append(title)
        if url:
            sources.append(url)
        part = f"### {title}"
        if publisher:
            part += f" ({publisher})"
        if url:
            part += f"\n出典: {url}"
        if body:
            part += f"\n{body[:2000]}"
        context_parts.append(part)

    return "\n\n---\n\n".join(context_parts), sources, titles


async def generate_report_data(ticker: str) -> dict:
    symbol = ticker.strip()
    if not symbol:
        raise ValueError("ticker is required")

    yf_sym, resolved_name = resolve_yf_symbol(symbol)

    context, sources, result_titles = await gather_context(symbol, yf_sym)
    if not context:
        raise RuntimeError(f"データを取得できませんでした（{yf_sym}）")

    company_info = extract_company_info(yf_sym, symbol, result_titles)
    if resolved_name and company_info["company_name"] == symbol:
        company_info["company_name"] = resolved_name

    report = await ollama_generate(
        REPORT_PROMPT.format(ticker=symbol, today=TODAY, context=context),
        timeout=360,
    )
    summary = await ollama_generate(SUMMARY_PROMPT.format(report=report), timeout=120)

    return {
        "ok": True,
        "ticker": symbol,
        "company_name": company_info["company_name"],
        "resolved_symbol": yf_sym,
        "markdown": report,
        "summary": summary,
        "sources": sources,
        "generated_at": TODAY,
    }
