# acp-provider — Kurage AI Project の ACP プロバイダ常駐

Virtuals Protocol ACP マーケットで「Kurage AI Project」エージェントを **24hオンライン維持**し、
受注(ジョブ)を **既存の内部brain(DeepSeek)** で自動納品するデーモン。

## なぜ必要か
- ACPはオンラインのエージェントだけがマーケットに露出し受注できる。常駐して online を保つ。
- 受注が来たら offering に対応する brain を呼び、`acp provider submit` で成果物を返す。

## 構成
- `provider.py` … 本体。`acp events listen` を子プロセスで常駐(=オンライン)、`acp events drain`
  でイベント消化、`acp job history` で全文脈を取り、フェーズに応じ `provider set-budget` /
  `provider submit`。
- `run.sh` … 起動ラッパ。brainトークンを **cdp-gateway の systemd 環境から実行時注入**(新規に
  秘密ファイルを作らない)。
- `~/.config/systemd/user/acp-provider.service` … `systemctl --user`(Linger=yes)で常駐。sudo不要・
  再起動後も持続。

## 対象offering(自動納品)
| offering | brain | port | 自動 |
|---|---|---|---|
| Kurage FX Brain | kfxbrain | 18326 | ✅ |
| Kurage Crypto Brain | kcbrain | 18328 | ✅ |
| URL2Brain | url2brain | 18332 | ❌ 保留(投稿系はKurage自身のSNSへ実publishのため自動化しない=手動レビュー) |

## 課金レール=DeepSeek
ACPはUSDCエスクロー決済(有料)なので brain呼び出しは `X-*-Provider: deepseek`。ローカルGemmaは
無料の直叩き(kfreqai毎時ジョブ等)専用で、ここでは使わない。

## 安全方針(未検証部分の扱い)
- 実ジョブのJSONスキーマは本番未観測。対応brain・入力ペイロードを確信を持って特定できない
  場合は **納品せず MANUAL_REVIEW としてログに残しスキップ**(誤った成果物を実マネー取引に出さない)。
- 初回の実ジョブで `provider.log` のスキーマを確認し、フィールド抽出を確定させること。

## 運用
```
systemctl --user status  acp-provider
systemctl --user restart acp-provider
tail -f provider.log            # 稼働ログ(gitignore)
```
runtime生成物(state.json / events.jsonl / provider.log)は `.gitignore` 済み。
