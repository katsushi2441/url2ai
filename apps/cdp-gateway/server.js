import express from "express";
import nodeHttp from "node:http";
import { paymentMiddleware } from "x402-express";
import { createFacilitatorConfig } from "@coinbase/x402";

const PORT       = Number.parseInt(process.env.PORT || "8021", 10);
const PAY_TO     = process.env.PAY_TO || "";
const OSS2API    = process.env.OSS2API_URL || "http://127.0.0.1:8015";
const LLM_URL    = process.env.LLM_URL    || "http://127.0.0.1:8019";
const NETWORK    = process.env.NETWORK    || "base";
const PRICE_OSS  = process.env.PRICE_OSS  || "$0.01";
const PRICE_LLM  = process.env.PRICE_LLM  || "$0.05";
const FXBRAIN_URL   = process.env.FXBRAIN_URL   || "http://127.0.0.1:18326";
const FXBRAIN_TOKEN = process.env.FXBRAIN_TOKEN || "";
// TradingAgentsフルグラフは1回5分超(実測5.5分)のマルチエージェント討論なので高価格帯
const PRICE_FXGRAPH = process.env.PRICE_FXGRAPH || "$0.50";
const KCBRAIN_URL   = process.env.KCBRAIN_URL   || "http://127.0.0.1:18328";
const KCBRAIN_TOKEN = process.env.KCBRAIN_TOKEN || "";
// nofx(claw402)のAI Trading Signals($0.001)と同水準。DeepSeek実測原価は単発判断で
// $0.0001程度(原価率約10%)。2026-07-21 ユーザー承認済み(claw402価格に合わせる方針)。
const PRICE_KC_SINGLE = process.env.PRICE_KC_SINGLE || "$0.001";
// bull/bear/manager等、generate_jsonを複数回連鎖する多段エンドポイント用。単発と同額だと
// 原価率60%超になるため、claw402上のDeepSeek Chat/Reasoner相場($0.003-0.005)に合わせる。
const PRICE_KC_CHAIN  = process.env.PRICE_KC_CHAIN  || "$0.003";
const URL2BRAIN_URL   = process.env.URL2BRAIN_URL   || "http://127.0.0.1:18332";
const URL2BRAIN_TOKEN = process.env.URL2BRAIN_TOKEN || "";
// URL解析+告知文+ブログ記事生成(DeepSeek/deepseek-v4-flash。x402課金コールはDeepSeekへ)。
// 2026-07-21 ユーザー指定: 1コール$1.00固定(llm2apiと同じく、エンドポイントによらず均一)。
const PRICE_URL2BRAIN = process.env.PRICE_URL2BRAIN || "$1.00";
const BACKGROUND_REMOVAL_SCHEMA = {
  bodyType: "json",
  properties: {
    image_url: { type: "string", description: "Public image URL" },
    image_base64: { type: "string", description: "Base64 encoded image" },
    mode: { type: "string", description: "remove, replace, or blur" },
    background_color: { type: "string", description: "Replacement color for mode=replace" },
    background_image_url: { type: "string", description: "Replacement image URL for mode=replace" },
    response: { type: "string", description: "json or binary" },
  },
};

function paidRoute(description, inputSchema) {
  return {
    price: PRICE_OSS,
    network: NETWORK,
    config: {
      description,
      discoverable: true,
      inputSchema,
    },
  };
}

function llmRoute() {
  return {
    price: PRICE_LLM,
    network: NETWORK,
    config: {
      description: "OpenAI-compatible chat completions via DeepSeek (deepseek-v4-flash)",
      discoverable: true,
      inputSchema: {
        bodyType: "json",
        properties: {
          messages: { type: "array", description: "Array of {role, content} objects" },
          temperature: { type: "number" },
          max_tokens: { type: "integer" },
        },
      },
    },
  };
}

function tradeRiskRoute() {
  return {
    price: PRICE_LLM,
    network: NETWORK,
    config: {
      description: "Crypto token risk check: scans recent news for hack/exploit/delisting/rug-pull/lawsuit events and returns a block/ok verdict with classified evidence. Same pipeline that protects the live Kurage FreqAI trading system.",
      discoverable: true,
      inputSchema: {
        bodyType: "json",
        properties: {
          symbol: { type: "string", description: "Base symbol, e.g. BTC (2-15 alphanumeric chars)" },
        },
      },
    },
  };
}

function tradeSizeRoute() {
  return {
    price: PRICE_LLM,
    network: NETWORK,
    config: {
      description: "Crypto order size / liquidity check: applies the live Kurage FreqAI 0.1%-of-24h-volume cap and returns max safe size with thin-market warning. Sub-second.",
      discoverable: true,
      inputSchema: {
        bodyType: "json",
        properties: {
          symbol: { type: "string", description: "Base symbol, e.g. DOGE" },
          order_size_usdt: { type: "number", description: "Intended order size in USDT" },
        },
      },
    },
  };
}

// Kurage FX Brain (kfxbrain :18326) — FX judgment APIs backed by DeepSeek.
// Vendored OSS intelligence: TradingAgents (Apache-2.0), FinGPT (MIT), ai-hedge-fund (MIT).
const FXBRAIN_EVIDENCE_SCHEMA = {
  bodyType: "json",
  properties: {
    pair: { type: "string", description: "FX pair like USD_JPY or EUR_USD (required)" },
    timeframe: { type: "string", description: "e.g. M15, H1, D (default H1)" },
    technicals: { type: "object", description: "Indicator values, ranges, closes" },
    macro: { type: "object", description: "Rates, CPI, policy expectations" },
    news: { type: "array", description: "Headlines (max 40)" },
    position: { type: "object", description: "Open position context" },
    history: { type: "array", description: "Recent trades (max 30)" },
    question: { type: "string", description: "Optional focused question" },
  },
};
const FXBRAIN_GRAPH_SCHEMA = {
  bodyType: "json",
  properties: {
    pair: { type: "string", description: "FX pair like USD_JPY (required)" },
    trade_date: { type: "string", description: "YYYY-MM-DD (default today)" },
    debate_rounds: { type: "integer", description: "1-3 (default 1)" },
    risk_rounds: { type: "integer", description: "1-3 (default 1)" },
    output_language: { type: "string", description: "Report language (default Japanese)" },
  },
};

