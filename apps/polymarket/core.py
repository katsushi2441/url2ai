import os
from datetime import datetime

import httpx


OLLAMA_API   = os.getenv("OLLAMA_API",   "https://exbridge.ddns.net/api/generate")
OLLAMA_MODEL = os.getenv("OLLAMA_MODEL", "gemma4:e4b")
TODAY        = datetime.now().strftime("%Y年%m月%d日")
GAMMA_API    = "https://gamma-api.polymarket.com/markets"


REPORT_PROMPT = """\
あなたは予測市場の専門アナリストです。本日は{today}です。
以下のPolymarket市場データをもとに、「{query}」に関する投資家・トレーダー向けレポートを**日本語Markdown**で作成してください。

## 制約
- 市場確率を根拠に分析すること
- 断定表現は避け「〜と考えられる」「〜の可能性がある」を使うこと
- 数値は明記すること

## 出力形式（以下の見出しを必ず使うこと）

# Polymarket Intelligence: {query}（{today}）

## 市場概要
（見つかった市場の全体的な傾向を2〜3文で）

## 主要マーケット分析
（各市場のオッズと意味を分析）

## 市場インプリケーション
（確率が示す市場センチメント・投資判断への示唆）

## 総合評価
（リスクと機会のまとめ）

---

## 市場データ

{context}

---

上記の見出し構成に従い、Markdownレポートを出力してください。"""


SUMMARY_PROMPT = """\
以下のPolymarketレポートを読み、**3文以内**で要点をまとめてください。
- 最も確率の高い結果1つ
- 注目すべき市場の動向1つ
- 総合的な市場センチメント1文

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


def fmt_usd(v: object) -> str:
    try:
        v = float(v)  # type: ignore[arg-type]
        if v >= 1_000_000:
            return f"${v / 1_000_000:.1f}M"
        if v >= 1_000:
            return f"${v / 1_000:.0f}k"
        return f"${v:.0f}"
    except Exception:
        return "-"


async def fetch_markets(query: str, limit: int = 5) -> list[dict]:
    params = {"search": query, "limit": limit, "active": "true", "closed": "false"}
    async with httpx.AsyncClient(timeout=15) as client:
        resp = await client.get(GAMMA_API, params=params,
                                headers={"User-Agent": "polymarket-intel/1.0"})
        resp.raise_for_status()
        data = resp.json()
    return data if isinstance(data, list) else []


def format_market(m: dict) -> dict:
    import json as _json

    outcomes = m.get("outcomes", "[]")
    if isinstance(outcomes, str):
        try:
            outcomes = _json.loads(outcomes)
        except Exception:
            outcomes = []

    prices_raw = m.get("outcomePrices", "[]")
    if isinstance(prices_raw, str):
        try:
            prices_raw = _json.loads(prices_raw)
        except Exception:
            prices_raw = []

    odds: dict[str, float] = {}
    for i, label in enumerate(outcomes):
        if i < len(prices_raw):
            try:
                odds[str(label)] = round(float(prices_raw[i]), 4)
            except Exception:
                pass

    top_outcome = ""
    if odds:
        top_k = max(odds, key=lambda k: odds[k])
        top_outcome = f"{top_k}: {odds[top_k] * 100:.0f}%"

    slug = m.get("slug", "")
    return {
        "slug":        slug,
        "title":       m.get("question") or m.get("title") or "",
        "odds":        odds,
        "volume":      fmt_usd(m.get("volume",    0)),
        "liquidity":   fmt_usd(m.get("liquidity", 0)),
        "end_date":    (m.get("endDate") or "")[:10],
        "top_outcome": top_outcome,
    }


def build_context(markets: list[dict]) -> str:
    lines = []
    for m in markets:
        odds_str = "  ".join(f"{k}: {v * 100:.0f}%" for k, v in m["odds"].items())
        lines.append(
            f"### {m['title']}\n"
            f"オッズ: {odds_str}\n"
            f"ボリューム: {m['volume']}  流動性: {m['liquidity']}  終了日: {m['end_date']}"
        )
    return "\n\n---\n\n".join(lines)


async def generate_report_data(query: str, depth: str = "medium") -> dict:
    query = query.strip()
    if not query:
        raise ValueError("query is required")

    raw_markets = await fetch_markets(query)
    if not raw_markets:
        raise RuntimeError(f"「{query}」に関連するアクティブな市場が見つかりませんでした")

    markets = [format_market(m) for m in raw_markets]
    sources = [f"https://polymarket.com/event/{m['slug']}" for m in markets if m["slug"]]
    context = build_context(markets)

    report  = await ollama_generate(
        REPORT_PROMPT.format(query=query, today=TODAY, context=context), timeout=360
    )
    summary = await ollama_generate(
        SUMMARY_PROMPT.format(report=report), timeout=120
    )

    return {
        "ok":              True,
        "query":           query,
        "depth":           depth,
        "markdown":        report,
        "summary":         summary,
        "matched_markets": markets,
        "sources":         sources,
        "generated_at":    TODAY,
    }
