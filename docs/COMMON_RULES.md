# URL2AI 共通規約

URL2AI 系の PHP アプリを追加・修正するときの共通ルール。

## ログインとセッション

URL2AI 側のログインは、各アプリで個別実装しない。

- 共通モジュール: `src/auth_common.php`
- 共通ログイン入口: `knowradar.php?kr_login=1&return=...`
- 共通ログアウト入口: `knowradar.php?kr_logout=1&return=...`
- X OAuth callback: `knowradar.php`
- セッションキー: `session_access_token`, `session_refresh_token`, `session_token_expires`, `session_username`

各アプリは次の形を基本にする。

```php
require_once __DIR__ . '/auth_common.php';
$auth = url2ai_auth_bootstrap();
$logged_in = $auth['logged_in'];
$session_user = $auth['session_user'];
$is_admin = $auth['is_admin'];
```

ログインリンクは `$auth['login_url']`、ログアウトリンクは `$auth['logout_url']` を使う。  
アプリごとに `redirect_uri` を作らない。

## コピー操作

コピー操作で `alert()` やブラウザのポップアップを出さない。

ボタンを押したら、対象ボタンの表示を一時的に変える。

- 通常時: `コピー`
- 成功時: `コピーしました`
- 1秒前後で元の文言に戻す

失敗時のフォールバックだけ、必要なら非表示textarea経由で `document.execCommand('copy')` を使う。  
`window.prompt()` は最後の手段にする。

## 共有テキスト

X などに貼るコピー内容は、URLだけにしない。

基本形:

```text
タイトル

要約の先頭1〜2行

公開URL
```

HTMLを受け取れる場所向けには、別ボタンで埋め込みコードを用意する。

## 埋め込みコード

埋め込み用ボタン名は `</> 埋め込み` を基本にする。

USlideBlog の例:

```html
<div class="uslideblog-embed">
  <div style="font-weight:bold;margin-bottom:8px;">USlideBlog：タイトル</div>
  <iframe src="https://aiknowledgecms.exbridge.jp/uslideblog.php?id=xxx&embed=1" width="100%" height="520" frameborder="0" allowfullscreen></iframe>
</div>
```

受け側で iframe を許可する場合は、全許可しない。  
許可する `src` をサービス単位でホワイトリスト化する。

## HTML表示とサニタイズ

投稿本文や埋め込みHTMLを表示するアプリでは、必ずサニタイズする。

- 許可タグを明示する
- `script`, `style`, 任意iframeは許可しない
- iframeは信頼できるURLだけ許可する
- URLは `https://` のみを基本にする

## URL本文取得

URL入力型アプリは、入力元に応じて取得方法を分ける。

- `x.com` / `twitter.com`: 通常HTMLではなく `api.fxtwitter.com` など投稿本文取得APIを使う
- `aixec.exbridge.jp/sns.php?id=...`: AIxSNS APIから投稿本文を取る
- 通常ブログ/記事: HTMLから `script/style/nav/footer` などを除外して本文抽出する

本文取得に失敗した場合、エラー画面やJavaScript注意文を生成素材にしない。  
本文が短すぎる場合は生成を止める。

## 表示と編集

表示だけのページでは、編集用の重いJSを読み込まない。  
編集画面だけ、必要なOSSライブラリを読み込む。

外部CDNに依存しない方針が必要な場合は、`src/vendor/` に配置して使う。

## FTP反映

`src/*.php` や `src/vendor/*` を変更したら、本番確認が必要な作業ではFTPアップロードまで行う。  
ローカル修正だけで完了扱いにしない。

