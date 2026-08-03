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
| `dist/en/01-home.html` | https://hakubarentacar.com/en/ |
| `dist/en/02-cars-deals.html` | https://hakubarentacar.com/en/cars-deals/ |
| `dist/en/03-access.html` | Locations & Access（`/en/access/` 想定） |
| `dist/en/04-how-to-rent.html` | How to Rent（`/en/how-to-rent/` 想定） |
| `dist/en/05-about.html` | About Us（`/en/about/` 想定） |
| `dist/en/06-terms.html` | https://hakubarentacar.com/en/terms-and-conditions-booking-policies/ |

「想定」と書いたスラッグは実際のURLが不明なため仮置きです。
実際のURLが違う場合は、各ファイル内の該当URLを検索置換してください。

## 貼り付け手順

1. 対象ページを編集画面で開く
2. 既存のブロックをすべて削除する
3. 「カスタムHTML」ブロックを1つ追加する
4. 該当ファイルの中身を**全文**コピーして貼り付ける
5. 更新

CSSは各ファイルの `<style>` に同梱され、すべて `.hrc` 配下にスコープされています。
テーマ側のCSSと干渉しないため、他ページへの影響はありません。

## 編集したいとき

`dist/` は自動生成物です。直接編集せず、`src/` を編集してから再ビルドしてください。

```
python3 build.py
```

- `src/base.css` — 全ページ共通のデザインシステム（色・タイポグラフィ・コンポーネント）
- `src/ja/*.html` `src/en/*.html` — 各ページ本体（`{{BASE_CSS}}` にCSSが差し込まれます）

ビルド時に JSON-LD の構文チェックと未置換プレースホルダーの検出も行います。

## SEO / AIO 対応

- **構造化データ**：`@graph` と `@id` で Organization / AutoRental / 2店舗 / WebPage / FAQPage / HowTo / BreadcrumbList / OfferCatalog を相互参照。全ページで同じエンティティを指すため、検索エンジン・AIが1つの事業体として認識します。
- **要点まとめブロック**：各ページ冒頭に事実を密に並べた「要点」ボックス。AI検索が引用しやすい形式です。
- **FAQ**：`<details>` による JS不要のアコーディオン。JSが動かなくても本文はHTMLに存在します。
- **セマンティックHTML**：`<table>` に `<caption>` と `scope`、見出し階層は h1 → h2 → h3 を厳守。
- **speakable**：音声検索用に要点ブロックを指定。
- **自己完結した回答**：FAQの各回答は「白馬レンタカーは〜」と主語を明示し、単体で切り出しても意味が通ります。

## 反映済みの修正

旧サイトにあった以下の不整合を統一しました。

| 項目 | 旧サイト | 本HTML |
|---|---|---|
| 営業時間 | トップ 8:00〜21:00 / 会社情報 8:00〜18:00 | **8:00〜18:00** に統一 |
| 支払い方法 | トップ「カード事前決済のみ」/ 利用案内「現金・カード・QR」 | **現金・カード・QR（貸渡時前払い）** に統一 |
| 郵便番号（構造化データ） | `3949422` / `3994922`（誤記） | **399-9422** |
| 日本語ページの予約リンク | 一部が英語ページ `/en/reservations-2/` を指していた | **`/reservations/`** に修正 |
| 店舗数 | 1店舗のみ掲載 | **白馬駅前店／白馬乗鞍店の2店舗** |
