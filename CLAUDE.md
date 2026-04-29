# CLAUDE.md — URL2AI Project Rules

## PHP Version Constraint

**The production server (aiknowledgecms.exbridge.jp) runs PHP 5.x.**

When editing any PHP file under `src/`, do NOT use PHP 7+ syntax:

| Forbidden | PHP 5.x alternative |
|---|---|
| `$a ?? 'default'` | `(isset($a) ? $a : 'default')` |
| `$a ?? $b ?? ''` | `(isset($a) ? $a : (isset($b) ? $b : ''))` |
| `function f(?string $x)` | `function f($x = null)` |
| `int $x` typed properties | Untyped only |
| `declare(strict_types=1)` | Omit |
| `match()` expression | `switch` / `if-elseif` |
| Named arguments `f(key: val)` | Positional only |
| Arrow functions `fn($x) => $x` | `function($x) { return $x; }` |

This applies to all files in `src/*.php`. Other directories (`apps/`, `x402/`) run Node.js and are not affected.

## Node.js / TypeScript (apps/, x402/)

- Node.js 22 (ESM, `"type": "module"`)
- TypeScript for Bankr x402 handlers (`x402/*/index.ts`)

## FTP Deploy

Upload PHP changes with:
```bash
cd src && python3 ftpphp.py
```

## Architecture Notes

- `src/` — PHP 5.x pages served on shared hosting via FTP
- `apps/llm-gateway/` — Node.js 22, LLM2API gateway (ports 8019/8020)
- `apps/oss2api/` — Node.js 22, OSS2API gateway (ports 8015/8017)
- `apps/ernie-image-turbo/` — Python, image generation server
- `x402/` — Bankr x402 TypeScript handlers, deployed via `bankr x402 deploy`