const FXBRAIN_MARKET_SCHEMA = {
  bodyType: "json",
  properties: {
    pairs: { type: "array", description: "1-40 pair evidence objects: {pair, market, technicals, macro, flows, positioning, news}" },
    timeframe: { type: "string", description: "e.g. H1 (default)" },
    global_context: { type: "object", description: "Risk sentiment, calendar, cross-market context" },
    account_context: { type: "object", description: "Leverage, equity, margin (for margin-risk)" },
    question: { type: "string", description: "Optional focused question" },
  },
};

// gateway suffix -> [upstream path, price, description, schema]
const FXBRAIN_ENDPOINTS = {
  "analyze/technical": ["/v1/analyze/technical", PRICE_LLM,
    "FX technical analysis (trend, levels, momentum) as structured JSON with evidence and invalidation. DeepSeek."],
  "analyze/macro": ["/v1/analyze/macro", PRICE_LLM,
    "FX macro analysis: rate differentials, growth, policy divergence as structured JSON. DeepSeek."],
  "analyze/sentiment": ["/v1/analyze/sentiment", PRICE_LLM,
    "FX news and market sentiment analysis as structured JSON. DeepSeek."],
  "analyze/full": ["/v1/analyze/full", PRICE_LLM,
    "All FX perspectives (technical, macro, sentiment, decision) in one structured response. DeepSeek."],
  "debate/bull-bear": ["/v1/debate/bull-bear", PRICE_LLM,
    "Bull vs bear argument mapping for an FX pair as structured JSON. DeepSeek."],
  "decide/trade": ["/v1/decide/trade", PRICE_LLM,
    "BUY / SELL / HOLD judgment for an FX pair from supplied evidence. Judgment only — never executes orders."],
  "assess/risk": ["/v1/assess/risk", PRICE_LLM,
    "Risk gate for a proposed FX trade: approve / reduce / reject with reasons. Judgment only."],
  "decide/portfolio": ["/v1/decide/portfolio", PRICE_LLM,
    "Manage open FX positions: hold / close / adjust judgments as structured JSON. Judgment only."],
  "review/trade": ["/v1/review/trade", PRICE_LLM,
    "Post-trade review: category, verdict, lesson from a closed FX trade. DeepSeek."],
  "fingpt/sentiment": ["/v1/vendor/fingpt/sentiment", PRICE_LLM,
    "FinGPT (MIT) financial sentiment classification task executed on DeepSeek."],
  "fingpt/headline": ["/v1/vendor/fingpt/headline", PRICE_LLM,
    "FinGPT (MIT) headline classification task executed on DeepSeek."],
  "fingpt/forecast": ["/v1/vendor/fingpt/forecast", PRICE_LLM,
    "FinGPT (MIT) Forecaster task: evidence-grounded FX outlook on DeepSeek."],
  "fingpt/report": ["/v1/vendor/fingpt/report", PRICE_LLM,
    "FinGPT (MIT) financial report analysis task executed on DeepSeek."],
  "hedge/news-sentiment": ["/v1/vendor/ai-hedge-fund/news-sentiment", PRICE_LLM,
    "ai-hedge-fund (MIT) news sentiment agent: per-headline classification plus aggregate signal for an FX pair."],
  "hedge/portfolio": ["/v1/vendor/ai-hedge-fund/portfolio", PRICE_LLM,
    "ai-hedge-fund (MIT) portfolio manager synthesis over supplied analyst signals. Judgment only."],
  "finrobot/forecast": ["/v1/vendor/finrobot/forecast", PRICE_LLM,
    "FinRobot (Apache-2.0) Market Forecaster workflow: 2-4 positive developments and concerns, next-week move prediction with % range. DeepSeek."],
  ...Object.fromEntries([
    "income_stmt", "balance_sheet", "cash_flow", "segment_stmt",
    "risk_assessment", "competitors", "business_highlights", "company_description",
  ].map((s) => [`finrobot/report/${s}`, [`/v1/vendor/finrobot/report/${s}`, PRICE_LLM,
    `FinRobot (Apache-2.0) analyst instruction: ${s.replace(/_/g, " ")} analysis on supplied evidence. Honestly reports missing data instead of inventing.`]])),
  "finmem/decide": ["/v1/vendor/finmem/decide", PRICE_LLM,
    "FinMem (MIT) layered-memory trading decision: pass short/mid/long/reflection memories via prior_reports{}; character switches risk-seeking/averse by cumulative return."],
  "finmem/reflect": ["/v1/vendor/finmem/reflect", PRICE_LLM,
    "FinMem (MIT) reflection loop: extract the lesson from a trade outcome plus supporting memories, ready to store as a reflection memory."],
  "market/opportunity-ranking": ["/v1/market/opportunity-ranking", PRICE_LLM,
    "Multi-pair FX opportunity ranking (up to 40 pairs in one call): risk-adjusted scores, duplicated-currency exposure conflicts, event risk. Body: {pairs:[{pair, technicals, news, flows, positioning}...], global_context}. DeepSeek.",
    FXBRAIN_MARKET_SCHEMA],
  "market/flow-ranking": ["/v1/market/flow-ranking", PRICE_LLM,
    "Multi-pair currency-flow strength ranking from supplied COT/positioning, rate differential, carry and liquidity evidence. Same multi-pair body. DeepSeek.",
    FXBRAIN_MARKET_SCHEMA],
  "market/anomaly": ["/v1/market/anomaly", PRICE_LLM,
    "Cross-pair FX anomaly detection: price/spread/volatility/volume/rates/positioning/correlation/intervention/liquidity, severity low-critical. Ordinary volatility is not flagged. Same multi-pair body.",
    FXBRAIN_MARKET_SCHEMA],
  "market/margin-risk": ["/v1/market/margin-risk", PRICE_LLM,
    "Margin-call and stop-out risk ranking per pair plus systemic risk, from supplied leverage/equity/margin thresholds. Never assumes broker rules. Same multi-pair body.",
    FXBRAIN_MARKET_SCHEMA],
  ...Object.fromEntries([
    "USD_JPY", "EUR_JPY", "GBP_JPY", "EUR_USD", "GBP_USD", "AUD_USD",
  ].map((p) => [`signal/pair/${p}`, [`/v1/signal/pair/${p}`, PRICE_LLM,
    `Single evidence-bounded FX signal for ${p}: watch_buy_base/watch_sell_base/wait/avoid with invalidation and event risks. Judgment only, never places orders.`]])),
  // tradingagents/run(フルグラフ)は撤去(2026-07-22)。逐次LLM呼び出しが多くx402の
  // 180秒上限を常に超過し=実質購入不可(無課金でクリーン失敗)、品質も伴わないため製品から外す。
  // kfxbrain側のエンドポイントは残るがx402には公開しない。
};

