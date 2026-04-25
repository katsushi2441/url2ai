# ERNIE-Image-Turbo を 192.168.0.3 に立てる

## 先に大事な点

`baidu/ERNIE-Image-Turbo` は動画生成モデルではなく、2026-04-17 時点では `text-to-image` モデルです。  
そのため、この手順で作るのは「画像生成 API サーバ」です。

動画生成までやりたい場合は、次のどちらかで構成するのが現実的です。

- まずこの ERNIE でキーフレーム画像を作る
- そのあと別の動画モデルで image-to-video / text-to-video を行う

## 想定スペック

- Ubuntu 22.04 / 24.04
- NVIDIA GPU 24GB VRAM 以上を推奨
- NVIDIA Driver と CUDA が導入済み
- Python 3.10 以上

公式のモデルカードでは、`consumer GPUs with 24G VRAM` での実行が案内されています。

## 追加したファイル

- `apps/ernie-image-turbo/server.py`
- `apps/ernie-image-turbo/requirements.txt`
- `apps/ernie-image-turbo/.env.sample`
- `apps/ernie-image-turbo/setup.sh`
- `apps/ernie-image-turbo/ernie-image-turbo.service`

## サーバ上でのセットアップ例

192.168.0.3 に SSH で入って実行します。

```bash
sudo apt update
sudo apt install -y python3 python3-venv python3-pip git
mkdir -p ~/work
cd ~/work
git clone <このリポジトリのURL> url2ai
cd url2ai/apps/ernie-image-turbo
chmod +x setup.sh
./setup.sh
```

そのあと `.env` を必要に応じて編集します。

```bash
cd /opt/ernie-image-turbo
cp -n .env.sample .env
vi .env
```

## 動作確認

```bash
cd /opt/ernie-image-turbo
source .venv/bin/activate
set -a
source .env
set +a
uvicorn server:app --host "$HOST" --port "$PORT"
```

別端末から:

```bash
curl http://192.168.0.3:8010/healthz
```

画像生成:

```bash
curl -X POST http://192.168.0.3:8010/generate \
  -H "Content-Type: application/json" \
  -d '{
    "prompt": "cinematic street photo at dusk, warm golden light, cyclist silhouette, volumetric light beams",
    "width": 848,
    "height": 1264,
    "num_inference_steps": 8,
    "guidance_scale": 1.0,
    "use_pe": true
  }'
```

レスポンスの `image_base64` をデコードすれば画像を保存できます。

## systemd で常駐化

```bash
sudo cp ernie-image-turbo.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ernie-image-turbo
sudo systemctl status ernie-image-turbo
```

`ernie-image-turbo.service` の `User=ubuntu` はサーバの実ユーザーに合わせて変更してください。

## URL2AI 側から使うなら

この repo は現状 Ollama 前提の呼び出しが中心なので、次のどちらかが扱いやすいです。

- PHP から `http://192.168.0.3:8010/generate` を直接叩く
- 画像生成専用の設定項目を `config.yaml` に追加する

## 動画生成に進むなら

このモデル単体では動画になりません。  
次の段階としては、192.168.0.3 に別の動画モデルを追加して 2 段構成にするのがおすすめです。

- Stage 1: ERNIE-Image-Turbo で画像生成
- Stage 2: 動画モデルで数秒のクリップ生成

必要なら次に、`text-to-video` か `image-to-video` のどちらで組むかに合わせて、192.168.0.3 用の動画サーバ構成までそのまま作れます。
