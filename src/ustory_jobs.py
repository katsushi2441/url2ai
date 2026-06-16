from __future__ import annotations

import datetime as dt
import json
import subprocess
from typing import Any

import oss_worker
from oss_jobs import standard_result

API_URL = "https://aiknowledgecms.exbridge.jp/saveustory.php"
DETAIL_URL = "https://aiknowledgecms.exbridge.jp/ustoryv.php"


STORY_PROMPT = """以下はXの投稿内容です。この内容を元にした短編小説を日本語で生成してください。

条件：
- 280字から420字程度
- 「ある日、」などの語り口で始める
- 登場人物に名前をつけて物語として展開する
- 元の投稿の言葉をそのまま使わず、独自の表現で語る
- 読み手が引き込まれる構成（導入、展開、結末）
- 最後に一言の余韻を残す

---
{thread}
---

短編小説のみを出力してください。タイトルや前置きは不要です。"""


ANALYSIS_PROMPT = """以下は永久保存したいX投稿またはスレッドの内容です。
この内容を引用しながら、技術・経営・AI活用・組織運用の観点で日本語の考察ブログを書いてください。

条件：
- 冒頭に短いタイトルを1行で付ける
- 元投稿の重要な言葉を「引用」として2〜3箇所抜き出す
- 引用の直後に、その意味や示唆を自分の言葉で簡潔に考察する
- 技術、経営、AI活用、組織運用のうち関連する観点を必ず含める
- 600〜900字程度で簡潔にまとめる
- 本文中にURLは記載しない（出典は別途表示されるため不要）
- 誇張や断定を避け、公開ブログとして読める落ち着いた文体にする
- 最後に「この投稿から残したい教訓」を2〜3点でまとめる

入力URL：
{source_url}

投稿内容：
---
{thread}
---

考察ブログ本文のみを出力してください。"""


def _normalize_mode(mode: str) -> str:
    return "analysis" if (mode or "").strip().lower() == "analysis" else "story"


def _build_prompt(mode: str, tweet_url: str, thread_text: str) -> str:
    if mode == "analysis":
        return ANALYSIS_PROMPT.format(source_url=tweet_url.strip(), thread=thread_text.strip())
    return STORY_PROMPT.format(thread=thread_text.strip())


def _save_to_cms(payload: dict[str, Any]) -> dict[str, Any]:
    body = json.dumps(payload, ensure_ascii=False)
    result = subprocess.run(
        [
            "curl",
            "-sS",
            "--max-time",
            "20",
            API_URL,
            "-H",
            "Content-Type: application/json",
            "-d",
            body,
        ],
        capture_output=True,
        text=True,
    )
    raw = (result.stdout or "").strip()
    if result.returncode != 0:
        raise RuntimeError(f"saveustory curl failed: {result.stderr.strip()[:300]}")
    try:
        data = json.loads(raw)
    except Exception as exc:
        raise RuntimeError(f"saveustory invalid json: {raw[:300]}") from exc
    return data


def generate_ustory_job(
    tweet_id: str,
    tweet_url: str,
    thread_text: str,
    generation_mode: str = "story",
    username: str = "",
    dry_run: bool = False,
    ai_provider: str = "",
    ai_model: str = "",
    claude_bin: str = "",
    **_meta: Any,
) -> dict[str, Any]:
    tweet_id = "".join(ch for ch in str(tweet_id or "") if ch.isdigit())
    tweet_url = (tweet_url or "").strip()
    thread_text = (thread_text or "").strip()
    username = (username or "").strip()
    mode = _normalize_mode(generation_mode)

    if not tweet_id:
        raise ValueError("tweet_id is required")
    if not tweet_url:
        raise ValueError("tweet_url is required")
    if not thread_text:
        raise ValueError("thread_text is required")

    oss_worker.configure_ai_provider(ai_provider, ai_model, claude_bin)
    prompt = _build_prompt(mode, tweet_url, thread_text)
    detail_url = f"{DETAIL_URL}?id={tweet_id}&mode={mode}"

    if dry_run:
        return standard_result(
            ok=True,
            status="ok",
            items=0,
            metrics={"created": 0, "dry_run": 1},
            note=f"dry_run UStory mode={mode} tweet_id={tweet_id}",
            artifacts=[{"type": "url", "label": "ustory_detail", "url": detail_url}],
            **oss_worker.ai_resource(),
            app="ustory",
            tweet_id=tweet_id,
            tweet_url=tweet_url,
            generation_mode=mode,
            dry_run=True,
            created_at=dt.datetime.now(dt.timezone.utc).isoformat(),
        )

    output = oss_worker.ai_request(prompt)
    if not output:
        raise RuntimeError("UStory AI generation returned empty text")

    save_result = _save_to_cms(
        {
            "tweet_id": tweet_id,
            "tweet_url": tweet_url,
            "thread_text": thread_text,
            "generation_mode": mode,
            "output": output,
            "username": username,
            "ai_provider": oss_worker.ai_resource().get("ai_provider", ""),
            "ai_model": oss_worker.ai_resource().get("ai_model", ""),
        }
    )
    status = save_result.get("status", "")
    if status not in {"ok", "updated"}:
        raise RuntimeError(f"saveustory failed: {save_result}")

    return standard_result(
        ok=True,
        status="ok",
        items=1,
        metrics={"created": 1, "remote_status": status, "mode": mode},
        note=f"UStory generated status={status} mode={mode} tweet_id={tweet_id}",
        artifacts=[
            {"type": "url", "label": "source", "url": tweet_url},
            {"type": "url", "label": "ustory_detail", "url": save_result.get("url", detail_url)},
        ],
        **oss_worker.ai_resource(),
        app="ustory",
        tweet_id=tweet_id,
        tweet_url=tweet_url,
        generation_mode=mode,
        remote_status=status,
        id=save_result.get("id", tweet_id),
        created_at=dt.datetime.now(dt.timezone.utc).isoformat(),
    )
