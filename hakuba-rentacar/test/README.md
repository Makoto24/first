# ブラウザ検証

テーマの追加CSSを読み込ませた状態で全ページをレンダリングし、
背景色・文字色が消えていないか、横スクロールが出ていないかを確認します。

```
npm i playwright
node test/check.mjs dist/ja/*.html dist/en/*.html
```

- `theme.css` — サイトの「追加CSS」のうち、干渉しうる部分を再現したもの
- `check.mjs` — 各ページの計算済みスタイルを検査。問題があれば終了コード1