function fxbrainRoute(price, description, schema, maxTimeoutSeconds) {
  const config = { description, discoverable: true, inputSchema: schema || FXBRAIN_EVIDENCE_SCHEMA };
  // 既定のmaxTimeoutSeconds(60)はEIP-3009のvalidBefore=now+60sを意味する。
  // 処理が60秒を超えるエンドポイント(tradingagents/run ~5.5分)はここで延長しないと、
  // 決済時にblock.timestamp>validBeforeで期限切れ拒否され、GPU計算だけ浪費される
  // (PayApi Chet 2026-07-17指摘)。
  if (maxTimeoutSeconds) config.maxTimeoutSeconds = maxTimeoutSeconds;
  return { price, network: NETWORK, config };
}

// Kurage Crypto Brain (kcbrain :18328) — crypto judgment APIs backed by DeepSeek
// (deepseek-v4-flash)。Vendored OSS: ai-hedge-fund-crypto, crypto-trading-agents,
// Vibe-Trading, LLM_trader, HELM Agents。単発判断(generate_json 1回)と、bull/bear/manager
// 等の多段連鎖(generate_json複数回)で原価が10倍近く違うため価格帯を分ける(2026-07-21実測)。
const KCBRAIN_EVIDENCE_SCHEMA = {
  bodyType: "json",
  properties: {
    symbol: { type: "string", description: "Crypto pair like BTC_USDT or ETH_USDT (required)" },
    timeframe: { type: "string", description: "e.g. H1, H4, D (default H1)" },
    technicals: { type: "object", description: "Indicator values, ranges, closes" },
    derivatives: { type: "object", description: "Funding rate, open interest, liquidations" },
    onchain: { type: "object", description: "Exchange flows, active addresses, whale activity" },
    defi: { type: "object", description: "TVL, protocol metrics" },
    news: { type: "array", description: "Headlines (max 60)" },
    social: { type: "array", description: "Social posts/sentiment (max 60)" },
    position: { type: "object", description: "Open position context" },
    portfolio: { type: "object", description: "Cash, positions, margin (for portfolio endpoints)" },
    history: { type: "array", description: "Recent trades (max 50)" },
    question: { type: "string", description: "Optional focused question" },
  },
};

const KCBRAIN_MARKET_SCHEMA = {
  bodyType: "json",
  properties: {
    pairs: { type: "array", description: "1-40 pair evidence objects: {symbol, market, technicals, derivatives, onchain, news}" },
    timeframe: { type: "string", description: "e.g. H1 (default)" },
    global_context: { type: "object", description: "Risk sentiment, macro, cross-market context" },
    account_context: { type: "object", description: "Leverage, equity, margin (for liquidation-risk)" },
    question: { type: "string", description: "Optional focused question" },
  },
};

// gateway suffix -> [upstream path, price, description, schema]
const KCBRAIN_ENDPOINTS = {
  "analyze/technical": ["/v1/analyze/technical", PRICE_KC_SINGLE,
    "Crypto technical analysis (trend, levels, momentum) as structured JSON with evidence and invalidation. DeepSeek."],
  "analyze/onchain": ["/v1/analyze/onchain", PRICE_KC_SINGLE,
    "Crypto on-chain analysis: exchange flows, whale activity, address growth as structured JSON. DeepSeek."],
  "analyze/sentiment": ["/v1/analyze/sentiment", PRICE_KC_SINGLE,
    "Crypto news and social sentiment analysis as structured JSON. DeepSeek."],
  "analyze/full": ["/v1/analyze/full", PRICE_KC_SINGLE,
    "All crypto perspectives (technical, onchain, sentiment, debate, trade, risk) in one structured response. DeepSeek."],
  "debate/bull-bear": ["/v1/debate/bull-bear", PRICE_KC_SINGLE,
    "Bull vs bear argument mapping for a crypto pair as structured JSON. DeepSeek."],
  "decide/trade": ["/v1/decide/trade", PRICE_KC_SINGLE,
    "BUY / SELL / HOLD judgment for a crypto pair from supplied evidence. Judgment only — never executes orders."],
  "assess/risk": ["/v1/assess/risk", PRICE_KC_SINGLE,
    "Risk gate for a proposed crypto trade: approve / reduce / reject with reasons. Judgment only."],
  "decide/portfolio": ["/v1/decide/portfolio", PRICE_KC_SINGLE,
    "Manage open crypto positions: hold / close / adjust judgments as structured JSON. Judgment only."],
  "review/trade": ["/v1/review/trade", PRICE_KC_SINGLE,
    "Post-trade review: category, verdict, lesson from a closed crypto trade. DeepSeek."],
  "market/opportunity-ranking": ["/v1/market/opportunity-ranking", PRICE_KC_SINGLE,
    "Multi-pair crypto opportunity ranking (up to 40 pairs in one call): risk-adjusted scores, event risk. Body: {pairs:[{symbol, technicals, derivatives, onchain, news}...], global_context}. DeepSeek.",
    KCBRAIN_MARKET_SCHEMA],
  "market/flow-ranking": ["/v1/market/flow-ranking", PRICE_KC_SINGLE,
    "Multi-pair crypto capital-flow strength ranking from supplied exchange-flow, funding, and positioning evidence. Same multi-pair body. DeepSeek.",
    KCBRAIN_MARKET_SCHEMA],
  "market/anomaly": ["/v1/market/anomaly", PRICE_KC_SINGLE,
    "Cross-pair crypto anomaly detection: price/spread/volatility/volume/funding/OI/liquidation, severity low-critical. Ordinary volatility is not flagged. Same multi-pair body.",
    KCBRAIN_MARKET_SCHEMA],
  "market/liquidation-risk": ["/v1/market/liquidation-risk", PRICE_KC_SINGLE,
    "Liquidation-cascade risk ranking per pair plus systemic risk, from supplied leverage/OI/funding thresholds. Never assumes exchange rules. Same multi-pair body.",
    KCBRAIN_MARKET_SCHEMA],
  ...Object.fromEntries([
    "BTC_USDT", "ETH_USDT", "SOL_USDT", "BNB_USDT", "XRP_USDT", "DOGE_USDT",
  ].map((p) => [`signal/pair/${p}`, [`/v1/signal/pair/${p}`, PRICE_KC_SINGLE,
    `Single evidence-bounded crypto signal for ${p}: watch_buy_base/watch_sell_base/wait/avoid with invalidation and event risks. Judgment only, never places orders.`]])),
  "vendor/ai-hedge-fund-crypto/portfolio": ["/v1/vendor/ai-hedge-fund-crypto/portfolio", PRICE_KC_SINGLE,
    "ai-hedge-fund-crypto (MIT) portfolio manager synthesis over supplied analyst signals. Single-call judgment only."],
  "vendor/llm-trader/analyze": ["/v1/vendor/llm-trader/analyze", PRICE_KC_SINGLE,
    "LLM_trader decision-gated crypto analysis: trend, momentum, funding, confluence, risk/reward. Single-call structured JSON."],
  "vendor/crypto-trading-agents/debate": ["/v1/vendor/crypto-trading-agents/debate", PRICE_KC_CHAIN,
    "crypto-trading-agents (from TradingAgents, Apache-2.0) bull vs bear researcher debate chained into a research-manager decision. 3 sequential DeepSeek calls."],
  "vendor/vibe-trading/research": ["/v1/vendor/vibe-trading/research", PRICE_KC_CHAIN,
    "Vibe-Trading crypto trading desk preset: multiple specialist agents plus a risk-manager synthesis. 4+ sequential DeepSeek calls."],
  "vendor/helm-agents/consensus": ["/v1/vendor/helm-agents/consensus", PRICE_KC_CHAIN,
    "HELM Agents 4-analyst consensus (market/sentiment/news/fundamentals) chained into a portfolio-manager rating. 5 sequential DeepSeek calls."],
};

