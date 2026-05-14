# USlideBlog APIサーバ起動指示

このファイルは、APIサーバ側のCodexに渡すための作業指示です。

## 目的

`url2ai/apps/uslideblog` に追加されたUSlideBlog renderer APIをAPIサーバ側で起動する。

USlideBlog rendererは、PHP側の `src/uslideblog.php` から呼ばれるNode.js APIです。

使用OSS:

- Marp: Markdownスライド / HTML変換
- PptxGenJS: PowerPoint出力
- Reveal.js: PHP側のブラウザ表示
- Tiptap: PHP側のブラウザ編集UI

## 作業手順

```bash
cd /home/kojima/exdirect/url2ai
git pull

cd apps/uslideblog
npm install
```

Node.jsは18以上が必要です。可能なら `/usr/bin/node` などNode 20系を使ってください。

## 起動

既存APIの連番に合わせ、USlideBlog rendererは次のポートで起動します。

```bash
HOST=0.0.0.0 PORT=8022 /usr/bin/node server.js
```

バックグラウンド起動する場合:

```bash
cd /home/kojima/exdirect/url2ai/apps/uslideblog
HOST=0.0.0.0 PORT=8022 setsid /usr/bin/node server.js > /tmp/uslideblog_renderer.log 2>&1 < /dev/null &
```

## systemd化する場合

`apps/uslideblog/uslideblog.service` を使います。

```bash
sudo cp /home/kojima/exdirect/url2ai/apps/uslideblog/uslideblog.service /etc/systemd/system/uslideblog.service
sudo systemctl daemon-reload
sudo systemctl enable --now uslideblog.service
sudo systemctl status uslideblog.service
```

## 動作確認

```bash
curl http://127.0.0.1:8022/health
```

正常例:

```json
{
  "ok": true,
  "service": "uslideblog",
  "renderer": ["Marp", "PptxGenJS"]
}
```

外部またはheteml/PHP側から到達できることも確認してください。

```bash
curl http://exbridge.ddns.net:8022/health
```

## PHP側設定

PHP側は `src/config.yaml` の次の値を見ます。

```yaml
uslideblog:
  renderer_api: http://exbridge.ddns.net:8022
```

APIサーバの公開ホストやポートが異なる場合は、この値を本番環境の `config.yaml` で変更してください。

## API一覧

```text
GET  /health
POST /api/uslideblog/markdown
POST /api/uslideblog/marp-html
POST /api/uslideblog/pptx
```

サンプル:

```bash
curl -X POST http://127.0.0.1:8022/api/uslideblog/markdown \
  -H 'Content-Type: application/json' \
  -d '{
    "title": "テスト",
    "description": "説明",
    "source_url": "https://example.com",
    "tags": ["VibeCoding"],
    "slides": [
      {"title": "1枚目", "body": "本文です。", "note": "ノート", "layout": "cover"}
    ]
  }'
```

