"""LLM2API のLPを組み立てる。

意匠は kurl2earn.exbridge.jp/kurl2earn.php をそのまま土台にしている
(Kurageシリーズで見た目を揃えるため、独自に作り直さない)。

「Kurageシリーズ紹介」と「URLAIトークン紹介」は kurl2earn と同じ内容を載せる。
両セクションは kgeo のLPにも同じものを差し込むため、ここで一元的に組み立てて
共有フラグメント(outputs/)としても書き出す。
"""

from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
LANDING = ROOT / "landing"
OUT = ROOT / "outputs"

GA_ID = "G-BP0650KDFR"

TRACKING = f"""<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id={GA_ID}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){{dataLayer.push(arguments);}}
  gtag('js', new Date());
  gtag('config', '{GA_ID}');
</script>
<script>
(function () {{
    var s = document.createElement('script');
    s.src = 'https://aiknowledgecms.exbridge.jp/simpletrack.php'
        + '?url=' + encodeURIComponent(location.href)
        + '&ref=' + encodeURIComponent(document.referrer);
    document.head.appendChild(s);
}})();
</script>"""

# --- Kurageシリーズ(kurl2earnと同じ並び + kgeo) ------------------------------
# (絵文字付き名前, URL, OGP画像, 日本語説明, 英語説明)
PRODUCTS = [
    ("📈 Kurage FreqAI Trade", "https://kfreqai.exbridge.jp/",
     "https://kfreqai.exbridge.jp/assets/ogp.png",
     "自分の負けを自分で研究する、自己改善型の暗号資産AI自動取引。全過程をブログで公開。",
     "Self-improving crypto AI trading that researches its own losses. The whole process is published on the blog."),
    ("🌊 Kurage FreqAI for Hyperliquid", "https://kurage.exbridge.jp/kfreqaihl.php",
     "https://kurage.exbridge.jp/images/kfreqaihl_ogp.png",
     "ウォレット1つ・サーバー不要のAI自動取引。CryptoからFX・金・株価指数まで。",
     "AI auto-trading with one wallet and no server. From crypto to FX, gold and equity indices."),
    ("💱 Kurage FX AI Trade", "https://kfxai.exbridge.jp/",
     "https://kfxai.exbridge.jp/assets/ogp.png",
     "OANDA APIのFX自動運用×差し替え可能なAI判断レイヤー。円ペアをペーパー取引で検証中。",
     "OANDA-API FX automation with a swappable AI judgment layer. Yen pairs are being validated on paper trading."),
    ("🔎 Kurage GEO", "https://kgeo.exbridge.jp/",
     "https://kgeo.exbridge.jp/assets/ogp.png",
     "WebサイトのAI検索対応(GEO)を日本語で監査。技術監査・AEO診断・LLM回答シミュレーション。",
     "Audits how ready your site is for AI search (GEO) in Japanese: technical audit, AEO scoring and grounded answer simulation."),
    ("🏗️ Kurage Architect", "https://kurage.exbridge.jp/karchitect.php",
     "https://kurage.exbridge.jp/images/karchitect-ogp.png",
     "AIと対話しながらシステム設計書を作る。要件定義・Mermaid構成図・PDF出力まで。",
     "Build a system design document by talking with AI: requirements, Mermaid diagrams and PDF export."),
    ("📝 Kurage URL2AI Publisher", "https://url2ai.exbridge.jp/",
     "https://url2ai.exbridge.jp/assets/ogp.png",
     "URLを渡すとKurageさんが記事を読み、告知文とブログを書いて5媒体へ自動配信。",
     "Give it a URL and Kurage reads the page, writes an announcement and a blog post, then publishes to five channels."),
    ("🎬 kmontage", "https://kmontage.exbridge.jp/",
     "https://kmontage.exbridge.jp/assets/ogp.png",
     "台本から動画（モンタージュ）を自動生成する、Kurageの動画制作システム。",
     "Kurage's video production system that generates montages automatically from a script."),
    ("🖱️ Kurage Argo Video（kargov）", "https://github.com/katsushi2441/kargov",
     "https://kurl2earn.exbridge.jp/assets/cards/kargov.png",
     "AIがブラウザを操作した記録から、デモ・マニュアル動画を自動生成する制作パイプライン。",
     "A pipeline that turns recordings of AI browser operations into demo and manual videos."),
    ("🪼 Kurage（総合ポータル）", "https://kurage.exbridge.jp/",
     "https://kurage.exbridge.jp/images/kurage_ogp.png",
     "Kurageシリーズの入口。全プロダクトと紹介動画をまとめたポータル。",
     "The entrance to the Kurage series. All products and intro videos in one place."),
]