// x402のmaxTimeoutSeconds(validBefore)はプロキシのハード締切(60s)より必ず長くする。
// 同値だと決済期限切れと処理中断がほぼ同時に起き、正常応答でも決済が拒否されうる
// (PayApi/Chetがfxbrainで指摘したのと同じ理由)。10秒マージンを常に持たせる。
function kcbrainRoute(price, description, schema) {
  return {
    price, network: NETWORK,
    config: { description, discoverable: true, inputSchema: schema || KCBRAIN_EVIDENCE_SCHEMA, maxTimeoutSeconds: 70 },
  };
}

// Kurage URL2AI Publisher brain (url2brain :18332) — URL解析+告知文+ブログ記事生成。
// DeepSeek(deepseek-v4-flash)。/v1/post/*(Kurage自身のSNS/ブログへの投稿)は
// 第三者が課金だけで投稿できてしまうため、意図的にゲートウェイへ載せない
// (analyze/generateの読み取り専用スキルのみ公開)。
const URL2BRAIN_URL_SCHEMA = {
  bodyType: "json",
  properties: {
    url: { type: "string", description: "Publicly reachable HTTP(S) URL to analyze and write about (required for analyze/url, generate/from-url)" },
    language: { type: "string", description: "Output language. Default ja (Japanese); pass en for English." },
    tone: { type: "string", description: "Optional tone hint, e.g. neutral, enthusiastic. Default neutral." },
  },
};
const URL2BRAIN_SOURCE_SCHEMA = {
  bodyType: "json",
  properties: {
    source: { type: "object", description: "The object returned in result.source by analyze/url or generate/from-url (required)" },
    language: { type: "string", description: "Output language. Default ja (Japanese); pass en for English." },
    tone: { type: "string", description: "Optional tone hint, e.g. neutral, enthusiastic. Default neutral." },
  },
};

// gateway suffix -> [upstream path, description, schema]
const URL2BRAIN_ENDPOINTS = {
  "analyze/url": ["/v1/analyze/url",
    "Fetch a URL and extract structured content: title, description, headings, links, body text.",
    URL2BRAIN_URL_SCHEMA],
  "generate/announcement": ["/v1/generate/announcement",
    "Generate a <=280 char announcement (Japanese by default) grounded only in the supplied extracted content (source from analyze/url). DeepSeek.",
    URL2BRAIN_SOURCE_SCHEMA],
  "generate/blog-article": ["/v1/generate/blog-article",
    "Generate a 300-600 word blog article (Japanese by default) grounded only in the supplied extracted content (source from analyze/url). DeepSeek.",
    URL2BRAIN_SOURCE_SCHEMA],
  "generate/from-url": ["/v1/generate/from-url",
    "Convenience endpoint: fetch a URL, then generate both an announcement and a blog article from it in one call. The recommended single-call skill for 'just paste a URL'. DeepSeek.",
    URL2BRAIN_URL_SCHEMA],
};

