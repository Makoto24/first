# 白馬レンタカー WordPress 用HTML

`hakubarentacar.com` の全ページを、統一デザイン・SEO/AIO対応で作り直したものです。
`dist/` の中身をそのまま WordPress の「カスタムHTML」ブロックに貼り付けてください。

## 貼り付け先の対応表

| ファイル | 貼り付け先ページ |
|---|---|
| `dist/ja/01-home.html` | https://hakubarentacar.com/ |
| `dist/ja/02-deals.html` | https://hakubarentacar.com/deals/ |
| `dist/ja/03-access.html` | 店舗案内・アクセス（`/access/` 想定） |
| `dist/ja/04-howtorent.html` | https://hakubarentacar.com/howtorent/ |
| `dist/ja/05-about.html` | 会社情報（`/about/` 想定） |
| `dist/ja/06-terms.html` | https://hakubarentacar.com/terms-and-conditions/ |
| `dist/ja/07-why-choose-us.html` | https://hakubarentacar.com/why-choose-us/ |
| `dist/ja/08-travel-guide.html` | https://hakubarentacar.com/local-travel-guide/ |
| `dist/en/01-home.html` | https://hakubarentacar.com/en/ |
| `dist/en/02-cars-deals.html` | https://hakubarentacar.com/en/cars-deals/ |
| `dist/en/03-access.html` | Locations & Access（`/en/access/` 想定） |
| `dist/en/04-how-to-rent.html` | How to Rent（`/en/how-to-rent/` 想定） |
| `dist/en/05-about.html` | About Us（`/en/about/` 想定） |
| `dist/en/06-terms.html` | https://hakubarentacar.com/en/terms-and-conditions-booking-policies/ |
| `dist/en/07-why-choose-us.html` | https://hakubarentacar.com/en/why-choose-us-2/ |
| `dist/en/08-travel-guide.html` | https://hakubarentacar.com/en/local-travel-guide-2/ |

「想定」と書いたスラッグは実際のURLが不明なため仮置きです。
実際のURLが違う場合は、各ファイル内の該当URLを検索置換してください。

## 貼り付け手順

1. 対象ページを編集画面で開く
2. 既存のブロックをすべて削除する
3. 「カスタムHTML」ブロックを **1つだけ** 追加する
4. 該当ファイルの中身を**全文**コピーして貼り付ける
5. 更新

---

## テーマ・追加CSSとの干渉対策（v2で修正）

初回版はテーマ環境で背景色と文字色が消える不具合が出ました。原因と対策は以下のとおりです。

### 原因

CSSカスタムプロパティ（`var(--hrc-green)` など）が解決されず、
`background: var(--hrc-green)` が無効値になって背景が消えていました。
リテラル値で書いた `rgba(0,0,0,.22)` などだけが残るという症状と一致します。
加えて `.hrc-full { width:100vw; left:50%; margin-left:-50vw }` のフルブリード指定が、
テーマのコンテナと干渉していました。

### 対策

| 項目 | v1 | v2 |
|---|---|---|
| 色の指定 | CSS変数 `var(--hrc-*)` | **リテラル値のみ**（変数を全廃） |
| 優先度 | 素の宣言 | 色・背景・線・文字サイズを**すべて `!important`** |
| セレクタ | `.hrc-btn` | **`.hrc .hrc-btn`**（詳細度を上げてテーマに勝つ） |
| 全幅表示 | `width:100vw` | **使用しない**（テーマのコンテナ幅に収める） |
| フォント | 独自に指定 | **指定しない**（サイトのフォント設定を継承） |
| ヒーロー | 画像を絶対配置＋オーバーレイ | **通常フローの画像＋テキスト**（崩れない） |

### 検証済み

`Chromium` に追加CSSを読み込ませた状態で全16ページをレンダリングし、
以下を自動チェックしています（`build.py` とは別のブラウザ検証）。

- キャンペーン帯・CTA帯・表ヘッダ・ピル・ステップ番号の**背景が透明になっていないこと**
- 表ヘッダの文字色が白、h2の下線がゴールド、要点ボックスの枠が緑であること
- ゴールドボタンの背景が `#fecc01` であること
- **横スクロールが発生していないこと**（1280px / 390px の両方で 0px）
- `var(--hrc-*)` が1つも残っていないこと

---

## 編集したいとき

`dist/` は自動生成物です。直接編集せず、`src/` を編集してから再ビルドしてください。

```
python3 build.py
```

- `src/base.css` — 全ページ共通のデザインシステム
- `src/ja/*.html` `src/en/*.html` — 各ページ本体（`{{BASE_CSS}}` にCSSが差し込まれます）

ビルド時に JSON-LD の構文チェックと未置換プレースホルダーの検出も行います。

## SEO / AIO 対応

- **構造化データ**：`@graph` と `@id` で Organization / AutoRental / 2店舗 / WebPage / FAQPage / HowTo / BreadcrumbList / OfferCatalog / ItemList を相互参照。全ページで同じエンティティを指すため、検索エンジン・AIが1つの事業体として認識します。
- **要点まとめブロック**：各ページ冒頭に事実を密に並べた「要点」ボックス。AI検索が引用しやすい形式です。
- **FAQ**：`<details>` によるJS不要のアコーディオン。JSが動かなくても本文はHTMLに存在します。全ページ合計76問。
- **セマンティックHTML**：`<table>` に `<caption>` と `scope`、見出し階層は h1 → h2 → h3 を厳守。
- **speakable**：音声検索用に要点ブロックを指定。
- **自己完結した回答**：FAQの各回答は「白馬レンタカーは〜」と主語を明示し、単体で切り出しても意味が通ります。

## 反映済みの内容修正

旧サイトにあった以下の不整合を統一しました。

| 項目 | 旧サイト | 本HTML |
|---|---|---|
| 営業時間 | トップ 8:00〜21:00 / 会社情報 8:00〜18:00 | **8:00〜18:00** に統一 |
| 支払い方法 | トップ「カード事前決済のみ」/ 利用案内「現金・カード・QR」 | **現金・カード・QR（貸渡時前払い）** に統一 |
| 郵便番号（構造化データ） | `3949422` / `3994922`（誤記） | **399-9422** |
| 日本語ページの予約リンク | 一部が英語ページ `/en/reservations-2/` を指していた | **`/reservations/`** に修正 |
| 店舗数 | 1店舗のみ掲載 | **白馬駅前店／白馬乗鞍店の2店舗** |
| 「当店の特徴」の店舗記述 | 「店舗は白馬乗鞍スキー場の近く」（1店舗前提） | 2店舗体制の記述に更新 |
