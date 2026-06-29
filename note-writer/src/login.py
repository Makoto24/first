"""note にログインしてブラウザの認証状態を保存する。

note には公式 API が無いため、Playwright でブラウザを自動操作する。
2要素認証やCAPTCHAが出る場合があるので、初回は headful（画面あり）で
手動補助しながらログインし、その状態を auth_state.json に保存して
以降のスクリプトで再利用する。
"""

from __future__ import annotations

from playwright.sync_api import sync_playwright

from common import AUTH_STATE, load_config, require_env


def main() -> None:
    cfg = load_config()
    email = require_env("NOTE_EMAIL")
    password = require_env("NOTE_PASSWORD")

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=False)
        context = browser.new_context()
        page = context.new_page()
        page.goto(cfg["note"]["login_url"])

        # ログインフォーム入力（note の DOM 変更時はセレクタ調整が必要）
        try:
            page.fill('input[type="email"], input[name="email"]', email)
            page.fill('input[type="password"], input[name="password"]', password)
            page.click('button[type="submit"], button:has-text("ログイン")')
        except Exception as e:  # noqa: BLE001
            print(f"自動入力に失敗しました（手動でログインしてください）: {e}")

        print(
            "\nブラウザでログインを完了してください。"
            "\n2要素認証やCAPTCHAがあれば手動で対応してください。"
            "\n完了したらこのターミナルで Enter を押してください..."
        )
        input()

        context.storage_state(path=str(AUTH_STATE))
        print(f"認証状態を保存しました: {AUTH_STATE}")
        browser.close()


if __name__ == "__main__":
    main()
