#!/usr/bin/env bash
# LLM2API のLPと利用状況ページを llm2api.exbridge.jp (heteml) へ配置する。
set -euo pipefail
cd "$(dirname "$0")"
set -a
. /home/kojima/work/aixec/.env
set +a

remote="/web/llm2api_exbridge_jp"
files=(
  "landing/index.html:index.html"          # 英語版
  "landing/llm2api.html:llm2api.html"      # 日本語版
  "landing/assets/style.css:assets/style.css"
  "landing/assets/ogp.png:assets/ogp.png"
  "landing/robots.txt:robots.txt"
  "landing/sitemap.xml:sitemap.xml"
  "landing/usage.php:usage.php"            # 運営用の利用状況ページ
)

for item in "${files[@]}"; do
  local_path="${item%%:*}"
  remote_path="${item#*:}"
  curl --fail --silent --show-error --ftp-create-dirs -T "$local_path" \
    "ftp://${FTP_USER}:${FTP_PASS}@${FTP_HOST}${remote}/${remote_path}"
  echo "deployed: ${remote_path}"
done

# トークンとパスワードを含むためgit管理外。存在するときだけ上げる。
if [[ -f landing/usage_config.php ]]; then
  curl --fail --silent --show-error --ftp-create-dirs -T landing/usage_config.php \
    "ftp://${FTP_USER}:${FTP_PASS}@${FTP_HOST}${remote}/usage_config.php"
  echo "deployed: usage_config.php"
fi

echo "published: https://llm2api.exbridge.jp/"
