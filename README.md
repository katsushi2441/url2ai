# URL2AI

Turn any URL into AI-generated content including stories, debates, lyrics, and insights. URL2AI is a multi-format content engine that analyzes links and transforms them into structured, creative outputs — built as a scalable ecosystem for AI-powered media generation on [aiknowledgecms.exbridge.jp](https://aiknowledgecms.exbridge.jp).

## Live Links

- Overview Page: [url2ai.html](https://aiknowledgecms.exbridge.jp/url2ai.html)
- Ecosystem Portal: [knowradar.php](https://aiknowledgecms.exbridge.jp/knowradar.php)

## Overview

URL2AI is an AI engine that transforms X (Twitter) post URLs and web URLs into multiple content formats:

| Module | Format | Description |
|--------|--------|-------------|
| 📖 UStory | Short fiction | Generates a short story from an X post |
| 🧩 UParse | Syntax pattern examples | Analyzes sentence structure and generates example sentences using the same pattern |
| ⚔️ UDebate | AI debate | Two AIs argue for/against; a judge AI summarizes |
| 📸 UMedia | Media insight | Downloads images/videos and generates AI analysis |
| 🎵 USong | Lyrics | Generates vocaloid-style lyrics from an X post |
| 💬 XInsight | AI commentary | Deep AI analysis of an X post thread |
| 🔗 AITech Links | Tech summaries | Fetches a tech URL and generates an AI summary + tags |
| 🦉 OSS Timeline | OSS insights | GitHub OSS discovery with AI analysis |
| 📚 OSSZenn | OSS × Zenn | Matches GitHub OSS with related Zenn articles |
| 📰 AI News Radar | News analysis | Analyzes X news posts together with linked articles |
| 🌐 KnowRadar | Portal | Unified portal showing all modules with RSS feeds |

## Architecture

```
X Post URL / Web URL
        ↓
  [fetch & parse]
  fxtwitter API / file_get_contents
        ↓
  [AI generation]
  Ollama (gemma4:e4b / gemma3:12b)
  https://exbridge.ddns.net/api/generate
        ↓
  [storage]
  data/xinsight_{tweet_id}.json
        ↓
  [display]
  *v.php viewer files (ustoryv, uparsev, udebatev, umediav, usongv, xinsightv)
```

## Stack

- **Backend**: PHP (no framework)
- **AI**: Ollama self-hosted (gemma4:e4b, gemma3:12b)
- **Auth**: X (Twitter) OAuth2 PKCE
- **Storage**: JSON files per tweet ID (`data/xinsight_*.json`)
- **Config**: `config.yaml` + `config.php`

## File Structure

```
src/
├── config.yaml          # Ollama URL, model, site config (excluded from git)
├── config.php           # Loads config.yaml, defines constants
├── x_api_keys.sh        # X API credentials (excluded from git)
├── ustory.php           # UStory generator
├── ustoryv.php          # UStory viewer + RSS
├── uparse.php           # UParse generator
├── uparsev.php          # UParse viewer + RSS
├── udebate.php          # UDebate generator (SSE streaming)
├── udebatev.php         # UDebate viewer + RSS
├── umedia.php           # UMedia downloader + insight generator
├── umediav.php          # UMedia viewer + RSS
├── usong.php            # USong lyrics generator
├── usongv.php           # USong viewer + RSS
├── xinsight.php         # XInsight generator
├── xinsightv.php        # XInsight viewer + RSS
├── aitech.php           # AITech Links viewer
├── saveaitech.php       # AITech Links registration backend
├── ainews.php           # AI News Radar viewer
├── saveainews.php       # AI News Radar registration backend
├── oss.php              # OSS Timeline viewer
├── saveoss.php          # OSS registration + Ollama analysis backend
├── osszenn.php          # OSSZenn viewer
├── zenn2oss.php         # Zenn × OSS matching backend
├── knowradar.php        # Portal (aggregates all modules + RSS)
├── url2ai.html          # Overview / landing page
└── data/
    ├── xinsight_*.json  # Per-post AI content storage
    └── aitech_posts.json
```

## Data Model

All AI-generated content is stored in a single JSON file per tweet:

```json
{
  "tweet_id": "...",
  "tweet_url": "https://x.com/...",
  "username": "...",
  "thread_text": "...",
  "insight": "...",
  "story": "...",
  "lyrics": "...",
  "debate_turns": [...],
  "debate_conclusion": "...",
  "debate_at": "...",
  "media": [...],
  "media_insight": "...",
  "saved_at": "..."
}
```

## Configuration

Edit `config.yaml` to change Ollama endpoint, model, or site settings:

```yaml
ollama:
  api_url: https://your-ollama-host/api/generate
  default_model: gemma4:e4b

site:
  base_url: https://aiknowledgecms.exbridge.jp
  admin: xb_bittensor
```

## Example

Input:
```
https://x.com/user/status/123456789
```

Output (stored in `data/xinsight_123456789.json`):
- 📖 Short story
- 🧩 Syntax pattern analysis + example sentences
- ⚔️ AI debate (3 rounds + judge summary)
- 📸 Media insight (with downloaded video/image)
- 🎵 Vocaloid-style lyrics
- 💬 Thread analysis

## Vision

Build an AI-driven content ecosystem where any information source — X posts, web articles, GitHub repos — becomes creative and analytical output, fully automated and scalable.
