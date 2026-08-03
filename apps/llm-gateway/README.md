# LLM2API

OpenAI互換のLLM推論を、x402プロトコルの従量課金で提供するゲートウェイ。
URL2AI プロジェクトの一部で、対外的な製品名は **LLM2API** です。

> **名前について**: このディレクトリ名は `llm-gateway` ですが、
> [llmgateway.io / theopenco](https://github.com/theopenco/llmgateway) とは**無関係**です。
> 派生でも fork でもありません。本実装は 2026-04-29 に依存パッケージゼロの
> 素の Node.js として書き起こしたもので、設計思想も逆방向です
> （多プロバイダを束ねるのではなく、GPU競合を避けるため1系統へ寄せている）。

## 公開先

| 用途 | URL |
|---|---|
| LP（英語） | https://llm2api.exbridge.jp/ |
| LP（日本語） | https://llm2api.exbridge.jp/llm2api.html |
| 利用状況（運営用） | https://llm2api.exbridge.jp/usage.php |
| Bankr x402 | `https://x402.bankr.bot/0x444fadbd6e1fed0cfbf7613b6c9f91b9021eecbd/llm2api`（$0.05/req, USDC on Base） |
| JPYC x402 | `https://exbridge.ddns.net:8020`（7.5 JPYC/req, Polygon） |

## サーバー構成

| ファイル | 役割 | ポート |
|---|---|---|
| `server.js` | LLM2API 本体（OpenAI互換・計測込み） | 8019 |
| `server-jpyc.js` | JPYC x402 課金ラッパー | 8020 |
| `server-rapidapi.js` | RapidAPI 窓口 | 8018 |
| `server-jpyc-*.js` | kcbrain / ksbrain / fxbrain / url2brain の JPYC ラッパー | 個別 |
| `usage.js` | 使用量計測（依存パッケージなし） | — |

**このディレクトリは LLM2API 専用ではありません。** 他プロダクトの課金ラッパーが同居しており、
`kcbrain-jpyc.service` などがこのパスを `WorkingDirectory` として参照しています。
ディレクトリ名を変えると13ファイルと本番課金サービスに波及するため、改名しないでください。

## エンドポイント

| メソッド | パス | 内容 |
|---|---|---|
| GET | `/health` | 稼働確認。プロバイダとモデルを返す |
| GET | `/v1/models` | 稼働中モデル、枠（hosted / self-hosted）、入出力上限 |
| POST | `/v1/chat/completions` | 推論（OpenAI互換） |
| POST | `/trade/risk-check` | 暗号資産のネガティブイベント検査（kfreqai judgment API へ中継） |
| POST | `/trade/size-check` | 流動性・注文サイズ診断 |
| GET | `/usage` | 使用量サマリ。`LLM2API_USAGE_TOKEN` 必須。未設定なら404 |

制限: 入力4,000文字・20メッセージ、出力2,048トークン（`max_tokens` の指定によらず強制）。

## 使用量計測

課金レール（x402 / JPYC / RapidAPI）は以前からあったが、**誰がどれだけ使ったかを記録していなかった**。
売上の内訳が分からないと価格を改善できないため、`usage.js` でトークン数を呼び出し元ごとに残す。

- 保存先: `data/usage-YYYY-MM.jsonl`（追記専用。`LLM2API_USAGE_DIR` で変更可）
- 呼び出し元の特定順: `x-payment`（x402の支払者アドレス）→ `x-rapidapi-user` → `x-forwarded-for` → 接続元IP
- JPYCゲートウェイは `req.headers` をそのまま上流へ渡すので、`x-payment` は本体まで届く
- 計測の失敗で推論の応答を壊さない（例外は握りつぶす。計測は本業ではない）
- SQLite を使わないのは、Node 20 に `node:sqlite` が無く、
  `better-sqlite3` を入れると **依存パッケージゼロ**という性質が失われるため

```bash
node --test test_usage.mjs      # 9件
```

## 環境変数

| 変数 | 既定 | 内容 |
|---|---|---|
| `PORT` | 8019 | 待受ポート |
| `LLM_PROVIDER` | `ollama` | `ollama`（セルフホスト）または `deepseek`（ホスト型） |
| `DEEPSEEK_API_KEY` | — | `deepseek` 選択時に必要 |
| `OLLAMA_HOST` | 192.168.0.14 | Ollamaホスト |
| `MAX_INPUT_CHARS` / `MAX_OUTPUT_TOKENS` | 4000 / 2048 | 入出力上限 |
| `LLM2API_USAGE_TOKEN` | 空 | `/usage` の閲覧トークン。空なら `/usage` は404 |
| `LLM2API_USAGE_DIR` | `./data` | 計測ログの保存先 |

トークンとパスワードの実値は `.env.llm2api-usage`（git管理外）にある。

## デプロイ

LPと利用状況ページ:

```bash
bash deploy_landing.sh          # llm2api.exbridge.jp へ配置
```

サーバー本体は **root所有の system unit** で動いている（`llm-gateway.service`）。
`systemctl --user` では触れず、反映には sudo が必要:

```bash
sudo systemctl restart llm-gateway
curl -s http://127.0.0.1:8019/health
```
