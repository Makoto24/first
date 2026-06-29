"""分析結果（またはテーマ指定）をもとに Claude で記事の下書きを生成する。

生成した記事は drafts/ に Markdown で保存する。
1行目を # タイトル とし、本文が続く形式。
"""

from __future__ import annotations

import argparse
import json
import re
from datetime import datetime

import anthropic

from common import DATA_DIR, DRAFTS_DIR, ensure_dirs, load_config, require_env


def pick_topic(explicit: str | None) -> tuple[str, str]:
    """テーマを決める。明示指定があればそれを、無ければ最新分析の先頭案を使う。"""
    if explicit:
        return explicit, "ユーザー指定"
    files = sorted(DATA_DIR.glob("analysis_*.json"))
    if not files:
        raise SystemExit(
            "テーマが指定されず、分析結果もありません。"
            "--topic でテーマを指定するか、先に analyze を実行してください。"
        )
    analysis = json.loads(files[-1].read_text(encoding="utf-8"))
    ideas = analysis.get("topic_ideas") or []
    if not ideas:
        raise SystemExit("分析結果にテーマ案がありません。--topic で指定してください。")
    return ideas[0]["title"], ideas[0]["reason"]


def generate(topic: str, cfg: dict) -> str:
    client = anthropic.Anthropic(api_key=require_env("ANTHROPIC_API_KEY"))
    blog, writing = cfg["blog"], cfg["writing"]
    prompt = (
        f"あなたは人気noteクリエイターです。以下の方針で記事を1本書いてください。\n\n"
        f"- ブログテーマ: {blog['theme']}\n"
        f"- 読者像: {blog['audience']}\n"
        f"- トーン: {blog['tone']}\n"
        f"- 言語: {blog['language']}\n"
        f"- 目安の文字数: {writing['target_chars']}字程度\n\n"
        f"今回の記事テーマ:「{topic}」\n\n"
        f"要件:\n"
        f"1. 1行目を「# タイトル」とする（クリックしたくなる具体的なタイトル）\n"
        f"2. 導入で読者の悩みに共感し、読むメリットを示す\n"
        f"3. 見出し(##)で構造化し、具体例・手順を入れる\n"
        f"4. 最後にまとめと、読者へのアクション（スキ・フォロー等）を促す一文\n"
        f"5. Markdown で出力。説明や前置きは不要、記事本文のみ。"
    )

    with client.messages.stream(
        model=writing["model"],
        max_tokens=64000,
        thinking={"type": "adaptive"},
        output_config={"effort": "high"},
        messages=[{"role": "user", "content": prompt}],
    ) as stream:
        msg = stream.get_final_message()
    return next(b.text for b in msg.content if b.type == "text")


def slugify(title: str) -> str:
    s = re.sub(r"[#\s]+", "-", title.strip())
    s = re.sub(r"[^\w\-]", "", s)
    return s[:40] or "draft"


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--topic", help="記事テーマを明示指定（省略時は分析結果から自動選択）")
    args = parser.parse_args()

    ensure_dirs()
    cfg = load_config()
    topic, reason = pick_topic(args.topic)
    print(f"テーマ: {topic}（{reason}）\n生成中...")

    article = generate(topic, cfg)
    title_line = article.splitlines()[0].lstrip("# ").strip() if article else topic
    ts = datetime.now().strftime("%Y%m%d-%H%M%S")
    out = DRAFTS_DIR / f"{ts}_{slugify(title_line)}.md"
    out.write_text(article, encoding="utf-8")
    print(f"下書きを保存しました: {out}")


if __name__ == "__main__":
    main()
