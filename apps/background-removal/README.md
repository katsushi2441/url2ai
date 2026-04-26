# OSS2API Background Removal

[URL2AI](https://github.com/katsushi2441/url2ai) プロジェクトの OSS2API サービスの一つ。
[imgly/background-removal-js](https://github.com/imgly/background-removal-js) を API 化し、JPYC x402 決済に対応した AI エージェントスキルです。

## 機能

| モード | 説明 |
|---|---|
| `remove` | 背景を除去して透過 PNG/WebP/JPEG を返す |
| `replace` | 背景を除去して指定色または画像で置き換える |
| `blur` | 背景を除去してぼかし処理した背景と合成する |

## 構成

| ファイル | ポート | 用途 |
|---|---|---|
| `server.js` | 8015 | 無料（Bankr / payapi.market 経由） |
| `server-jpyc.js` | 8017 | JPYC x402 直接決済（Polygon） |

## セットアップ

```bash
npm install
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

### POST /run

**リクエスト**

| フィールド | 型 | 説明 |
|---|---|---|
| `image_url` | string | 入力画像の HTTPS URL |
| `image_base64` | string | base64 または data URL 形式の画像 |
| `mode` | string | `remove`（デフォルト）/ `replace` / `blur` |
| `background_color` | string | `replace` モード用 CSS カラー（例: `#ffffff`） |
| `background_image_url` | string | `replace` モード用背景画像 URL |
| `blur_sigma` | number | `blur` モードのぼかし強度 1–80（デフォルト 18） |
| `output_format` | string | `png`（デフォルト）/ `webp` / `jpeg` |
| `output_quality` | number | 1–100（デフォルト 90） |
| `response` | string | `binary`（デフォルト）/ `json`（base64 返却） |

## 使用例

**背景除去（透過 PNG）**

```bash
curl -sS https://exbridge.ddns.net:8015/run \
  -H 'Content-Type: application/json' \
  -d '{"image_url":"https://example.com/product.jpg","mode":"remove"}' \
  --output removed.png
```

**背景を白に置換**

```bash
curl -sS https://exbridge.ddns.net:8015/run \
  -H 'Content-Type: application/json' \
  -d '{"image_url":"https://example.com/person.jpg","mode":"replace","background_color":"#ffffff"}' \
  --output replaced.png
```

**背景をぼかす**

```bash
curl -sS https://exbridge.ddns.net:8015/run \
  -H 'Content-Type: application/json' \
  -d '{"image_url":"https://example.com/portrait.jpg","mode":"blur","blur_sigma":22}' \
  --output blurred.png
```

**JSON（base64）で受け取る**

```bash
curl -sS https://exbridge.ddns.net:8015/run \
  -H 'Content-Type: application/json' \
  -d '{"image_url":"https://example.com/photo.jpg","mode":"remove","response":"json"}'
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
