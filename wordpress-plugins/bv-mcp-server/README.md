# BV Rental Manager ― Claude 連携（MCP）

レンタカー予約管理のデータを Claude から参照できるようにする MCP サーバーです。
**読み取り専用**で、Claude からデータを書き換えることはできません。

## できること

| 聞けること | 使われるツール |
|---|---|
| 今月の売上は？ 稼働率は？ | `rental_summary` |
| 店舗別の売上を比べて | `rental_by_store` |
| クラス別の実績は？ | `rental_by_class` |
| 稼働率の低い車両・赤字の車両は？ | `rental_by_vehicle` |
| 去年からの月次推移を見せて | `rental_monthly` |
| 明日の貸出予定は？ 未入金の予約は？ | `rental_reservations` |
| 車検が近い車両は？ | `rental_vehicles` |

## 渡していない情報

お客様の**連絡先・住所・生年月日・免許証画像・決済リンク・管理メモは返しません**。
予約一覧で返すのは、予約番号・日時・店舗・貸出場所・車両・クラス・**姓のみ**・金額・入金状況です。

## 準備

### 1. 中央サイト側で有効にする

1. be-village.com の管理画面 →「レンタカー管理」→「設定」
2. 「スタッフポータル・API」の中の **「データ連携（Claude等）」にチェック** を入れて保存
3. 表示された **接続先URL** と **連携キー** を控える

使わなくなったらチェックを外してください（**既定は無効**です）。
キーが漏れたと思ったら「連携キーを作り直す」で失効させられます。

### 2. Claude 側に登録する

Node.js 18 以降が必要です（`node -v` で確認）。

**Claude Desktop の場合**（設定 → 開発者 → 構成ファイルを編集）:

```json
{
  "mcpServers": {
    "bv-rental": {
      "command": "node",
      "args": ["/フルパス/bv-mcp-server/index.js"],
      "env": {
        "BV_API_URL": "https://be-village.com/wp-json/bvrm/v1/data/",
        "BV_API_KEY": "控えた連携キー"
      }
    }
  }
}
```

**Claude Code の場合**:

```
claude mcp add bv-rental \
  --env BV_API_URL=https://be-village.com/wp-json/bvrm/v1/data/ \
  --env BV_API_KEY=控えた連携キー \
  -- node /フルパス/bv-mcp-server/index.js
```

登録したら Claude を再起動し、「今月の売上は？」と聞いてみてください。

### 3. 動作確認（任意）

```
BV_API_URL=https://be-village.com/wp-json/bvrm/v1/data/ \
BV_API_KEY=控えた連携キー \
node index.js <<< '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

ツールの一覧が返れば接続できています。

## うまくいかないとき

**「連携キーが違うか、データ連携が無効」と出る**
管理画面の「データ連携（Claude等）」のチェックと、キーの貼り間違いを確認してください。

**接続できない（エックスサーバーをお使いの場合）**
サーバーパネルの「国外IPアクセス制限設定」「REST APIアクセス制限」「WAF設定」が
REST API を塞いでいる可能性があります。Square の Webhook と同じ原因です。

## 仕組み

```
Claude ──stdio──→ このMCPサーバー ──HTTPS──→ be-village.com
                （お手元のPCで動く）        /wp-json/bvrm/v1/data/
```

連携キーは**お手元のPCの設定ファイルに置かれるだけ**で、インターネット上に
公開されることはありません。サーバー側に新しい公開窓口を作らずに済むため、
この形にしています。
