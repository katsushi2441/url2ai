# URL2AI MCP Server

Remote MCP server for URL2AI tools.

Included tools:

- `convert_pdf_to_markdown`
- `generate_image_from_text`
- `generate_image_from_url`
- `generate_image_from_x_post`

## Run

```bash
cd apps/url2ai-mcp
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
cp .env.sample .env
set -a && source .env && set +a
python server.py
```

The remote MCP endpoint will be available at:

`http://127.0.0.1:8012/mcp`
