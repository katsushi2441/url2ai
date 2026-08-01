# ERNIE-Image-Turbo サーバー

## 現在の本番構成（2026-08-01）

- 公開APIゲートウェイ: `192.168.0.11:8010`
- ERNIE推論サーバー: `192.168.0.11:18300`（RTX 3080 10GB）
- 画像転送先: `ERNIE_BASE_URL=http://192.168.0.11:18300`
- PDF転送先: `PDF_BASE_URL=http://192.168.0.3:8010/pdf`
- 実行方式: `OFFLOAD_MODE=sequential`

RTX 3080で848×1264・8ステップを2回連続生成し、HTTP 200、初回49秒、2回目39秒、ピークVRAM約2.1GBを確認した。通常の384×384・4ステップはゲートウェイ経由で約21.8秒だった。

## 先に大事な点

`baidu/ERNIE-Image-Turbo` は動画生成モデルではなく、2026-04-17 時点では `text-to-image` モデルです。  
そのため、この手順で作るのは「画像生成 API サーバ」です。

動画生成までやりたい場合は、次のどちらかで構成するのが現実的です。

- まずこの ERNIE でキーフレーム画像を作る
- そのあと別の動画モデルで image-to-video / text-to-video を行う

## 想定スペック

- Ubuntu 22.04 / 24.04
- NVIDIA GPU 10GB VRAM以上（`sequential` CPUオフロードでRTX 3080 10GBを検証済み）
- システムRAM 32GB以上（48GB推奨）
- NVIDIA Driver と CUDA が導入済み
- Python 3.10 以上

フルGPU実行には大容量VRAMが必要だが、このAPIは既定で逐次CPUオフロードを使うため、10GB VRAMでも動作する。

## 追加したファイル

- `apps/ernie-image-turbo/server.py`
- `apps/ernie-image-turbo/requirements.txt`
- `apps/ernie-image-turbo/.env.sample`
- `apps/ernie-image-turbo/setup.sh`
- `apps/ernie-image-turbo/ernie-image-turbo.service`

## サーバ上でのセットアップ例

推論ホストにSSHで入って実行します。RTX 3080とCUDA 12.5対応ドライバの組み合わせでは、CUDA 12.4版PyTorchを使用します。

```bash
sudo apt update
sudo apt install -y python3 python3-venv python3-pip git
mkdir -p ~/work
cd ~/work
git clone <このリポジトリのURL> url2ai
cd url2ai/apps/ernie-image-turbo
chmod +x setup.sh
VENV_DIR=.venv-cu124 \
TORCH_INDEX_URL=https://download.pytorch.org/whl/cu124 \
./setup.sh
```

そのあと `.env` を必要に応じて編集します。

```bash
cd ~/work/url2ai/apps/ernie-image-turbo
cp -n .env.sample .env
vi .env
```

## 動作確認

```bash
cd ~/work/url2ai/apps/ernie-image-turbo
source .venv-cu124/bin/activate
set -a
source .env
set +a
uvicorn server:app --host "$HOST" --port "$PORT"
```

別端末から:

```bash
curl http://192.168.0.11:18300/healthz
```

画像生成:

```bash
curl -X POST http://192.168.0.11:18300/generate \
  -H "Content-Type: application/json" \
  -d '{
    "prompt": "cinematic street photo at dusk, warm golden light, cyclist silhouette, volumetric light beams",
    "width": 848,
    "height": 1264,
    "num_inference_steps": 8,
    "guidance_scale": 1.0,
    "use_pe": false
  }'
```

レスポンスの `image_base64` をデコードすれば画像を保存できます。

## systemd で常駐化

本番のRTX 3080ホストではユーザーサービスとして登録しています。

```bash
systemctl --user enable --now ernie-image-turbo.service
loginctl enable-linger "$USER"
systemctl --user status ernie-image-turbo.service
```

## URL2AI 側から使うなら

利用側は推論ホストを直接参照せず、従来どおりゲートウェイを使います。

```text
http://192.168.0.11:8010/image/generate
```

公開ルーターの外部8010はRTX 3080ホストの`192.168.0.11:8010`へ転送します。外部8011は使用しません。x402 UImageと一般クライアントは、公開URL `http://exbridge.ddns.net:8010/image/generate` を共有します。

## 動画生成に進むなら

このモデル単体では動画になりません。  
次の段階としては、別の動画モデルを追加して2段構成にします。

- Stage 1: ERNIE-Image-Turbo で画像生成
- Stage 2: 動画モデルで数秒のクリップ生成

動画側は `text-to-video` または `image-to-video` の用途に合わせて別サービスとして構成します。
