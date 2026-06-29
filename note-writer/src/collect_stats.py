"""note のダッシュボード（アクセス状況）から記事ごとの閲覧数等を取得する。

保存した認証状態を使ってダッシュボードを開き、各記事の
タイトル・ビュー数・スキ数・コメント数を JSON に保存する。

注意: note のダッシュボードはJavaScriptで描画され、DOM構造が変わりやすい。
セレクタが効かない場合は --debug でHTMLを保存し、手動でセレクタを調整すること。
"""

from __future__ import annotations

import argparse
import json
from datetime import date

from playwright.sync_api import sync_playwright

from common import AUTH_STATE, DATA_DIR, ensure_dirs, load_config


def collect(debug: bool = False) -> list[dict]:
    cfg = load_config()
    if not AUTH_STATE.exists():
        raise SystemExit("先に `python src/login.py` でログインしてください。")

    rows: list[dict] = []
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=not debug)
        context = browser.new_context(storage_state=str(AUTH_STATE))
        page = context.new_page()
        page.goto(cfg["note"]["dashboard_url"], wait_until="networkidle")
        page.wait_for_timeout(3000)

        if debug:
            html_path = DATA_DIR / "dashboard_debug.html"
            html_path.write_text(page.content(), encoding="utf-8")
            print(f"デバッグHTMLを保存: {html_path}")

        # ダッシュボードの各記事行を取得。note の構造変更に備え複数候補を試す。
        # 各行: タイトル / ビュー / コメント / スキ
        rows_loc = page.locator('[class*="o-statsTable"] tbody tr, table tbody tr')
        count = rows_loc.count()
        for i in range(count):
            row = rows_loc.nth(i)
            cells = row.locator("td, th")
            texts = [cells.nth(j).inner_text().strip() for j in range(cells.count())]
            if not texts:
                continue
            rows.append({"raw_cells": texts})

        browser.close()

    return rows


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--debug", action="store_true", help="画面ありで実行しHTMLを保存")
    args = parser.parse_args()

    ensure_dirs()
    rows = collect(debug=args.debug)
    out = DATA_DIR / f"stats_{date.today().isoformat()}.json"
    out.write_text(json.dumps(rows, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"{len(rows)} 行のデータを保存しました: {out}")
    if not rows:
        print(
            "データが取得できませんでした。--debug で実行して "
            "data/dashboard_debug.html を確認し、セレクタを調整してください。"
        )


if __name__ == "__main__":
    main()
