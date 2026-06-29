# note-writer — note ブログ自動運用 Claude システム

note のダッシュボード（アクセス状況）を自動取得して **閲覧数を分析**し、
**より読まれる記事の下書きを Claude が書き続ける** ローカル実行ツールです。

- 投稿は **下書き保存まで**。公開は必ず人が note 上で内容確認してから手動で行います。
- 分析・執筆は Claude（`claude-opus-4-8`）が担当します。
- note には公式 API が無いため、ダッシュボード取得・下書き投稿は Playwright によるブラウザ自動操作で行います。

## 全体の流れ

```
collect_stats  →  analyze  →  generate  →  (人が確認)  →  公開
 ダッシュボード     Claude分析   Claude執筆     note上で          手動
 から閲覧数取得    傾向/テーマ案  下書き生成    下書き確認
```

## セットアップ

```bash
cd note-writer
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
playwright install chromium

cp .env.example .env   # ANTHROPIC_API_KEY と note のログイン情報を記入
```

`config.yaml` でブログのテーマ・読者像・トーン・文字数などを調整してください。

## 使い方

### 1. note にログイン（初回のみ）

```bash
python src/login.py
```

ブラウザが開くのでログインを完了し、ターミナルで Enter。
認証状態が `auth_state.json` に保存され、以降のスクリプトで再利用されます。

### 2. 個別に実行

```bash
python src/collect_stats.py          # ダッシュボードから閲覧数を取得 → data/stats_*.json
python src/analyze.py                # 閲覧数を分析 → data/analysis_*.json
python src/generate.py               # 分析結果から下書き生成 → drafts/*.md
python src/generate.py --topic "テーマ指定"   # テーマを明示して生成
python src/post_draft.py             # 最新の下書きを note に下書き投稿（公開はしない）
```

### 3. まとめて実行

```bash
python src/run_all.py                # 取得→分析→生成
python src/run_all.py --post         # さらに note へ下書き投稿まで
python src/run_all.py --skip-collect # 取得をスキップ（既存データで分析・生成）
```

## 定期実行（cron 例）

毎週月曜 9:00 に実行し、ログを残す例:

```cron
0 9 * * 1 cd /path/to/note-writer && .venv/bin/python src/run_all.py >> data/cron.log 2>&1
```

`--post` を付けると下書き投稿まで自動化できますが、**公開は必ず手動**で行ってください。

## セレクタが効かないとき

note の画面構造は変わりやすいです。データが取れない／投稿できない場合は：

```bash
python src/collect_stats.py --debug   # 画面ありで実行し data/dashboard_debug.html を保存
python src/post_draft.py --debug      # 画面ありで投稿挙動を確認
```

保存した HTML を見て `collect_stats.py` / `post_draft.py` のセレクタを調整してください。

## 注意事項

- `.env` と `auth_state.json` には認証情報が含まれます。**git にコミットしない**でください（`.gitignore` 済み）。
- ブラウザ自動操作は note の利用規約・負荷に配慮し、過度な頻度では実行しないでください。
- 2要素認証や CAPTCHA が出る場合は `login.py` を画面ありで実行し手動で対応してください。

## ファイル構成

```
note-writer/
├── config.yaml          # ブログ方針・モデル・noteのURL設定
├── requirements.txt
├── .env.example         # → .env にコピーして使う
├── src/
│   ├── common.py        # 設定・パス・環境変数の共通処理
│   ├── login.py         # note ログインと認証状態の保存
│   ├── collect_stats.py # ダッシュボードから閲覧数取得
│   ├── analyze.py       # Claude で分析・テーマ案生成
│   ├── generate.py      # Claude で記事下書き生成
│   ├── post_draft.py    # note へ下書き投稿（公開しない）
│   └── run_all.py       # 一連の流れをまとめて実行
├── data/                # 取得データ・分析結果（gitignore）
└── drafts/              # 生成された下書き Markdown（gitignore）
```
