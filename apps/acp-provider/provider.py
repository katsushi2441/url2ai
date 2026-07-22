#!/usr/bin/env python3
"""Kurage AI Project — ACP プロバイダ常駐デーモン。

役割:
  1. エージェントを24hオンライン維持する(`acp events listen`)。オンラインでないと
     ACPマーケットに露出せず受注もできない。これは実測で動作確認済みの核心機能。
  2. 受注(ジョブ)が「自分の対応待ち」になったら、offeringに対応する内部brainを
     DeepSeekで呼び、成果物を `acp provider submit` で納品する。

安全方針(重要):
  - 課金レール(ACPはUSDCエスクロー決済=有料)なので brain呼び出しは DeepSeek を使う
    (`X-*-Provider: deepseek`)。ローカルGemmaは使わない(kfreqai毎時ジョブ等の無料直叩き専用)。
  - **初回の実ジョブでスキーマを観測してから納品確定**する。ジョブJSONの構造は本番で
    未観測なので、対応brain・入力ペイロードを確信を持って特定できない場合は納品せず
    MANUAL_REVIEW としてログに残しスキップする(誤った成果物を実マネーの取引に出さない)。
  - url2brain(投稿系はKurage自身のSNSへ実publish)は自動納品しない。手動レビュー扱い。
    → 判断系(fxbrain / kcbrain, 読み取り専用)のみ自動納品対象。

秘密情報: brainトークンは環境変数から読む(run.sh が cdp-gateway の systemd 環境から注入)。
ファイルには保存しない。
"""
from __future__ import annotations

import json
import os
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

BASE = Path(__file__).resolve().parent
STATE_PATH = BASE / "state.json"
EVENTS_FILE = BASE / "events.jsonl"
LOG_PATH = BASE / "provider.log"

ACP = os.environ.get("ACP_BIN", "acp")
POLL_WATCH_TIMEOUT = int(os.environ.get("ACP_WATCH_TIMEOUT", "240"))  # job watch のブロック上限
ACP_CMD_TIMEOUT = int(os.environ.get("ACP_CMD_TIMEOUT", "60"))        # 通常コマンドのハード締切
BRAIN_TIMEOUT = int(os.environ.get("BRAIN_TIMEOUT", "120"))

# offering(ID or 名前キーワード) → 対応する内部brain。判断系のみ自動納品。
# port/token/provider header は cdp-gateway の実装(server.js)と一致させている。
BRAINS = {
    "fxbrain": {
        "match_ids": {"019f85e8-357b-7b05-9662-bae5896520ac"},
        "match_kw": ["fx brain", "fx judgment", "fxbrain"],
        "base": os.environ.get("FXBRAIN_URL", "http://127.0.0.1:18326"),
        "token": os.environ.get("FXBRAIN_TOKEN", ""),
        "token_header": "X-KFXBRAIN-Token",
        "provider_header": "X-KFXBrain-Provider",
        "price_usdc": "0.05",
        "auto": True,
    },
    "kcbrain": {
        "match_ids": {"019f85e8-a00a-769a-8d75-2177f38044fb"},
        "match_kw": ["crypto brain", "crypto judgment", "kcbrain"],
        "base": os.environ.get("KCBRAIN_URL", "http://127.0.0.1:18328"),
        "token": os.environ.get("KCBRAIN_TOKEN", ""),
        "token_header": "X-KCBRAIN-Token",
        "provider_header": "X-KCBRAIN-Provider",
        "price_usdc": "0.001",
        "auto": True,
    },
    "url2brain": {
        "match_ids": {"019f85e6-703a-72f3-b1cf-ecaa3748dc96"},
        "match_kw": ["url2brain", "content generation", "auto-posting"],
        "base": os.environ.get("URL2BRAIN_URL", "http://127.0.0.1:18332"),
        "token": os.environ.get("URL2BRAIN_TOKEN", ""),
        "token_header": "X-URL2BRAIN-Token",
        "provider_header": None,
        "price_usdc": "1",
        "auto": False,  # 投稿系はKurage自身のSNSへ実publishするため自動納品しない
    },
}


def log(msg: str, obj=None) -> None:
    ts = datetime.now(timezone.utc).astimezone().isoformat(timespec="seconds")
    line = f"[{ts}] {msg}"
    if obj is not None:
        try:
            line += " " + json.dumps(obj, ensure_ascii=False)[:2000]
        except Exception:
            line += " " + str(obj)[:2000]
    print(line, flush=True)
    try:
        with LOG_PATH.open("a", encoding="utf-8") as f:
            f.write(line + "\n")
    except Exception:
        pass


def load_state() -> dict:
    if STATE_PATH.exists():
        try:
            return json.loads(STATE_PATH.read_text(encoding="utf-8"))
        except Exception:
            pass
    return {"handled": {}}  # job_id -> last handled phase/action


