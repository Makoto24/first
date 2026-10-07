# レンタカー AIコンシェルジュ（WordPress プラグイン）

Claude API を使った、レンタカーサイト向けの **チャットボット** と **問い合わせフォーム** です。
既存の予約プラグインが保存している予約データ（ガントチャートの元データ）を読み取り、**空車状況をチャットで案内** できます。

## できること

| 機能 | 内容 |
|---|---|
| チャットボット | 全ページ右下にチャットボタンを表示。店舗情報と Q&A 集をもとに Claude が回答します。 |
| 空車案内 | 「来週の土曜から2泊、7人乗りは空いてる？」のような質問に、予約データから空き車両を調べて回答します（予約の作成はしません。予約ページへ案内します）。 |
| 問い合わせフォーム | チャット内のタブ、またはショートコードでページに設置。ボットが答えられないときは会話内容を下書きにしたフォームを表示します。受付内容は管理画面に保存し、メールで通知・自動返信します。 |
| Q&A 集 | 管理画面で1件ずつ登録、または CSV（Excel で保存したもの）で一括取り込み・書き出し。 |
| 会話ログ | お客様の質問と回答を確認できます。答えられなかった質問を Q&A に追加すると、次から答えられるようになります。API の推定コストも表示します。 |

画面はすべて Shadow DOM の中に描画するため、Lightning などのテーマの CSS で崩れません。

## 動作環境

