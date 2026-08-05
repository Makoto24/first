# 白馬レンタカー WordPress 用HTML

`hakubarentacar.com` の全16ページ（日本語8・英語8）です。
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
違う場合は各ファイル内の該当URLを検索置換してください。

## 貼り付け手順

1. 対象ページを編集画面で開く
2. 既存のブロックをすべて削除する
3. 「カスタムHTML」ブロックを **1つだけ** 追加する
4. 該当ファイルの中身を**全文**コピーして貼り付ける
5. 更新

---

## デザイン（v4）

- **ヒーロー**：写真の上に暗いスクリムを重ね、白抜きで見出し・リード・CTAを置く構成。
  高さは中身の余白で決まるため、文章量が変わっても崩れません。
- **実績ストリップ**：ヒーロー直下に「2店舗 / 全車4WD / 8:00–18:00 / 日英対応 / 40%OFF」を5分割で配置。
- **コンテナ幅を1120pxに統一**。長文ページだけ812pxに落として行長を整えています。
- **「サービス概要」**（旧・要点まとめ）を2カラムの仕様表レイアウトに変更。
  緑の太枠をやめ、上辺のみのアクセントに。
- **タイプスケール**を clamp() で連続可変にし、見出し・本文・補足の階層を明確化。
- **装飾絵文字を削除**（📌🕒📍📞など）。ブランドに関わる 🌿 のみ残しています。
- 角丸を 4px に統一し、影を弱めて情報密度を上げています。

## テーマ干渉対策（v3・完全インライン化）

v1・v2はテーマ環境で配色や余白が壊れました。v3では原因の特定に頼らず、
**干渉が起こりようのない構造**に変更しています。

### 方針

**すべてのスタイルを各要素の `style` 属性に `!important` 付きで埋め込む。**

インライン `style` の `!important` はCSSカスケードの最上位にあります。

- テーマCSS・追加CSS・プラグインCSSが `!important` を付けていても上書きされません
- CSSの読み込み順（テーマが後に来る／プラグインが結合ファイルを前に置く）に影響されません
- CSS最適化プラグインが `<style>` を結合・移動・削除しても、スタイルはHTMLの一部なので消えません

### メディアクエリを使わずレスポンシブにしている

インライン `style` にはメディアクエリを書けないため、以下だけで実現しています。

- `clamp()` による可変フォントサイズ・可変余白
- `grid-template-columns: repeat(auto-fit, minmax(260px, 1fr))` による自動段組
- `flex-wrap: wrap`
- 表は `overflow-x:auto` のコンテナで横スクロール

ブレークポイントが1つも無いため、**CSSが1行も効かない環境でもレイアウトは崩れません。**

### 残っている `<style>`

各ファイルの先頭に小さな `<style>` があります。中身は以下だけです。

- ボタン・リンクカードの `:hover`
- FAQ（`<details>`）を開いたときの `＋` → `−` の切り替え
- `summary` の三角マーカー消し

**このブロックが丸ごと消えても、レイアウトと配色は一切変わりません。**

### 検証済み

`test/check.mjs` で、以下の**最悪条件**を再現して全16ページを検査しています。

1. テーマCSSを**本文より後に**読み込む（`!important` 同士なら後勝ちになる条件）
2. そのテーマCSSは、見出し・リンク・表・div・リストの背景/余白/線/表示形式を
   **すべて `!important` で潰しにかかる**（Lightning風の見出し上線も含む）
3. 各ページの `<style>` を**丸ごと削除**した状態で描画する

この条件下で以下を確認しています。

- キャンペーン帯・CTA帯・告知バー・アラート・要点・表ヘッダ・カード・ステップ番号・店舗ヘッダ・ピル・関連リンク・ボタンの**背景がすべて残っていること**
- 表ヘッダとキャンペーンの文字色が白、h2下線がゴールド、要点の枠が緑、表の罫線が残ること
- ボタンの余白・角丸・`display` が保たれていること
- テーマ由来の**見出し上線が入り込んでいないこと**
- グリッドが多段組のままであること
- **横スクロールが発生しないこと**（1280px / 390px）

```
npm i playwright
node test/check.mjs dist/ja/*.html dist/en/*.html
```

### トレードオフ

1ページ 40〜120KB とファイルサイズは大きくなります。
表示速度への影響は軽微（HTMLのgzip圧縮が効くため）ですが、
ブロックエディタへの貼り付け時にわずかに待つことがあります。

---

## 編集したいとき

`dist/` は自動生成物です。直接編集せず、`src/` を編集してから再ビルドしてください。

```
pip install lxml
python3 build.py
```

- `src/styles.py` — 色・サイズ・余白の定義（クラス名 → CSS宣言）
- `src/ja/*.html` `src/en/*.html` — 素のセマンティックHTML（`style` 属性は書かない）
- `build.py` — 上記2つを合成して `dist/` を生成

ビルド時に JSON-LD の構文、未置換プレースホルダー、
スタイル未定義の `hrc-*` クラスを検出します。

## SEO / AIO 対応

- **構造化データ**：`@graph` と `@id` で Organization / AutoRental / 2店舗 / WebPage / FAQPage / HowTo / BreadcrumbList / OfferCatalog / ItemList を相互参照。全ページで同じエンティティを指すため、検索エンジン・AIが1つの事業体として認識します。
- **要点まとめブロック**：各ページ冒頭に事実を密に並べたボックス。AI検索が引用しやすい形式です。
- **FAQ**：`<details>` によるJS不要のアコーディオン。全ページ合計76問。
- **セマンティックHTML**：`<table>` に `<caption>` と `scope`、見出し階層は h1 → h2 → h3。
- **speakable**：音声検索用に要点ブロックを指定。
- **自己完結した回答**：FAQの各回答は「白馬レンタカーは〜」と主語を明示。

## 反映済みの内容修正

| 項目 | 旧サイト | 本HTML |
|---|---|---|
| 営業時間 | トップ 8:00〜21:00 / 会社情報 8:00〜18:00 | **8:00〜18:00** に統一 |
| 支払い方法 | トップ「カード事前決済のみ」/ 利用案内「現金・カード・QR」 | **現金・カード・QR（貸渡時前払い）** に統一 |
| 郵便番号（構造化データ） | `3949422` / `3994922`（誤記） | **399-9422** |
| 日本語ページの予約リンク | 一部が英語ページ `/en/reservations-2/` を指していた | **`/reservations/`** に修正 |
| 店舗数 | 1店舗のみ掲載 | **白馬駅前店／白馬乗鞍店の2店舗** |
| 「当店の特徴」の店舗記述 | 「店舗は白馬乗鞍スキー場の近く」（1店舗前提） | 2店舗体制の記述に更新 |