def save_state(state: dict) -> None:
    tmp = STATE_PATH.with_suffix(".tmp")
    tmp.write_text(json.dumps(state, ensure_ascii=False, indent=1), encoding="utf-8")
    tmp.replace(STATE_PATH)


def acp_json(args: list[str], timeout: int = ACP_CMD_TIMEOUT):
    """acp <args> --json を実行して dict/list を返す。失敗時 None。必ずハード締切付き。"""
    cmd = [ACP, *args, "--json"]
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    except subprocess.TimeoutExpired:
        log("acp timeout", {"args": args})
        return None
    except Exception as e:  # noqa: BLE001
        log("acp exec error", {"args": args, "err": str(e)})
        return None
    out = (p.stdout or "").strip()
    if not out:
        if p.returncode != 0:
            log("acp nonzero/empty", {"args": args, "rc": p.returncode, "stderr": (p.stderr or "")[:300]})
        return None
    # 最終行が JSON のことが多い。全体→末尾行の順に試す。
    for cand in (out, out.splitlines()[-1]):
        try:
            return json.loads(cand)
        except Exception:
            continue
    log("acp non-json output", {"args": args, "head": out[:300]})
    return None


# ---- ジョブJSONからの防御的フィールド抽出(本番スキーマ未観測。複数候補キーを探す) ----

def _first(d: dict, keys: list[str]):
    for k in keys:
        if isinstance(d, dict) and d.get(k) not in (None, "", [], {}):
            return d[k]
    return None


def job_id_of(job: dict):
    return _first(job, ["onChainJobId", "jobId", "id", "job_id", "onchain_job_id"])


def job_phase_of(job: dict):
    # イベント種別(job.created / job.funded 等)も相にみなす。
    v = _first(job, ["phase", "status", "state", "nextAction", "next_action", "stage",
                     "type", "event", "eventType", "name"])
    return str(v).lower() if v is not None else ""


def job_offering_hint(job: dict) -> str:
    parts = []
    for k in ["offeringId", "offering_id", "offering", "serviceName", "service", "serviceRequirement",
              "packageName", "package", "name", "title", "description"]:
        v = job.get(k) if isinstance(job, dict) else None
        if isinstance(v, str):
            parts.append(v)
        elif isinstance(v, dict):
            parts.append(json.dumps(v, ensure_ascii=False))
    return " ".join(parts).lower()


def job_buyer_payload(job: dict):
    """買い手が指定した入力(skillパス + JSON body)を取り出す。未観測なので候補を広く探す。"""
    return _first(job, ["requirement", "requirements", "serviceRequirement", "payload",
                         "params", "input", "body", "request", "args"])


def match_brain(job: dict):
    oid = str(_first(job, ["offeringId", "offering_id"]) or "")
    hint = job_offering_hint(job)
    for name, b in BRAINS.items():
        if oid and oid in b["match_ids"]:
            return name, b
    for name, b in BRAINS.items():
        if any(kw in hint for kw in b["match_kw"]):
            return name, b
    return None, None


def call_brain(brain: dict, skill_path: str, body: dict):
    """内部brainをDeepSeekで叩いて成果物JSONを返す。curl を使い外部依存を避ける。"""
    # 買い手のskillパス(例 "/analyze/technical")→内部の /v1/<...>
    sp = skill_path.strip("/")
    if sp.startswith("v1/"):
        sp = sp[3:]
    url = f"{brain['base']}/v1/{sp}"
    headers = ["-H", "content-type: application/json"]
    if brain["token"]:
        headers += ["-H", f"{brain['token_header']}: {brain['token']}",
                    "-H", f"Authorization: Bearer {brain['token']}"]
    if brain["provider_header"]:
        headers += ["-H", f"{brain['provider_header']}: deepseek"]
    cmd = ["curl", "-s", "--max-time", str(BRAIN_TIMEOUT), "-X", "POST", url,
           *headers, "-d", json.dumps(body, ensure_ascii=False)]
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=BRAIN_TIMEOUT + 15)
    except Exception as e:  # noqa: BLE001
        log("brain call error", {"url": url, "err": str(e)})
        return None
    try:
        return json.loads(p.stdout)
    except Exception:
        log("brain non-json", {"url": url, "head": (p.stdout or "")[:300]})
        return None


def extract_skill_and_body(payload):
    """買い手ペイロードから (skill_path, body) を取り出す。形が読めなければ (None, None)。"""
    if not isinstance(payload, dict):
        return None, None
    skill = _first(payload, ["path", "skill", "endpoint", "skillPath", "skill_path"])
    body = _first(payload, ["body", "input", "params", "data"])
    if body is None:
        # payload 自体が body で、path が別キーにあるケース
        body = {k: v for k, v in payload.items()
                if k not in ("path", "skill", "endpoint", "skillPath", "skill_path")}
    return (str(skill) if skill else None), (body if isinstance(body, dict) else None)


