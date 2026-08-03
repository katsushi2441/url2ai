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
| `server-rapidapi.js` | RapidAPI 窓口。LLM生成は本体(8019)へ中継 | 8018 |
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

## 有料レールはすべて DeepSeek

方針: **有料レール(Bankr x402 / JPYC / ACP / RapidAPI)は全部 DeepSeek**、
無料・内部用途のみセルフホストGemma。

2026-08-04 時点で **RapidAPI(8018)だけが Gemma のまま**で、レール間で品質が
食い違っていた。`server-rapidapi.js` が直接 Ollama を叩いていたのが原因。
LLM生成を本体(8019)へ中継する形に変え、プロバイダの決定を本体1か所に集約した。

| ポート | レール | プロバイダ |
|---|---|---|
| 8019 | Bankr x402 | DeepSeek |
| 8020 | JPYC x402 (→8019) | DeepSeek |
| 8018 | RapidAPI (→8019) | DeepSeek |

副次的に、RapidAPI経由の呼び出しも使用量計測に載るようになった
(`rail=rapidapi`, `caller=<X-RapidAPI-User>`)。
`/health` と `/v1/models` も本体へ中継するので、8018 が Gemma だと
誤って案内することはない。

## 無課金の直叩き対策

8019 は Bankr のハンドラから到達するため外部公開が必要で、**そのためホスト:ポートを
知っていれば誰でも無課金で推論できていた**(2026-08-04 実測で確認)。
kcbrain / kfxbrain / ksbrain / url2brain は Bankr の暗号化env に `*_TOKEN` を持ち、
ハンドラがヘッダで付けて呼んでいたが、**LLM2API だけこれが無かった**。同じ型に揃えた。

- `x402/llm2api/index.ts` が `X-LLM2API-Token` を付けて上流を呼ぶ
- トークンは Bankr の暗号化env (`bankr x402 env set LLM2API_TOKEN=...`) と
  ローカルの `.env.llm2api-usage` の両方に同じ値を置く
- 判定は `access.js`。**ループバックは常に許可**(JPYC:8020 / RapidAPI:8018 の
  自前ラッパーは 127.0.0.1 から来るため。ここを塞ぐと課金レールが死ぬ)
- 守るのは推論を伴う POST だけ。`/health` と `/v1/models` は監視のため開けておく
- `LLM2API_TOKEN` 未設定なら素通し。設定前に売上を止めないため
- `LLM2API_ENFORCE=false`(既定) は遮断せず `[would-block]` を記録するだけ。
  Bankr側の設定が済んだのを確認してから `true` にする二段構え

Bankr ハンドラの送信元は `35.87.168.13` だった(2026-08-04 実測)。IPは変わりうるので
許可IPではなくトークンで守っている。

```bash
node --test test_access.mjs test_usage.mjs   # 20件
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
| `LLM2API_TOKEN` | 空 | 上流保護トークン。Bankrの暗号化envと同値。空なら素通し |
| `LLM2API_ENFORCE` | `false` | `true` で無課金の直叩きを403にする |
| `LLM2API_ALLOWED_CLIENT_IPS` | 空 | 追加の許可IP(カンマ区切り)。ループバックは常に許可 |
| `LLM2API_USAGE_DIR` | `./data` | 計測ログの保存先 |

トークンとパスワードの実値は `.env.llm2api-usage`（git管理外）にある。

## デプロイ

LPと利用状況ページ:

```bash
bash deploy_landing.sh          # llm2api.exbridge.jp へ配置
```

サーバー本体は **user unit** へ移行済み(2026-08-04)。sudoは不要:

```bash
systemctl --user restart llm-gateway
curl -s http://127.0.0.1:8019/health
```

Bankr ハンドラ(`x402/llm2api/index.ts`)を変えたときは、リポジトリルートで:

```bash
bankr x402 deploy llm2api
```
