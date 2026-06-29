"""閲覧数データを Claude で分析し、よく読まれる傾向と次のテーマ案を出す。

最新の stats_*.json を読み込み、Claude に渡して
「読まれている記事の共通点」「改善点」「次に書くべき記事テーマ案」を
構造化（JSON）で出力させ、data/analysis_*.json に保存する。
"""

from __future__ import annotations

import json
from datetime import date

import anthropic

from common import DATA_DIR, ensure_dirs, load_config, require_env


def latest_stats() -> tuple[str, list[dict]]:
    files = sorted(DATA_DIR.glob("stats_*.json"))
    if not files:
        raise SystemExit("stats が見つかりません。先に `python src/collect_stats.py` を実行してください。")
    path = files[-1]
    return path.name, json.loads(path.read_text(encoding="utf-8"))


SCHEMA = {
    "type": "object",
    "properties": {
        "summary": {"type": "string", "description": "全体傾向の要約"},
        "what_works": {
            "type": "array",
            "items": {"type": "string"},
            "description": "読まれている記事の共通点",
        },
        "improvements": {
            "type": "array",
            "items": {"type": "string"},
            "description": "改善できる点",
        },
        "topic_ideas": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "title": {"type": "string"},
                    "reason": {"type": "string"},
                },
                "required": ["title", "reason"],
                "additionalProperties": False,
            },
            "description": "次に書くべき記事テーマ案",
        },
    },
    "required": ["summary", "what_works", "improvements", "topic_ideas"],
    "additionalProperties": False,
}


def analyze(stats: list[dict], cfg: dict) -> dict:
    client = anthropic.Anthropic(api_key=require_env("ANTHROPIC_API_KEY"))
    blog = cfg["blog"]
    prompt = (
        f"あなたはnoteの編集・グロース担当です。ブログテーマ「{blog['theme']}」、"
        f"読者像「{blog['audience']}」のアクセス分析を行います。\n\n"
        f"以下は note ダッシュボードから取得した記事ごとのアクセスデータ（生の表データ）です。"
        f"列はおおむね タイトル / ビュー / コメント / スキ の順です。\n\n"
        f"{json.dumps(stats, ensure_ascii=False, indent=2)}\n\n"
        f"このデータから、読まれている記事の共通点・改善点・次に書くべきテーマ案を"
        f"上位{cfg['analysis']['top_n']}記事を中心に分析してください。"
    )

    resp = client.messages.create(
        model=cfg["analysis"]["model"],
        max_tokens=16000,
        thinking={"type": "adaptive"},
        output_config={"format": {"type": "json_schema", "schema": SCHEMA}},
        messages=[{"role": "user", "content": prompt}],
    )
    text = next(b.text for b in resp.content if b.type == "text")
    return json.loads(text)


def main() -> None:
    ensure_dirs()
    cfg = load_config()
    name, stats = latest_stats()
    print(f"分析対象: {name}（{len(stats)} 行）")
    result = analyze(stats, cfg)
    out = DATA_DIR / f"analysis_{date.today().isoformat()}.json"
    out.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"分析結果を保存しました: {out}\n")
    print(result["summary"])
    print("\n■ 次に書くべきテーマ案:")
    for idea in result["topic_ideas"]:
        print(f"  - {idea['title']}  ({idea['reason']})")


if __name__ == "__main__":
    main()
