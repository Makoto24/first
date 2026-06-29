"""共通ユーティリティ: 設定読み込み・パス・ブラウザ認証の補助。"""

from __future__ import annotations

import os
from pathlib import Path

import yaml
from dotenv import load_dotenv

ROOT = Path(__file__).resolve().parent.parent
DATA_DIR = ROOT / "data"
DRAFTS_DIR = ROOT / "drafts"
AUTH_STATE = ROOT / "auth_state.json"
CONFIG_PATH = ROOT / "config.yaml"

load_dotenv(ROOT / ".env")


def load_config() -> dict:
    with open(CONFIG_PATH, encoding="utf-8") as f:
        return yaml.safe_load(f)


def require_env(name: str) -> str:
    val = os.environ.get(name)
    if not val:
        raise SystemExit(
            f"環境変数 {name} が未設定です。.env.example を .env にコピーして設定してください。"
        )
    return val


def ensure_dirs() -> None:
    DATA_DIR.mkdir(exist_ok=True)
    DRAFTS_DIR.mkdir(exist_ok=True)