TEXT = {
    "ja": {
        "eco_h": '広がっている<em>Kurageシリーズ</em>',
        "eco_sub": 'LLM2APIは、Kurageエコシステムの推論基盤です。同じ仕組みが以下のプロダクトを動かしています。',
        "v_h": 'URLAIは<em>エコシステムのトークン</em>',
        "v_p": 'URLAIは、<b>Kurageエコシステムを広めるためのトークン</b>です。'
               '<a href="https://kurl2earn.exbridge.jp/kurl2earn.php" target="_blank" rel="noopener">URL2Earn</a>だけでなく、'
               '<b>kfreqaiのアンバサダー</b>にも配布されます。',
        "v_l1": 'アンバサダーは <b>kfreqai</b> を使って暗号資産・FXをトレードし、その成果を発信します。',
        "v_l2": 'それが kfreqai の<b>収益性を高め、認知を広め</b>、やがて<b>たくさんの人の収益につながるプロジェクト</b>を目指しています。',
        "v_l3": 'URLAIは、その拡散と貢献に対して配られる、<b>トークノミクスにおけるエコシステムの一部</b>になることを目指しています。',
        "v_fine": '※URLAIは <a href="https://kfreqai.exbridge.jp/kfreqai.html" target="_blank" rel="noopener">Kurage FreqAI</a> '
                  'エコシステムのトークンです。価格や流動性は市場により変動し、金銭的価値を保証するものではありません。'
                  '受け取りは投資助言ではありません。',
    },
    "en": {
        "eco_h": 'The growing <em>Kurage series</em>',
        "eco_sub": 'LLM2API is the inference layer of the Kurage ecosystem. The same plumbing powers the products below.',
        "v_h": 'URLAI is the <em>ecosystem token</em>',
        "v_p": 'URLAI is <b>the token for spreading the Kurage ecosystem</b>. Beyond '
               '<a href="https://kurl2earn.exbridge.jp/kurl2earn.php?lang=en" target="_blank" rel="noopener">URL2Earn</a>, '
               'it is also distributed to <b>kfreqai ambassadors</b>.',
        "v_l1": 'Ambassadors trade crypto and FX with <b>kfreqai</b> and share their results.',
        "v_l2": 'That <b>improves kfreqai\'s profitability and spreads awareness</b>, aiming at '
                '<b>a project that eventually earns for many people</b>.',
        "v_l3": 'URLAI aims to be <b>part of the ecosystem\'s tokenomics</b>, distributed for that spreading and contribution.',
        "v_fine": '* URLAI is a token of the <a href="https://kfreqai.exbridge.jp/" target="_blank" rel="noopener">Kurage FreqAI</a> '
                  'ecosystem. Its price and liquidity fluctuate with the market and no monetary value is guaranteed. '
                  'Claiming is not investment advice.',
    },
}


def products_html(lang: str) -> str:
    idx = 3 if lang == "ja" else 4
    cards = []
    for name, url, image, *desc in PRODUCTS:
        text = desc[0] if lang == "ja" else desc[1]
        label = url.replace("https://", "").rstrip("/")
        cards.append(
            f'    <a class="prod" href="{url}" target="_blank" rel="noopener">\n'
            f'      <div class="im"><img src="{image}" alt="{name}" loading="lazy"></div>\n'
            f'      <div class="tx"><div class="nm disp">{name}</div>\n'
            f'        <div class="ds">{text}</div>\n'
            f'        <div class="lk">{label} →</div></div>\n'
            f'    </a>'
        )
    del idx
    return "\n".join(cards)


def common_sections(lang: str) -> str:
    t = TEXT[lang]
    return f"""<section id="ecosystem">
  <h2 class="sec disp">{t['eco_h']}</h2>
  <p class="sec-sub">{t['eco_sub']}</p>
  <div class="prods">
{products_html(lang)}
  </div>
</section>

<section id="urlai">
  <h2 class="sec disp">{t['v_h']}</h2>
  <div class="vision">
    <p style="font-size:14.5px; margin-bottom:12px">{t['v_p']}</p>
    <ul>
      <li>{t['v_l1']}</li>
      <li>{t['v_l2']}</li>
      <li>{t['v_l3']}</li>
    </ul>
    <p class="fine">{t['v_fine']}</p>
  </div>
</section>"""