// 投稿5媒体はKurage/EXBRIDGE自身のアカウント(認証情報はurl2brainサーバー側に保存)に対して
// 実際に投稿する。x402決済(=第三者による直接の見放題スパムへの経済的な歯止め)を通した
// 支払いを実投稿の許可として扱う設計(2026-07-21 ユーザー承認)。投稿文はKurage/bittensorman
// ペルソナで自動的に枠付けされる(persona注入、下記)。生の自由入力テキストをそのまま
// Kurage名義で投稿させるわけではなく、url2brainが生成した文章(generate/*)を渡す用途を想定。
const URL2BRAIN_POST_ENDPOINTS = {
  "post/bluesky": ["/v1/post/bluesky", "kurage", "$1.00", {
    bodyType: "json",
    properties: {
      text: { type: "string", description: "Post text, max 280 chars including the Kurage persona prefix (required)" },
      url: { type: "string", description: "Optional URL to include" },
    },
  }, "Post to Kurage's own Bluesky account (@bittensorman.bsky.social), framed in the Kurage persona. Actually publishes — x402 payment is treated as the posting authorization."],
  "post/hatena-bookmark": ["/v1/post/hatena-bookmark", "", "$1.00", {
    bodyType: "json",
    properties: {
      url: { type: "string", description: "URL to bookmark (required)" },
      comment: { type: "string", description: "Bookmark comment, max 100 chars" },
      tags: { type: "array", description: "Optional tags" },
    },
  }, "Post to Kurage's Hatena Bookmark account. No persona framing (short comment only). Actually publishes."],
  "post/aixsns": ["/v1/post/aixsns", "bittensorman", "$1.00", {
    bodyType: "json",
    properties: {
      content: { type: "string", description: "Post content, max 2000 chars (required)" },
      author: { type: "string", description: "Display author name" },
    },
  }, "Post to AIxSNS (aixec.exbridge.jp) as bittensorman (developer/business persona). Actually publishes."],
  "post/bludit": ["/v1/post/bludit", "kurage", "$1.00", {
    bodyType: "json",
    properties: {
      title: { type: "string", description: "Article title (required)" },
      body_markdown: { type: "string", description: "Article body in Markdown (required)" },
    },
  }, "Post a full article to Kurage's own Bludit blog (kurage.exbridge.jp/blog, url2pub category), framed in the Kurage persona. Actually publishes."],
  "post/hatena-blog": ["/v1/post/hatena-blog", "bittensorman", "$1.00", {
    bodyType: "json",
    properties: {
      title: { type: "string", description: "Article title (required)" },
      body_markdown: { type: "string", description: "Article body in Markdown (required)" },
    },
  }, "Post a full article to bittensorman's Hatena Blog, framed in the developer/business persona. Actually publishes."],
};

function url2brainRoute(description, schema) {
  return {
    price: PRICE_URL2BRAIN, network: NETWORK,
    config: { description, discoverable: true, inputSchema: schema, maxTimeoutSeconds: 100 },
  };
}

function url2brainPostRoute(price, description, schema) {
  return {
    price, network: NETWORK,
    config: { description, discoverable: true, inputSchema: schema, maxTimeoutSeconds: 60 },
  };
}

if (!PAY_TO) { console.error("PAY_TO is required"); process.exit(1); }

const facilitator = createFacilitatorConfig(
  process.env.CDP_API_KEY_ID,
  process.env.CDP_API_KEY_SECRET,
);

const routes = {
  "GET /background-removal": paidRoute("Remove or replace image background (imgly AGPL-3.0)", BACKGROUND_REMOVAL_SCHEMA),
  "POST /background-removal": paidRoute("Remove or replace image background (imgly AGPL-3.0)", BACKGROUND_REMOVAL_SCHEMA),
  "GET /oss2api": paidRoute("OSS2API multi-skill agent gateway", { bodyType: "json", properties: {} }),
  "POST /oss2api": paidRoute("OSS2API multi-skill agent gateway", { bodyType: "json", properties: {} }),
  "GET /oss2api/": paidRoute("OSS2API multi-skill agent gateway", { bodyType: "json", properties: {} }),
  "POST /oss2api/": paidRoute("OSS2API multi-skill agent gateway", { bodyType: "json", properties: {} }),
  "GET /oss2api/image/remove-background": paidRoute("Remove or replace image background (imgly AGPL-3.0)", BACKGROUND_REMOVAL_SCHEMA),
  "POST /oss2api/image/remove-background": paidRoute("Remove or replace image background (imgly AGPL-3.0)", BACKGROUND_REMOVAL_SCHEMA),
  "GET /oss2api/url/analyze": {
    price: PRICE_OSS,
    network: NETWORK,
    config: {
      description: "Extract title, headings, links and entities from a URL",
      discoverable: true,
      inputSchema: { bodyType: "json", properties: { url: { type: "string", description: "Target URL" } } },
    },
  },
  "POST /oss2api/url/analyze": {
    price: PRICE_OSS,
    network: NETWORK,
    config: {
      description: "Extract title, headings, links and entities from a URL",
      discoverable: true,
      inputSchema: { bodyType: "json", properties: { url: { type: "string", description: "Target URL" } } },
    },
  },
  "GET /oss2api/url/browse": {
    price: PRICE_OSS,
    network: NETWORK,
    config: {
      description: "Playwright screenshot and dynamic content extraction from a URL",
      discoverable: true,
      inputSchema: { bodyType: "json", properties: { url: { type: "string", description: "Target URL" } } },
    },
  },
  "POST /oss2api/url/browse": {
    price: PRICE_OSS,
    network: NETWORK,
    config: {
      description: "Playwright screenshot and dynamic content extraction from a URL",
      discoverable: true,
      inputSchema: { bodyType: "json", properties: { url: { type: "string", description: "Target URL" } } },
    },
  },
  "GET /oss2api/url/scan": {
    price: PRICE_OSS,
    network: NETWORK,
    config: {
      description: "3-phase security scan: HTTP headers + static HTML + AI analysis",
      discoverable: true,
      inputSchema: { bodyType: "json", properties: { url: { type: "string", description: "Target URL" } } },
    },
  },
  "POST /oss2api/url/scan": {
    price: PRICE_OSS,
    network: NETWORK,
    config: {
      description: "3-phase security scan: HTTP headers + static HTML + AI analysis",
      discoverable: true,
      inputSchema: { bodyType: "json", properties: { url: { type: "string", description: "Target URL" } } },
    },
  },
  "GET /llm2api": llmRoute(),
  "POST /llm2api": llmRoute(),
  "GET /llm2api/": llmRoute(),
  "POST /llm2api/": llmRoute(),
  "GET /llm2api/v1/chat/completions": llmRoute(),
  "POST /llm2api/v1/chat/completions": llmRoute(),
  "GET /llm/v1/chat/completions": llmRoute(),
  "POST /llm/v1/chat/completions": llmRoute(),
  "GET /llm2api/trade/risk-check": tradeRiskRoute(),
  "POST /llm2api/trade/risk-check": tradeRiskRoute(),
  "GET /llm2api/trade/size-check": tradeSizeRoute(),
  "POST /llm2api/trade/size-check": tradeSizeRoute(),
};

