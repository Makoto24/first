"""生成した Markdown 記事を note の「下書き」として投稿する（公開はしない）。

note には公式 API が無いため Playwright で新規ノート作成画面を操作する。
公開は行わず、人が note 上で内容を確認してから手動で公開する運用。

note のエディタはリッチテキストで DOM が複雑なため、本文はプレーンテキスト
として流し込む。Markdown の記号はそのまま入るので、note 上で見出し等を
整える前提。確実に動かしたい場合は --debug で挙動を確認すること。
"""

from __future__ import annotations

import argparse
from pathlib import Path

from playwright.sync_api import sync_playwright

from common import AUTH_STATE, DRAFTS_DIR, load_config


def latest_draft() -> Path:
    files = sorted(DRAFTS_DIR.glob("*.md"))
    if not files:
        raise SystemExit("下書きがありません。先に generate を実行してください。")
    return files[-1]


def split_title_body(md: str) -> tuple[str, str]:
    lines = md.splitlines()
    if lines and lines[0].lstrip().startswith("#"):
        return lines[0].lstrip("# ").strip(), "\n".join(lines[1:]).strip()
    return (lines[0] if lines else "無題"), "\n".join(lines[1:]).strip()


def post(draft: Path, debug: bool = False) -> None:
    cfg = load_config()
    if not AUTH_STATE.exists():
        raise SystemExit("先に `python src/login.py` でログインしてください。")

    title, body = split_title_body(draft.read_text(encoding="utf-8"))

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=not debug)
        context = browser.new_context(storage_state=str(AUTH_STATE))
        page = context.new_page()
        page.goto(cfg["note"]["new_note_url"], wait_until="networkidle")
        page.wait_for_timeout(3000)

        # タイトル入力
        page.click('textarea[placeholder*="タイトル"], [placeholder*="タイトル"]')
        page.keyboard.type(title)

        # 本文入力（エディタ本体にフォーカスして流し込む）
        page.click('[contenteditable="true"], .ProseMirror')
        page.keyboard.type(body)
        page.wait_for_timeout(1500)

        # note は入力すると自動で下書き保存される。明示保存ボタンがあれば押す。
        try:
            page.click('button:has-text("下書き保存")', timeout=4000)
        except Exception:  # noqa: BLE001
            pass  # 自動保存に任せる

        page.wait_for_timeout(2000)
        print(
            f"下書きを作成しました: 「{title}」\n"
            "note の下書き一覧で内容を確認し、問題なければ手動で公開してください。"
        )
        if debug:
            print("デバッグモード: ブラウザを開いたままにします。Enterで閉じます...")
            input()
        browser.close()


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--file", help="投稿する下書きファイル（省略時は最新）")
    parser.add_argument("--debug", action="store_true", help="画面ありで実行")
    args = parser.parse_args()

    draft = Path(args.file) if args.file else latest_draft()
    print(f"投稿対象: {draft}")
    post(draft, debug=args.debug)


if __name__ == "__main__":
    main()