BUBBLES = ('<div class="bubbles" aria-hidden="true">'
           + "".join(
               f'<span style="left:{left}%;width:{size}px;height:{size}px;'
               f'animation-duration:{dur}s;animation-delay:{delay}s"></span>'
               for left, size, dur, delay in
               [(6, 14, 17, 0), (18, 9, 21, 3), (31, 20, 15, 6), (44, 11, 24, 1),
                (57, 16, 19, 8), (69, 8, 26, 4), (81, 18, 16, 2), (93, 12, 22, 7)]
           ) + "</div>")


def page(lang: str) -> str:
    ja = lang == "ja"
    canonical = ("https://llm2api.exbridge.jp/llm2api.html" if ja
                 else "https://llm2api.exbridge.jp/")
    title = ("LLM2API — エージェントが1回ずつ買えるLLM推論（登録不要・APIキー不要）" if ja
             else "LLM2API — Pay-per-call LLM inference for AI agents (no signup, no API key)")
    desc = ("OpenAI互換のchat completionsを、x402プロトコルでUSDC(Base)またはJPYC(Polygon)の従量課金で提供。"
            "1リクエスト$0.05。サブスク登録ができない自律エージェントのためのLLM APIです。" if ja else
            "OpenAI-compatible chat completions billed per request in USDC (Base) or JPYC (Polygon) over x402. "
            "$0.05 per request. Built for autonomous agents that cannot sign up for a subscription.")
    other = ("./", "English") if ja else ("llm2api.html", "日本語")

    hero_h1 = ("エージェントが<em>1回ずつ</em><br>買えるLLM推論。" if ja
               else "LLM inference your agent<br>can buy <em>one request</em> at a time.")
    hero_lead = ("OpenAI互換のchat completionsを、x402プロトコルでステーブルコイン従量課金で提供します。"
                 "アカウントもダッシュボードもAPIキーの発行も要りません。<b>支払いそのものが認可</b>です。" if ja else
                 "An OpenAI-compatible chat completions endpoint billed per call in stablecoins over the x402 "
                 "protocol. No account, no dashboard, no API key to provision — <b>the payment is the "
                 "authorization</b>.")
    eyebrow = ("KURAGE ECOSYSTEM ・ 推論基盤" if ja else "KURAGE ECOSYSTEM ・ INFERENCE LAYER")
    speech = ("登録なしで<br>すぐ使えます 🪼" if ja else "No signup.<br>Just call it 🪼")

    why_h = ("なぜ<em>登録不要</em>なのか" if ja else "Why <em>no signup</em>")
    why_p = ("ホスト型のLLM APIは、どれも「まず人間が登録すること」を前提にしています。"
             "1回だけ推論を必要とする自律エージェントは、アカウントを作り、メールを確認し、"
             "カードを登録してキーを発行する、という手順を踏めません。これは技術的な制約ではなく"
             "<b>手続き上の構造的な障壁</b>です。LLM2APIはそこを外しました。" if ja else
             "Every hosted LLM API assumes a human signs up first. An autonomous agent that needs one "
             "completion cannot create an account, confirm an email, enter a card and provision a key. "
             "That signup flow is a <b>structural barrier, not a technical one</b>. LLM2API removes it.")

    qs_h = ("すぐ使う" if ja else "Quickstart")
    qs_sub = ("既存のOpenAI呼び出しがあるなら、ベースURLを差し替えるだけです。" if ja
              else "If your code already calls OpenAI, change the base URL.")
    comment = ("# x402で支払いながら1回呼ぶ（Bankr CLI）" if ja
               else "# Call once, paying with x402 (Bankr CLI)")
    ask = "&quot;一文で要約して&quot;" if ja else "&quot;Summarise this in one sentence.&quot;"
    curl = (f'<span class="c">{comment}</span>\n'
            '<span class="k">bankr</span> x402 call \\\n'
            '  https://x402.bankr.bot/0x444fadbd6e1fed0cfbf7613b6c9f91b9021eecbd/llm2api/v1/chat/completions \\\n'
            '  -X POST \\\n'
            f"  -d '{{&quot;messages&quot;:[{{&quot;role&quot;:&quot;user&quot;,&quot;content&quot;:{ask}}}]}}'")

    price_h = ("料金" if ja else "Pricing")
    price_sub = ("1リクエストあたりの定額です。サブスクリプション・最低利用額・有効期限はありません。" if ja
                 else "Flat per request. No subscription, no minimum, no expiry.")
    rails = [
        ("$0.05", "/ request" if not ja else "/ リクエスト", "Bankr x402 — USDC (Base)",
         "既定のレール。エージェントやMCPワークフローはこちらを推奨します。" if ja
         else "The default rail. Recommended for agents and MCP workflows."),
        ("7.5", "JPYC / request" if not ja else "JPYC / リクエスト", "JPYC x402 — Polygon",
         "円建てで決済したい場合に使います。" if ja else "For yen-denominated settlement."),
        ("RapidAPI", "", "通常のAPIキー方式" if ja else "Conventional key-based access",
         "従来どおりキーと月額プランで使いたい場合の窓口です。" if ja
         else "If you would rather use a normal API key and monthly plan."),
    ]
    rail_cards = "\n".join(
        f'    <div class="card2"><div class="big">{big}</div><div class="unit">{unit}</div>'
        f'<h3 style="margin-top:10px">{head}</h3><p>{body}</p></div>'
        for big, unit, head, body in rails)

    spec_h = ("API仕様" if ja else "API")
    spec_rows = [
        ("<code>messages</code>", "配列" if ja else "array", "必須" if ja else "Yes",
         "<code>{role, content}</code> の配列。roleは <code>system</code> / <code>user</code> / <code>assistant</code>。" if ja
         else "<code>{role, content}</code> objects. Roles: <code>system</code>, <code>user</code>, <code>assistant</code>."),
        ("<code>stream</code>", "真偽値" if ja else "boolean", "任意" if ja else "No",
         "SSEストリーミング。既定は <code>false</code>。" if ja else "SSE streaming. Default <code>false</code>."),
        ("<code>temperature</code>", "数値" if ja else "number", "任意" if ja else "No",
         "0.0〜2.0。既定は <code>0.7</code>。" if ja else "0.0–2.0. Default <code>0.7</code>."),
        ("<code>max_tokens</code>", "整数" if ja else "integer", "任意" if ja else "No",
         "指定値によらず2,048の上限が適用されます。" if ja else "A hard cap of 2,048 applies regardless."),
    ]
    spec_head = (("項目", "型", "必須", "説明") if ja else ("Field", "Type", "Required", "Notes"))
    spec_table = (
        f'    <table class="spec"><tr>' + "".join(f"<th>{h}</th>" for h in spec_head) + "</tr>\n"
        + "\n".join("      <tr>" + "".join(f"<td>{c}</td>" for c in row) + "</tr>" for row in spec_rows)
        + "\n    </table>")

    limits = ([
        "<b>入力</b> — 全メッセージ合計で4,000文字まで、メッセージ数は20件まで",
        "<b>出力</b> — 1リクエストあたり2,048トークンの固定上限",
        "<b>モデル</b> — 有料レールはすべて <code>deepseek-v4-flash</code>",
    ] if ja else [
        "<b>Input</b> — 4,000 characters total across all messages, 20 messages maximum",
        "<b>Output</b> — hard cap of 2,048 tokens per request",
        "<b>Model</b> — every paid rail is served by <code>deepseek-v4-flash</code>",
    ])
    limit_note = ("この上限は意図的なものです。1件のリクエストがバックエンドを占有するのを防ぎ、"
                  "1コール定額という価格を成立させるために置いています。" if ja else
                  "These caps are deliberate. They keep a single request from monopolising the backend "
                  "and keep the flat per-call price honest.")

    honest_h = ("正直な制約" if ja else "Honest limitations")
    honest = ([
        "個人が運用する小規模サービスです。大手クラウドのような稼働率保証はありません。",
        "上記の入出力上限は固定です。長文ドキュメントを扱う用途には向きません。",
        "リクエストは上流プロバイダへ中継されます。第三者のLLM APIに送れないデータは送らないでください。",
    ] if ja else [
        "This is a small, self-operated service. It is not a hyperscaler and makes no uptime guarantee.",
        "The input and output caps above are hard limits. Long-document workloads are not a fit.",
        "Requests are proxied to an upstream provider. Do not send data you would not send to a third-party LLM API.",
    ])

    footer = ("LLM2API — <a href=\"https://exbridge.jp/\">株式会社エクスブリッジ</a>。"
              "Kurageエコシステムの推論基盤です 🪼" if ja else
              "LLM2API — <a href=\"https://exbridge.jp/\">EXBRIDGE Inc.</a> "
              "The inference layer of the Kurage ecosystem 🪼")

    schema = f"""<script type="application/ld+json">
{{"@context":"https://schema.org","@type":"Product","name":"LLM2API",
 "description":{desc!r},
 "brand":{{"@type":"Brand","name":"Kurage"}},
 "url":"{canonical}",
 "image":"https://llm2api.exbridge.jp/assets/ogp.png",
 "offers":{{"@type":"Offer","price":"0.05","priceCurrency":"USD",
   "url":"https://x402.bankr.bot/0x444fadbd6e1fed0cfbf7613b6c9f91b9021eecbd/llm2api",
   "availability":"https://schema.org/InStock"}}}}
</script>""".replace("'", '"')

    return f"""<!DOCTYPE html>
<html lang="{lang}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title}</title>
<meta name="description" content="{desc}">
<meta name="robots" content="index, follow">
<link rel="canonical" href="{canonical}">
<link rel="alternate" hreflang="ja" href="https://llm2api.exbridge.jp/llm2api.html">
<link rel="alternate" hreflang="en" href="https://llm2api.exbridge.jp/">
<link rel="alternate" hreflang="x-default" href="https://llm2api.exbridge.jp/">
<meta property="og:title" content="{title}">
<meta property="og:description" content="{desc}">
<meta property="og:type" content="website">
<meta property="og:url" content="{canonical}">
<meta property="og:image" content="https://llm2api.exbridge.jp/assets/ogp.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:site_name" content="LLM2API">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="https://llm2api.exbridge.jp/assets/ogp.png">
{schema}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@700;900&family=Noto+Sans+JP:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
{TRACKING}
</head>
<body>
{BUBBLES}

<header>
  <div class="brand">
    <img src="https://kurage.exbridge.jp/blog/kurage_avatar_face.webp" alt="Kurage">
    <span>LLM<em>2</em>API</span>
  </div>
  <div class="userbar"><a href="{other[0]}">{other[1]}</a></div>
</header>

<main>
<section class="hero">
  <div>
    <span class="eyebrow"><span class="dot"></span>{eyebrow}</span>
    <h1 class="disp">{hero_h1}</h1>
    <p class="lead">{hero_lead}</p>
    <div class="statrow">
      <span class="pill"><b>$0.05</b> / {"リクエスト" if ja else "request"}</span>
      <span class="pill mode-live">OpenAI{"互換" if ja else "-compatible"}</span>
      <span class="pill">DeepSeek v4 Flash</span>
    </div>
    <div class="cta-row">
      <a class="btn gold" href="https://x402.bankr.bot/0x444fadbd6e1fed0cfbf7613b6c9f91b9021eecbd/llm2api">
        {"🪼 エンドポイントを叩く" if ja else "🪼 Call the endpoint"}</a>
      <a class="btn ghost" href="#quickstart">{"使い方を見る" if ja else "See the quickstart"}</a>
    </div>
  </div>
  <div class="hero-kurage">
    <div class="speech">{speech}</div>
    <div class="kfloat"><img class="kurage" src="https://kurage.exbridge.jp/images/kurage-ecosystem-avatar.png" alt="Kurage"></div>
    <div class="coin3"><span class="a">$0.05</span><span class="b">/ req</span></div>
  </div>
</section>

<section>
  <h2 class="sec disp">{why_h}</h2>
  <div class="vision"><p style="font-size:14.5px">{why_p}</p></div>
</section>

<section id="quickstart">
  <h2 class="sec disp">{qs_h}</h2>
  <p class="sec-sub">{qs_sub}</p>
  <pre class="code">{curl}</pre>
</section>

<section>
  <h2 class="sec disp">{price_h}</h2>
  <p class="sec-sub">{price_sub}</p>
  <div class="cards">
{rail_cards}
  </div>
</section>

<section>
  <h2 class="sec disp">{spec_h}</h2>
  <p class="sec-sub"><code>POST {{base}}/v1/chat/completions</code></p>
  <div class="vision">
    <div class="table-wrap">
{spec_table}
    </div>
    <ul class="limits" style="margin-top:18px">
{chr(10).join(f"      <li>{x}</li>" for x in limits)}
    </ul>
    <p class="fine">{limit_note}</p>
  </div>
</section>

<section>
  <h2 class="sec disp">{honest_h}</h2>
  <div class="vision">
    <ul class="limits">
{chr(10).join(f"      <li>{x}</li>" for x in honest)}
    </ul>
  </div>
</section>

{common_sections(lang)}

</main>
<footer>{footer}</footer>
</body>
</html>
"""


def main() -> int:
    LANDING.mkdir(parents=True, exist_ok=True)
    OUT.mkdir(parents=True, exist_ok=True)
    (LANDING / "index.html").write_text(page("en"), encoding="utf-8")
    (LANDING / "llm2api.html").write_text(page("ja"), encoding="utf-8")
    # kgeo など他プロダクトのLPへ差し込むための共有フラグメント
    for lang in ("ja", "en"):
        (OUT / f"common_sections_{lang}.html").write_text(common_sections(lang), encoding="utf-8")
    print(f"生成: index.html / llm2api.html と共有フラグメント2件 (商材{len(PRODUCTS)}件)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