for (const [suffix, [, price, description, schema, maxTimeoutSeconds]] of Object.entries(FXBRAIN_ENDPOINTS)) {
  routes[`POST /fxbrain/${suffix}`] = fxbrainRoute(price, description, schema, maxTimeoutSeconds);
}
for (const [suffix, [, price, description, schema]] of Object.entries(KCBRAIN_ENDPOINTS)) {
  routes[`POST /kcbrain/${suffix}`] = kcbrainRoute(price, description, schema);
}
for (const [suffix, [, description, schema]] of Object.entries(URL2BRAIN_ENDPOINTS)) {
  routes[`POST /url2brain/${suffix}`] = url2brainRoute(description, schema);
}
for (const [suffix, [, , price, schema, description]] of Object.entries(URL2BRAIN_POST_ENDPOINTS)) {
  routes[`POST /url2brain/${suffix}`] = url2brainPostRoute(price, description, schema);
}

const app = express();
app.set("trust proxy", true);
app.use(express.json({ limit: "20mb" }));
app.use((_req, res, next) => {
  const originalJson = res.json.bind(res);
  res.json = (body) => {
    if (res.statusCode === 402 && body && Array.isArray(body.accepts)) {
      const encoded = Buffer.from(JSON.stringify(body)).toString("base64");
      res.setHeader("PAYMENT-REQUIRED", encoded);
      res.setHeader("X-PAYMENT-REQUIRED", encoded);
      res.setHeader("Access-Control-Expose-Headers", "PAYMENT-REQUIRED, X-PAYMENT-REQUIRED, X-PAYMENT-RESPONSE");
    }
    return originalJson(body);
  };
  next();
});
app.use(paymentMiddleware(PAY_TO, routes, facilitator));

async function proxyTo(url, req, res) {
  try {
    const upstream = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(req.body),
    });
    const contentType = upstream.headers.get("content-type") || "application/json";
    const bytes = await upstream.arrayBuffer();
    res.status(upstream.status).set("Content-Type", contentType).end(Buffer.from(bytes));
  } catch (err) {
    res.status(502).json({ error: `Upstream unavailable: ${err.message}` });
  }
}

app.get(["/health", "/healthz"], (_req, res) => {
  res.json({ ok: true, service: "cdp-gateway", port: PORT });
});

const WALLET = PAY_TO;
const X402_WELL_KNOWN = {
  "version": "1",
  "x402Version": 1,
  "pay_to": WALLET,
  "wallet": WALLET,
  "treasury": WALLET,
  "network": NETWORK,
  "endpoints": [
    {
      "path": "/oss2api",
      "method": "POST",
      "price": PRICE_OSS,
      "network": NETWORK,
      "pay_to": WALLET,
      "description": "OSS2API multi-skill agent gateway"
    },
    {
      "path": "/oss2api/image/remove-background",
      "method": "POST",
      "price": PRICE_OSS,
      "network": NETWORK,
      "pay_to": WALLET,
      "description": "Remove or replace image background (imgly/background-removal-js AGPL-3.0)"
    },
    {
      "path": "/oss2api/url/analyze",
      "method": "POST",
      "price": PRICE_OSS,
      "network": NETWORK,
      "pay_to": WALLET,
      "description": "Extract structured content from a URL"
    },
    {
      "path": "/oss2api/url/browse",
      "method": "POST",
      "price": PRICE_OSS,
      "network": NETWORK,
      "pay_to": WALLET,
      "description": "Playwright screenshot and content extraction"
    },
    {
      "path": "/oss2api/url/scan",
      "method": "POST",
      "price": PRICE_OSS,
      "network": NETWORK,
      "pay_to": WALLET,
      "description": "3-phase security scan"
    },
    {
      "path": "/llm2api",
      "method": "POST",
      "price": PRICE_LLM,
      "network": NETWORK,
      "pay_to": WALLET,
      "description": "OpenAI-compatible chat completions via DeepSeek (deepseek-v4-flash)"
    },
    {
      "path": "/llm2api/v1/chat/completions",
      "method": "POST",
      "price": PRICE_LLM,
      "network": NETWORK,
      "pay_to": WALLET,
      "description": "OpenAI-compatible chat completions via DeepSeek (deepseek-v4-flash)"
    },
    {
      "path": "/llm2api/trade/risk-check",
      "method": "POST",
      "price": PRICE_LLM,
      "network": NETWORK,
      "pay_to": WALLET,
      "description": "Crypto token risk check: recent hack/exploit/delisting/rug-pull/lawsuit scan with block/ok verdict"
    },
    {
      "path": "/llm2api/trade/size-check",
      "method": "POST",
      "price": PRICE_LLM,
      "network": NETWORK,
      "pay_to": WALLET,
      "description": "Crypto order size / liquidity check: max safe size from 24h volume (0.1% cap rule)"
    }
  ]
};

for (const [suffix, [, price, description]] of Object.entries(FXBRAIN_ENDPOINTS)) {
  X402_WELL_KNOWN.endpoints.push({
    path: `/fxbrain/${suffix}`,
    method: "POST",
    price,
    network: NETWORK,
    pay_to: WALLET,
    description,
  });
}
for (const [suffix, [, price, description]] of Object.entries(KCBRAIN_ENDPOINTS)) {
  X402_WELL_KNOWN.endpoints.push({
    path: `/kcbrain/${suffix}`,
    method: "POST",
    price,
    network: NETWORK,
    pay_to: WALLET,
    description,
  });
}
for (const [suffix, [, description]] of Object.entries(URL2BRAIN_ENDPOINTS)) {
  X402_WELL_KNOWN.endpoints.push({
    path: `/url2brain/${suffix}`,
    method: "POST",
    price: PRICE_URL2BRAIN,
    network: NETWORK,
    pay_to: WALLET,
    description,
  });
}
for (const [suffix, [, , price, , description]] of Object.entries(URL2BRAIN_POST_ENDPOINTS)) {
  X402_WELL_KNOWN.endpoints.push({
    path: `/url2brain/${suffix}`,
    method: "POST",
    price,
    network: NETWORK,
    pay_to: WALLET,
    description,
  });
}

app.get("/.well-known/x402.json", (_req, res) => {
  res.json(X402_WELL_KNOWN);
});