- WordPress 6.2 以上
- PHP 8.1 以上（エックスサーバーなどでは「PHP Ver.切替」で 8.1 以上を選択）
- Claude API キー（[Claude Console](https://console.anthropic.com/) で発行）

## インストール

1. GitHub の **Actions** → 「Build WordPress plugin (rentacar-ai-concierge)」→ 最新の実行結果を開き、**Artifacts** の `rentacar-ai-concierge` をダウンロードします（`rentacar-ai-concierge.zip` が保存されます）。
2. WordPress 管理画面 →「プラグイン」→「新規追加」→「プラグインのアップロード」で zip を選び、インストールして有効化します。
3. 管理メニューに「AIコンシェルジュ」が追加されます。

> 自分で zip を作る場合は、リポジトリのルートで `bash rentacar-ai-concierge/bin/build-zip.sh` を実行すると `dist/rentacar-ai-concierge.zip` ができます（PHP 8.1 以上と Composer が必要）。

## 初期設定（おすすめの順番）

1. **API キー**
   `wp-config.php` の `/* That's all, stop editing! */` より上に次の1行を書くのが安全です（データベースにキーが残りません）。
   ```php
   define( 'RCAC_ANTHROPIC_API_KEY', 'sk-ant-...' );
   ```
   難しい場合は「設定 → 基本設定 → Claude API キー」に入力しても動きます。
2. **基本設定**：会社名、ボットの名前、予約ページの URL、テーマカラーなど。
3. **店舗情報・Q&A**：営業時間・住所・料金の概要・キャンセル規定などを書きます。Q&A は「Q&A CSV取り込み」から一括登録できます（`sample/faq-sample.csv` が雛形です）。
4. **空車連携**：下の「既存の予約プラグインとの連携」を参照。最初は「デモデータ」で動作を確認できます。
5. **問い合わせフォーム**：通知先メール、プライバシーポリシーの URL、自動返信の文面。
6. **動作テスト**：保存した設定で実際に質問を送り、回答・所要時間・推定コストを確認できます。

## サイトへの表示

- **右下のチャットボタン**：有効にすると全ページに表示されます。出したくないページは「チャットボタンを出さないページID」に指定します。
- **ショートコード**（固定ページの「ショートコード」ブロックに書きます）
  - `[rcac_chatbot]` … ページ内にチャット画面を埋め込み
  - `[rcac_contact_form]` … ページ内に問い合わせフォームを埋め込み

## 既存の予約プラグインとの連携（空車案内）

予約プラグインが保存している **車両の一覧** と **予約（車両・貸出日時・返却日時・ステータス）** を読み取り、指定期間に予約が重なっていない車両を「空車」と判定します。返却から次の貸出までの準備時間（初期値 60 分）も考慮します。お客様の氏名などの個人情報は読み取りません。

「設定 → 空車連携」で、予約プラグインのデータの保存方法に合わせて選びます。

### A. 独自テーブルに保存しているプラグイン（テーブル型）

1. 画面下の「予約プラグインのデータ構造を調べる」でテーブルを選び、列名を確認します。
2. 車両テーブル名・車両 ID の列・車両名の列（クラス・定員・店舗は任意）を入力します。車両テーブルに「稼働中のみ」などの条件がある場合は `status=active` のように指定します（複数は `&` でつなぐ）。
3. 予約テーブル名・車両 ID の列・貸出日時の列・返却日時の列・ステータスの列（任意）を入力します。
4. 日時は DATETIME / DATE 型、UNIX タイムスタンプ、`2026/10/12 10:00` のような文字列のいずれでも読み取れます。日付だけの返却日は「その日いっぱい使用中」とみなします。

### B. 投稿タイプ＋カスタムフィールドに保存しているプラグイン（投稿型）

1. 「カスタム投稿タイプ」で予約・車両の投稿タイプを選び、カスタムフィールドのキーを確認します。
2. 車両の投稿タイプ（タイトルが車両名になります）、クラス（カスタムフィールド、またはタクソノミーなら `tax:タクソノミー名`）を入力します。
3. 予約の投稿タイプ、車両・貸出日時・返却日時のカスタムフィールドを入力します。車両のフィールドには車両の投稿 ID か車両名が入っている必要があります。

### 設定の確認

保存すると「接続テスト（簡易ガントチャート）」に今日から14日間の予約状況が表示されます。**既存の予約プラグインのガントチャートと同じ場所が埋まっていれば連携完了** です。キャンセル済みの予約が埋まって見える場合は「空車扱いにする予約ステータス」にそのステータス値を追加してください。

### C. どちらにも当てはまらない場合（独自アダプター）

テーマの `functions.php` や独自プラグインで、`RCAC\Availability\ProviderInterface` を実装したクラスを `rcac_availability_provider` フィルターで返します（取得方法は「カスタム」を選択）。

```php
add_filter( 'rcac_availability_provider', function ( $provider, $type ) {
	if ( 'custom' !== $type ) {
		return $provider;
	}
	return new class() implements \RCAC\Availability\ProviderInterface {
		public function vehicles(): array {
			// 例: [ [ 'id' => '1', 'name' => 'ヤリス', 'class' => 'コンパクト', 'capacity' => 5, 'store' => '本店' ], ... ]
			return my_booking_plugin_get_cars();
		}
		public function bookings( \DateTimeImmutable $from, \DateTimeImmutable $to ): array {
			// 例: [ [ 'vehicle' => '1', 'start' => DateTimeImmutable, 'end' => DateTimeImmutable ], ... ]（キャンセル済みは除く）
			return my_booking_plugin_get_reservations( $from, $to );
		}
	};
}, 10, 2 );
```

## 料金の目安と利用制限

- API の料金は Anthropic から利用量に応じて請求されます。管理画面の上部と「会話ログ」に推定コスト（米ドル）を表示します。
- 店舗情報と Q&A はプロンプトキャッシュの対象にしているため、続けて質問が来るときは入力料金が大幅に下がります。
- 料金を抑えたい場合は「モデル」を Claude Sonnet 5.5 や Claude Haiku 4.5 に変更できます（回答の質は動作テストで確認してください）。
- 「利用制限・ログ」タブで、1人あたりの送信回数、サイト全体の1日の上限、1回の文字数を設定できます。上限に達するとチャットを止め、フォームへ案内します。

## セキュリティとプライバシー

- API キーはサーバー側だけで使い、ブラウザには送りません。
- 会話の履歴はサーバーに保存したものだけを使います（ブラウザから送られた過去の発言は使わないため、改ざんして指示を書き換えることはできません）。
- IP アドレスは復元できない形（ハッシュ）でのみ記録します。会話ログは設定した日数を過ぎると毎日自動で削除されます。
- ボットには、個人情報をチャットで聞かない・予約データの中身（他のお客様の予約）に触れない・レンタカーと関係のない依頼に応じない、よう指示しています。
- 問い合わせフォームは、ハニーポット・送信速度チェック・回数制限でスパムを防ぎます。

## 開発者向け

| フック | 種類 | 用途 |
|---|---|---|
| `rcac_availability_provider` | filter | 空車データの取得方法を差し替える |
| `rcac_system_prompt` | filter | システムプロンプト（キャッシュされる部分）を調整する |
| `rcac_show_floating_widget` | filter | 右下のチャットボタンを表示するか |
| `rcac_client_ip` | filter | リバースプロキシ配下で実 IP を使う |
| `rcac_http_timeout` | filter | Claude API の通信タイムアウト（秒、初期値 120） |
| `rcac_inquiry_submitted` | action | 問い合わせ受付後の処理（外部システム連携など） |

- Claude API は公式 PHP SDK（`anthropic-ai/sdk`）で呼び出しています。通信は WordPress の HTTP API を使う PSR-18 クライアント（`includes/Claude/WpHttpClient.php`）経由で行うため、Guzzle などを同梱せず、他プラグインとのライブラリ衝突を避けています。
- Opus 5.5 / Sonnet 5.5 では、安全分類器が誤って回答を止めた場合に別モデルで回答するサーバー側フォールバック（`fallbacks: "default"`）を有効にしています。
- 空車判定の動作確認: `wp eval-file wp-content/plugins/rentacar-ai-concierge/tests/availability-test.php`（テスト用のテーブル・投稿を作って削除します。本番サイトでは実行しないでください）
- テスト用に API の接続先を変える場合は `define( 'RCAC_ANTHROPIC_BASE_URL', 'http://127.0.0.1:8899' );`

```
rentacar-ai-concierge/
├── rentacar-ai-concierge.php   プラグイン本体（PHP バージョン確認・読み込み）
├── includes/
│   ├── ChatEngine.php          Claude 呼び出し・ツール（空車確認・車両一覧・フォーム表示）
│   ├── Rest.php                REST API（/wp-json/rcac/v1/chat, /contact）
│   ├── Settings.php            設定項目の定義
│   ├── KnowledgeBase.php       Q&A 集（投稿タイプ rcac_faq）
│   ├── Inquiries.php           問い合わせ（投稿タイプ rcac_inquiry）・メール
│   ├── ConversationStore.php   会話ログ
│   ├── Guard.php               会話トークン・回数制限
│   ├── Availability/           空車判定とアダプター（デモ・テーブル型・投稿型）
│   ├── Claude/                 SDK クライアント生成・WordPress HTTP API アダプター
│   └── Admin/                  管理画面
├── assets/                     チャット画面（Shadow DOM）・管理画面の JS/CSS
├── sample/faq-sample.csv       Q&A の CSV 雛形
└── bin/build-zip.sh            配布用 zip の作成
```
