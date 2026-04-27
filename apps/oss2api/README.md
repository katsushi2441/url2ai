# OSS2API — Multi-skill AI Agent Gateway

[URL2AI](https://github.com/katsushi2441/url2ai) プロジェクトの OSS2API サービス。
公開OSSをラップして x402 / JPYC 決済対応の agent-ready API として公開するゲートウェイです。

## スキル一覧

| エンドポイント | スキル | 説明 |
|---|---|---|
| `POST /image/remove-background` | 背景除去 | 除去 / 置換 / ぼかし（imgly AGPL-3.0） |
| `POST /url/analyze` | URL解析 | タイトル・見出し・リンク・エンティティ抽出 |
| `POST /url/browse` | Webブラウズ | Playwright スクリーンショット / 動的抽出 |
| `POST /url/scan` | セキュリティスキャン | Shannon-like 3フェーズ診断（headers + static + AI） |

`POST /run` は `/image/remove-background` の後方互換エイリアスです。

## 構成

| ファイル | ポート | 用途 |
|---|---|---|
| `server.js` | 8015 | ゲートウェイ本体（Bankr / payapi.market 経由） |
| `server-jpyc.js` | 8017 | JPYC x402 直接決済（Polygon） |
| `anthropic-ollama-proxy.js` | 8098 | Anthropic API → Ollama 変換プロキシ |

## セットアップ

```bash
npm install
# Playwright を使う場合（/url/browse）
npx playwright install chromium
```

### 無料サーバー（Bankr / payapi.market 向け）

```bash
cp .env.sample .env
npm start
```

### JPYC x402 決済サーバー

```bash
cp .env.jpyc.sample .env.jpyc
# .env.jpyc の JPYC_RELAY_PRIVATE_KEY を記入
npm run start:jpyc
```

## API

### POST /image/remove-background

画像の背景を除去・置換・ぼかし処理します。

| フィールド | 型 | 説明 |
|---|---|---|
| `image_url` | string | 入力画像の HTTPS URL |
| `image_base64` | string | base64 または data URL 形式の画像 |
| `mode` | string | `remove`（デフォルト）/ `replace` / `blur` |
| `background_color` | string | `replace` モード用 CSS カラー（例: `#ffffff`） |
| `background_image_url` | string | `replace` モード用背景画像 URL |
| `blur_sigma` | number | `blur` モードのぼかし強度 1–80（デフォルト 18） |
| `output_format` | string | `png`（デフォルト）/ `webp` / `jpeg` |
| `response` | string | `binary`（デフォルト）/ `json`（base64 返却） |

```bash
# 背景除去（透過 PNG）
curl -sS https://exbridge.ddns.net:8015/image/remove-background \
  -H 'Content-Type: application/json' \
  -d '{"image_url":"https://example.com/product.jpg","mode":"remove"}' \
  --output removed.png

# 背景を白に置換
curl -sS https://exbridge.ddns.net:8015/image/remove-background \
  -H 'Content-Type: application/json' \
  -d '{"image_url":"https://example.com/person.jpg","mode":"replace","background_color":"#ffffff"}' \
  --output replaced.png
```

### POST /url/analyze

URL を fetch して構造化データを返します。

| フィールド | 型 | 説明 |
|---|---|---|
| `url` | string | 解析対象の URL（必須） |
| `format` | string | `json`（デフォルト）/ `markdown` |
| `depth` | string | `basic`（デフォルト）/ `full`（本文 8000 字） |

```bash
curl -s -X POST https://exbridge.ddns.net:8015/url/analyze \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://example.com","format":"markdown"}'
```

### POST /url/browse

Playwright でページを描画してスクリーンショットまたはテキスト抽出します。

| フィールド | 型 | 説明 |
|---|---|---|
| `url` | string | 対象 URL（必須） |
| `action` | string | `screenshot`（デフォルト）/ `extract` |
| `wait_until` | string | `networkidle`（デフォルト）/ `load` |

```bash
curl -s -X POST https://exbridge.ddns.net:8015/url/browse \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://example.com","action":"screenshot"}' | jq '.screenshot' | base64 -d > shot.png
```

### POST /url/scan

Shannon-like 3フェーズ セキュリティスキャンを実行します。

| フィールド | 型 | 説明 |
|---|---|---|
| `url` | string | スキャン対象 URL（必須） |

**3フェーズ処理:**
1. **headers** — CSP / X-Frame-Options / HSTS / Referrer-Policy 等の欠落検出
2. **static** — XSSシンク（`document.write` / `innerHTML` / `eval`）、SQLエラー露出、フォーム認証問題、SSRF URLパラメータ、外部スクリプト数
3. **ollama-ai** — Ollama gemma4:e4b による追加findings・推奨アクション生成

```bash
curl -s -X POST https://exbridge.ddns.net:8015/url/scan \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://example.com"}' | python3 -m json.tool
```

**レスポンス例:**
```json
{
  "ok": true,
  "scanner": "shannon-like (headers + static + ollama-ai)",
  "risk_score": 50,
  "summary": "...",
  "findings": {
    "critical": [], "high": [], "medium": ["[xss] Missing CSP"], "low": [...], "info": [...]
  },
  "actions": ["Implement CSP", "Add X-Frame-Options: DENY"],
  "phases": ["1:header-analysis", "2:static-content-analysis", "3:ollama-ai-analysis"]
}
```

## JPYC x402 決済

`server-jpyc.js`（ポート 8017）は x402 プロトコルで JPYC 決済を処理します。

- チェーン: Polygon（chainId 137）
- トークン: JPYC（`0x431D5dfF03120AFA4bDf332c61A6e1766eF37BDB`）
- 料金: 1.5 JPYC / リクエスト（約 1 円）
- 署名方式: EIP-3009 `transferWithAuthorization`

決済なしでリクエストすると `402` と支払い要件が返ります。

## マーケットプレイス登録

| プラットフォーム | 決済 |
|---|---|
| [Bankr](https://bankr.bot) | Bankr 経由 |
| [payapi.market](https://www.payapi.market/) | USDC on Base |
| 直接 JPYC | JPYC on Polygon |

## Agent Skill

`SKILL.md` を同梱しています。Claude Code・GitHub Copilot・Cursor・Codex・Gemini CLI などの AI エージェントから直接インストールして使えます（[agentskills.io](https://agentskills.io) 準拠）。

## ライセンス

`@imgly/background-removal-node` は AGPL-3.0 で配布されています。このサービスをデプロイ・公開する場合は AGPL の条件に従ってください。