app.post("/background-removal",               (req, res) => proxyTo(`${OSS2API}/oss2api/image/remove-background`, req, res));
app.post("/oss2api",                          (req, res) => proxyTo(`${OSS2API}/oss2api/url/analyze`, req, res));
app.post("/oss2api/",                         (req, res) => proxyTo(`${OSS2API}/oss2api/url/analyze`, req, res));
app.post("/oss2api/image/remove-background", (req, res) => proxyTo(`${OSS2API}/oss2api/image/remove-background`, req, res));
app.post("/oss2api/url/analyze",             (req, res) => proxyTo(`${OSS2API}/oss2api/url/analyze`, req, res));
app.post("/oss2api/url/browse",              (req, res) => proxyTo(`${OSS2API}/oss2api/url/browse`, req, res));
app.post("/oss2api/url/scan",                (req, res) => proxyTo(`${OSS2API}/oss2api/url/scan`, req, res));
app.post("/llm2api",                         (req, res) => proxyTo(`${LLM_URL}/v1/chat/completions`, req, res));
app.post("/llm2api/",                        (req, res) => proxyTo(`${LLM_URL}/v1/chat/completions`, req, res));
app.post("/llm2api/v1/chat/completions",     (req, res) => proxyTo(`${LLM_URL}/v1/chat/completions`, req, res));
app.post("/llm/v1/chat/completions",         (req, res) => proxyTo(`${LLM_URL}/v1/chat/completions`, req, res));
app.post("/llm2api/trade/risk-check",        (req, res) => proxyTo(`${LLM_URL}/trade/risk-check`, req, res));
app.post("/llm2api/trade/size-check",        (req, res) => proxyTo(`${LLM_URL}/trade/size-check`, req, res));

// kfxbrain proxy: node:httpで長タイムアウト(TradingAgentsフルグラフは実測5.5分、
// fetch/undiciの既定headersTimeout 300秒では途中で切れる)。認証トークンを注入。
// エッジ(bittensorman.xyz/nginx)のproxy_read_timeoutが約180秒。ここより手前で
// 打ち切って >=400 を返せば、x402-expressのpaymentMiddlewareは settle をスキップする
// (res.statusCode >= 400 で決済しない)。これで「処理が長引いた時に課金だけされて
// 納品されない(charge-without-delivery)」を構造的に防ぐ。PayApi/Chet 2026-07-18指摘。
const FXBRAIN_DEADLINE_MS = Number(process.env.FXBRAIN_DEADLINE_MS || 170000);

function proxyToFxbrain(upstreamPath, req, res) {
  const body = JSON.stringify(req.body);
  const url = new URL(`${FXBRAIN_URL}${upstreamPath}`);
  const options = {
    hostname: url.hostname,
    port: url.port,
    path: url.pathname,
    method: "POST",
    timeout: FXBRAIN_DEADLINE_MS + 10000,
    headers: {
      "Content-Type": "application/json",
      "Content-Length": Buffer.byteLength(body),
      "X-KFXBRAIN-Token": FXBRAIN_TOKEN,
      // 課金コール(x402)はDeepSeekを使う。kfxbrainの既定はローカルGemma(WEB/kfxai直叩き用)。
      "X-KFXBrain-Provider": "deepseek",
    },
  };
  let settled = false;
  const once = (fn) => { if (!settled) { settled = true; clearTimeout(deadline); fn(); } };
  const proxyReq = nodeHttp.request(options, (upRes) => {
    once(() => {
      res.status(upRes.statusCode || 502);
      res.set("Content-Type", upRes.headers["content-type"] || "application/json");
      upRes.pipe(res);
    });
  });
  // ハード締切: 超えたら上流を切って504を返す → x402は課金しない(no charge)。
  const deadline = setTimeout(() => {
    once(() => {
      proxyReq.destroy(new Error("fxbrain deadline"));
      if (!res.headersSent) {
        res.status(504).json({
          error: "workflow exceeded the gateway deadline; no payment was captured. Please retry.",
        });
      }
    });
  }, FXBRAIN_DEADLINE_MS);
  proxyReq.on("timeout", () => proxyReq.destroy(new Error("fxbrain upstream timeout")));
  proxyReq.on("error", (err) => {
    once(() => { if (!res.headersSent) res.status(502).json({ error: `fxbrain unavailable: ${err.message}` }); });
  });
  proxyReq.write(body);
  proxyReq.end();
}

for (const [suffix, [upstreamPath]] of Object.entries(FXBRAIN_ENDPOINTS)) {
  app.post(`/fxbrain/${suffix}`, (req, res) => proxyToFxbrain(upstreamPath, req, res));
}

// kcbrain proxy: DeepSeek(hosted API)はkfxbrainと同じDeepSeek(hosted)で高速
// (単発判断は実測2.5秒)。多段連鎖(3-5コール)でも数十秒想定のため締切は60秒で十分。
// 締切超過は>=400を返しx402-express側でsettleをスキップ(charge-without-delivery防止、
// PayApi/Chet指摘のfxbrainと同じ構造)。
const KCBRAIN_DEADLINE_MS = Number(process.env.KCBRAIN_DEADLINE_MS || 60000);

function proxyToKcbrain(upstreamPath, req, res) {
  const body = JSON.stringify(req.body);
  const url = new URL(`${KCBRAIN_URL}${upstreamPath}`);
  const options = {
    hostname: url.hostname,
    port: url.port,
    path: url.pathname,
    method: "POST",
    timeout: KCBRAIN_DEADLINE_MS + 10000,
    headers: {
      "Content-Type": "application/json",
      "Content-Length": Buffer.byteLength(body),
      "X-KCBRAIN-Token": KCBRAIN_TOKEN,
      // 課金コール(x402)はDeepSeek。kcbrain既定のローカルGemma(kfreqai毎時ジョブ等の直叩き用)を上書き。
      "X-KCBRAIN-Provider": "deepseek",
    },
  };
  let settled = false;
  const once = (fn) => { if (!settled) { settled = true; clearTimeout(deadline); fn(); } };
  const proxyReq = nodeHttp.request(options, (upRes) => {
    once(() => {
      res.status(upRes.statusCode || 502);
      res.set("Content-Type", upRes.headers["content-type"] || "application/json");
      upRes.pipe(res);
    });
  });
  const deadline = setTimeout(() => {
    once(() => {
      proxyReq.destroy(new Error("kcbrain deadline"));
      if (!res.headersSent) {
        res.status(504).json({
          error: "workflow exceeded the gateway deadline; no payment was captured. Please retry.",
        });
      }
    });
  }, KCBRAIN_DEADLINE_MS);
  proxyReq.on("timeout", () => proxyReq.destroy(new Error("kcbrain upstream timeout")));
  proxyReq.on("error", (err) => {
    once(() => { if (!res.headersSent) res.status(502).json({ error: `kcbrain unavailable: ${err.message}` }); });
  });
  proxyReq.write(body);
  proxyReq.end();
}

