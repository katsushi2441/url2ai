#!/usr/bin/env bash
# LLM2API の3サービスを root の system unit から user unit へ移す。
#
# なぜ: 以前は /etc/systemd/system で root として動いており、コードを直しても
# 再起動に毎回 sudo が必要だった。node の実行に root 権限は要らない。
# 移行後は `systemctl --user restart llm-gateway` だけで反映できる。
#
# sudo が要るのはこのスクリプトの中の「旧root unitを止めて無効化する」1回だけ。
set -euo pipefail
cd "$(dirname "$0")"

UNITS=(llm-gateway llm-gateway-rapidapi llm-gateway-jpyc)
USER_DIR="$HOME/.config/systemd/user"

echo "== 1. user unit を設置 =="
mkdir -p "$USER_DIR"
for u in "${UNITS[@]}"; do
  cp "systemd/$u.service" "$USER_DIR/$u.service"
  echo "  installed: $USER_DIR/$u.service"
done
systemctl --user daemon-reload

echo "== 2. 移行前の状態を記録 =="
before=$(curl -s -m 8 http://127.0.0.1:8019/health || echo "取得不可")
echo "  8019 health: $before"

echo "== 3. 旧 root unit を停止・無効化 (sudo が必要) =="
sudo systemctl disable --now "${UNITS[@]}"
# Restart=always なので、確実に落ちたことを確認してから起動する
for port in 8019 8018 8020; do
  for i in $(seq 1 15); do
    ss -ltn | grep -q ":$port " || break
    sleep 1
  done
  if ss -ltn | grep -q ":$port "; then
    echo "  !! ポート $port がまだ使用中。中止する（user unit は起動しない）" >&2
    exit 1
  fi
done
echo "  8019/8018/8020 の解放を確認"

echo "== 4. user unit を起動 =="
systemctl --user enable --now "${UNITS[@]}"
sleep 4

echo "== 5. 検証 =="
fail=0
for u in "${UNITS[@]}"; do
  state=$(systemctl --user is-active "$u" || true)
  printf "  %-24s %s\n" "$u" "$state"
  [[ "$state" == "active" ]] || fail=1
done
after=$(curl -s -m 10 http://127.0.0.1:8019/health || echo "取得不可")
echo "  8019 health: $after"
echo "$after" | grep -q '"ok":true' || fail=1
# 移行前後でプロバイダが変わっていないこと(x402の課金対象が変わると事故)
before_provider=$(echo "$before" | grep -oE '"provider":"[^"]+"' || true)
after_provider=$(echo "$after" | grep -oE '"provider":"[^"]+"' || true)
if [[ -n "$before_provider" && "$before_provider" != "$after_provider" ]]; then
  echo "  !! provider が変化した: $before_provider -> $after_provider" >&2
  fail=1
fi

if [[ $fail -ne 0 ]]; then
  echo
  echo "移行に失敗した。旧構成へ戻すには:" >&2
  echo "  systemctl --user disable --now ${UNITS[*]}" >&2
  echo "  sudo systemctl enable --now ${UNITS[*]}" >&2
  exit 1
fi

echo
echo "移行完了。以後 sudo は不要:"
echo "  systemctl --user restart llm-gateway"