def handle_job(job: dict, state: dict) -> None:
    jid = job_id_of(job)
    if jid is None:
        log("job without id (skip)", job)
        return
    jid = str(jid)
    phase = job_phase_of(job)
    prev = state["handled"].get(jid)
    log("job event", {"job_id": jid, "phase": phase})

    name, brain = match_brain(job)
    if brain is None:
        log("MANUAL_REVIEW: offering not mapped", {"job_id": jid, "hint": job_offering_hint(job)[:300]})
        return
    if not brain["auto"]:
        log("MANUAL_REVIEW: offering is not auto-fulfillable (e.g. url2brain posts)",
            {"job_id": jid, "brain": name})
        return

    # フェーズ判定(未観測スキーマにつきキーワードで推定)。
    needs_budget = any(k in phase for k in ["request", "negotiat", "pending", "propose", "new", "created"])
    needs_deliver = any(k in phase for k in ["fund", "progress", "accepted", "transaction", "deliver"])

    if needs_budget and prev != "budget_set":
        r = acp_json(["provider", "set-budget", "--job-id", jid, "--amount", brain["price_usdc"]])
        if r is not None:
            state["handled"][jid] = "budget_set"; save_state(state)
            log("budget set", {"job_id": jid, "amount": brain["price_usdc"], "brain": name})
        else:
            log("budget set FAILED (will retry)", {"job_id": jid})
        return

    if needs_deliver and prev != "delivered":
        payload = job_buyer_payload(job)
        skill, body = extract_skill_and_body(payload)
        if not skill or body is None:
            log("MANUAL_REVIEW: cannot parse buyer payload (schema unobserved)",
                {"job_id": jid, "payload": payload})
            return
        result = call_brain(brain, skill, body)
        if result is None:
            log("deliver FAILED: brain returned nothing (will retry)", {"job_id": jid})
            return
        deliverable = json.dumps(result, ensure_ascii=False)
        r = acp_json(["provider", "submit", "--job-id", jid, "--deliverable", deliverable])
        if r is not None:
            state["handled"][jid] = "delivered"; save_state(state)
            log("DELIVERED", {"job_id": jid, "brain": name, "skill": skill})
        else:
            log("submit FAILED (will retry)", {"job_id": jid})
        return

    log("no actionable phase (observe only)", {"job_id": jid, "phase": phase})


def start_listener() -> subprocess.Popen:
    """`acp events listen` を子プロセスで起動し、エージェントをオンライン維持する。
    受注イベントは EVENTS_FILE に追記され、メインループが drain で消化する。"""
    p = subprocess.Popen([ACP, "events", "listen", "--all", "--output", str(EVENTS_FILE)],
                         stdout=subprocess.DEVNULL, stderr=subprocess.STDOUT)
    log("listener started (agent online)", {"pid": p.pid})
    return p


def fetch_job_detail(jid: str):
    """ジョブの完全なコンテキストを取得(best-effort)。取れなければ None。"""
    d = acp_json(["job", "history", "--job-id", str(jid)], timeout=40)
    if isinstance(d, dict):
        return d
    if isinstance(d, list) and d:
        return d[-1] if isinstance(d[-1], dict) else None
    return None


def drain_events():
    """listener が書いた events.jsonl から未処理イベントを取り出す(取り出したら消える)。"""
    ev = acp_json(["events", "drain", "--file", str(EVENTS_FILE), "--limit", "25"], timeout=30)
    if ev is None:
        return []
    # drain の出力は {"events":[...], "remaining":N} エンベロープ(実測)。
    if isinstance(ev, dict):
        return ev.get("events") or []
    return ev if isinstance(ev, list) else [ev]


def main() -> None:
    log("=== ACP provider daemon start ===",
        {"brains": {k: {"base": v["base"], "auto": v["auto"], "token": bool(v["token"])}
                    for k, v in BRAINS.items()}})
    state = load_state()
    listener = start_listener()
    poll = int(os.environ.get("ACP_POLL_SEC", "6"))
    try:
        while True:
            # listener 落ちてたら再起動(オンライン維持が最優先)
            if listener.poll() is not None:
                log("listener died — restarting")
                listener = start_listener()
            # listener が書いたイベントを消化。各イベントの job を full context で取り直して処理。
            for ev in drain_events():
                if not isinstance(ev, dict):
                    continue
                jid = job_id_of(ev)
                job = fetch_job_detail(jid) if jid is not None else None
                # full context が取れなければイベント自体で判定(相はイベント種別から推定)。
                if job is None:
                    job = ev
                elif job_phase_of(job) == "" and job_phase_of(ev):
                    job = {**job, "type": job_phase_of(ev)}
                try:
                    handle_job(job, state)
                except Exception as e:  # noqa: BLE001
                    log("handle_job error", {"err": str(e)})
            time.sleep(poll)
    except KeyboardInterrupt:
        pass
    finally:
        try:
            listener.terminate()
        except Exception:
            pass
        log("=== ACP provider daemon stop ===")


if __name__ == "__main__":
    sys.exit(main())