for (const [suffix, [upstreamPath]] of Object.entries(KCBRAIN_ENDPOINTS)) {
  app.post(`/kcbrain/${suffix}`, (req, res) => proxyToKcbrain(upstreamPath, req, res));
}

// url2brain proxy: x402課金コールはDeepSeek(deepseek-v4-flash)へ振る
// 締切を90秒に取る(実測: generate/from-url で約11秒)。fxbrain/kcbrainと同じ
// charge-without-delivery防止パターン(締切超過は>=400を返しx402側でsettleをスキップ)。
const URL2BRAIN_DEADLINE_MS = Number(process.env.URL2BRAIN_DEADLINE_MS || 90000);

// 有料x402コールは常にDeepSeek(ホスト型API)へ強制する。url2pub Webアプリ(url2brainへ直接
// アクセスするローカル呼び出し)はこの注入を経由しないためローカルGemma4のまま
// (2026-07-21方針: 課金コールとローカルGPUのKurage本番系を競合させない)。
const URL2BRAIN_LLM_SUFFIXES = new Set(["generate/announcement", "generate/blog-article", "generate/from-url"]);

function proxyToUrl2brain(upstreamPath, req, res, suffix) {
  const payload = URL2BRAIN_LLM_SUFFIXES.has(suffix)
    ? { ...req.body, provider: "deepseek" }
    : req.body;
  const body = JSON.stringify(payload);
  const url = new URL(`${URL2BRAIN_URL}${upstreamPath}`);
  const options = {
    hostname: url.hostname,
    port: url.port,
    path: url.pathname,
    method: "POST",
    timeout: URL2BRAIN_DEADLINE_MS + 10000,
    headers: {
      "Content-Type": "application/json",
      "Content-Length": Buffer.byteLength(body),
      "X-URL2BRAIN-Token": URL2BRAIN_TOKEN,
    },
  };
  let settled = false;
  const once = (fn) => { if (!settled) { settled = true; clearTimeout(deadline); fn(); } };
  const proxyReq = nodeHttp.request(options, (upRes) => {
    once(() => {
      res.status(upRes.statusCode || 502);
      res.set("Content-Type", upRes.headers["content-type"] || "application/json");
      upRes.pipe(res);
    });
  });
  const deadline = setTimeout(() => {
    once(() => {
      proxyReq.destroy(new Error("url2brain deadline"));
      if (!res.headersSent) {
        res.status(504).json({
          error: "generation exceeded the gateway deadline; no payment was captured. Please retry.",
        });
      }
    });
  }, URL2BRAIN_DEADLINE_MS);
  proxyReq.on("timeout", () => proxyReq.destroy(new Error("url2brain upstream timeout")));
  proxyReq.on("error", (err) => {
    once(() => { if (!res.headersSent) res.status(502).json({ error: `url2brain unavailable: ${err.message}` }); });
  });
  proxyReq.write(body);
  proxyReq.end();
}

for (const [suffix, [upstreamPath]] of Object.entries(URL2BRAIN_ENDPOINTS)) {
  app.post(`/url2brain/${suffix}`, (req, res) => proxyToUrl2brain(upstreamPath, req, res, suffix));
}

// 投稿系: x402決済を実投稿の許可として扱い、confirm_post:trueとペルソナを強制注入する
// (買い手側はpersonaを知らなくていい。どのURLを叩いたかだけで自動的に決まる)。
function proxyToUrl2brainPost(upstreamPath, persona, req, res) {
  const payload = { ...req.body, confirm_post: true };
  if (persona) payload.persona = persona;
  const body = JSON.stringify(payload);
  const url = new URL(`${URL2BRAIN_URL}${upstreamPath}`);
  const options = {
    hostname: url.hostname,
    port: url.port,
    path: url.pathname,
    method: "POST",
    timeout: 60000,
    headers: {
      "Content-Type": "application/json",
      "Content-Length": Buffer.byteLength(body),
      "X-URL2BRAIN-Token": URL2BRAIN_TOKEN,
    },
  };
  let settled = false;
  const once = (fn) => { if (!settled) { settled = true; clearTimeout(deadline); fn(); } };
  const proxyReq = nodeHttp.request(options, (upRes) => {
    once(() => {
      res.status(upRes.statusCode || 502);
      res.set("Content-Type", upRes.headers["content-type"] || "application/json");
      upRes.pipe(res);
    });
  });
  const deadline = setTimeout(() => {
    once(() => {
      proxyReq.destroy(new Error("url2brain post deadline"));
      if (!res.headersSent) {
        res.status(504).json({ error: "post exceeded the gateway deadline; no payment was captured. Please retry." });
      }
    });
  }, 50000);
  proxyReq.on("timeout", () => proxyReq.destroy(new Error("url2brain post upstream timeout")));
  proxyReq.on("error", (err) => {
    once(() => { if (!res.headersSent) res.status(502).json({ error: `url2brain unavailable: ${err.message}` }); });
  });
  proxyReq.write(body);
  proxyReq.end();
}

for (const [suffix, [upstreamPath, persona]] of Object.entries(URL2BRAIN_POST_ENDPOINTS)) {
  app.post(`/url2brain/${suffix}`, (req, res) => proxyToUrl2brainPost(upstreamPath, persona, req, res));
}

app.listen(PORT, "0.0.0.0", () => {
  console.log(`CDP gateway → http://0.0.0.0:${PORT}`);
  console.log(`  OSS2API: ${OSS2API}  LLM: ${LLM_URL}  FXBRAIN: ${FXBRAIN_URL}  KCBRAIN: ${KCBRAIN_URL}  URL2BRAIN: ${URL2BRAIN_URL}`);
  console.log(`  Network: ${NETWORK}  PayTo: ${PAY_TO}`);
});
