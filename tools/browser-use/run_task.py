import argparse
import asyncio
import os
from pathlib import Path

from dotenv import load_dotenv

from browser_use import Agent, Browser, ChatAnthropic, ChatBrowserUse, ChatGoogle, ChatOpenAI


ROOT = Path(__file__).resolve().parent
load_dotenv(ROOT / ".env")


def build_llm(provider: str, model: str | None):
    provider = provider.lower()
    if provider == "browser-use":
        return ChatBrowserUse(model=model or os.getenv("BROWSER_USE_MODEL", "bu-latest"))
    if provider == "anthropic":
        return ChatAnthropic(model=model or os.getenv("ANTHROPIC_MODEL", "claude-sonnet-4-6"))
    if provider == "google":
        return ChatGoogle(model=model or os.getenv("GOOGLE_MODEL", "gemini-3-flash-preview"))
    if provider == "openai":
        return ChatOpenAI(model=model or os.getenv("OPENAI_MODEL", "gpt-4.1"))
    raise ValueError(f"Unsupported provider: {provider}")


async def main() -> int:
    parser = argparse.ArgumentParser(description="Run a browser-use task for URL2AI ops.")
    parser.add_argument("task", nargs="*", help="Task for the browser agent.")
    parser.add_argument("--provider", default=os.getenv("BROWSER_USE_PROVIDER", "openai"))
    parser.add_argument("--model", default=None)
    parser.add_argument("--headed", action="store_true", help="Show the browser window.")
    parser.add_argument("--keep-alive", action="store_true", help="Keep browser open after completion.")
    parser.add_argument("--allowed-domain", action="append", default=[], help="Restrict browser to a domain.")
    parser.add_argument("--max-steps", type=int, default=int(os.getenv("BROWSER_USE_MAX_STEPS", "40")))
    args = parser.parse_args()

    task = " ".join(args.task).strip()
    if not task:
        parser.error("Provide a task string.")

    browser = Browser(
        headless=not args.headed,
        keep_alive=args.keep_alive,
        allowed_domains=args.allowed_domain or None,
        user_data_dir=str(ROOT / ".browser-profile"),
    )
    agent = Agent(
        task=task,
        llm=build_llm(args.provider, args.model),
        browser=browser,
    )
    history = await agent.run(max_steps=args.max_steps)

    print("\n=== browser-use result ===")
    print(f"success: {history.is_successful()}")
    result = history.final_result()
    if result:
        print(result)
    return 0 if history.is_successful() is not False else 1


if __name__ == "__main__":
    raise SystemExit(asyncio.run(main()))
