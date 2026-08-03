#!/usr/bin/env python3
"""
白馬レンタカー WordPress 貼り付け用HTMLビルダー

src/base.css を各ページの {{BASE_CSS}} に差し込み、
dist/ に貼り付け可能な単一ファイルを書き出します。

    python3 build.py

チェック内容:
  - {{BASE_CSS}} が置換されたか
  - JSON-LD がすべて構文的に正しいか
  - 未置換のプレースホルダーが残っていないか
"""
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).parent
SRC = ROOT / "src"
DIST = ROOT / "dist"

LDJSON = re.compile(r'<script type="application/ld\+json">(.*?)</script>', re.S)
LEFTOVER = re.compile(r"\{\{[A-Z_]+\}\}|【要入力】|\[TO BE ADDED\]")


def build() -> int:
    base_css = (SRC / "base.css").read_text(encoding="utf-8")
    pages = sorted(p for p in SRC.rglob("*.html"))
    if not pages:
        print("no source pages found", file=sys.stderr)
        return 1

    errors = []
    for page in pages:
        rel = page.relative_to(SRC)
        out = DIST / rel
        out.parent.mkdir(parents=True, exist_ok=True)

        html = page.read_text(encoding="utf-8").replace("{{BASE_CSS}}", base_css)

        for raw in LDJSON.findall(html):
            try:
                json.loads(raw)
            except json.JSONDecodeError as exc:
                errors.append(f"{rel}: invalid JSON-LD ({exc})")

        for stray in set(LEFTOVER.findall(html)):
            errors.append(f"{rel}: unresolved placeholder {stray}")

        out.write_text(html, encoding="utf-8")
        print(f"  built  dist/{rel}  ({len(html):,} bytes)")

    if errors:
        print("\nFAILED:", file=sys.stderr)
        for err in errors:
            print(f"  - {err}", file=sys.stderr)
        return 1

    print(f"\nOK — {len(pages)} pages built into dist/")
    return 0


if __name__ == "__main__":
    sys.exit(build())
