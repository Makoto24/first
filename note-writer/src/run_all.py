"""一連の流れをまとめて実行する: 取得 → 分析 → 生成（→ 任意で下書き投稿）。

cron での定期実行を想定。下書き投稿まで自動でやりたい場合は --post を付ける。
公開は常に手動（このスクリプトは下書き保存までしか行わない）。
"""

from __future__ import annotations

import argparse
import json
import traceback
from datetime import date, datetime

import analyze
import collect_stats
import generate
import post_draft
from common import DATA_DIR, DRAFTS_DIR, ensure_dirs, load_config


def step_collect() -> None:
    rows = collect_stats.collect(debug=False)
    out = DATA_DIR / f"stats_{date.today().isoformat()}.json"
    out.write_text(json.dumps(rows, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"{len(rows)} 行を保存: {out}")


def step_analyze(cfg: dict) -> None:
    name, stats = analyze.latest_stats()
    result = analyze.analyze(stats, cfg)
    out = DATA_DIR / f"analysis_{date.today().isoformat()}.json"
    out.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"分析を保存: {out}\n{result['summary']}")


def step_generate(cfg: dict) -> None:
    topic, reason = generate.pick_topic(None)
    print(f"テーマ: {topic}（{reason}）")
    article = generate.generate(topic, cfg)
    title = article.splitlines()[0].lstrip("# ").strip() if article else topic
    ts = datetime.now().strftime("%Y%m%d-%H%M%S")
    out = DRAFTS_DIR / f"{ts}_{generate.slugify(title)}.md"
    out.write_text(article, encoding="utf-8")
    print(f"下書きを保存: {out}")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--post", action="store_true", help="生成後に note へ下書き投稿する")
    parser.add_argument("--skip-collect", action="store_true", help="ダッシュボード取得をスキップ")
    args = parser.parse_args()

    ensure_dirs()
    cfg = load_config()

    if not args.skip_collect:
        print("=== 1. ダッシュボード取得 ===")
        try:
            step_collect()
        except Exception:  # noqa: BLE001
            print("取得に失敗（分析・生成は続行）:")
            traceback.print_exc()

    print("\n=== 2. 分析 ===")
    try:
        step_analyze(cfg)
    except SystemExit as e:
        print(f"分析をスキップ: {e}")

    print("\n=== 3. 記事生成 ===")
    for i in range(cfg["writing"].get("drafts_per_run", 1)):
        print(f"--- 下書き {i + 1} ---")
        try:
            step_generate(cfg)
        except SystemExit as e:
            print(f"生成をスキップ: {e}")
            break

    if args.post:
        print("\n=== 4. 下書き投稿 ===")
        post_draft.post(post_draft.latest_draft(), debug=False)
        print("公開は note 上で内容確認のうえ手動で行ってください。")


if __name__ == "__main__":
    main()
