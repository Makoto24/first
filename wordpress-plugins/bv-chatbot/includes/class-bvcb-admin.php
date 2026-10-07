<?php
/**
 * 管理画面：設定・Q&A集・動作テスト・会話ログ
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BVCB_Admin {

	const SLUG = 'bvcb';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle' ) );
	}

	public static function menu() {
		add_menu_page( 'チャットボット', 'チャットボット', 'manage_options', self::SLUG, array( __CLASS__, 'page' ), 'dashicons-format-chat', 58 );
	}

	protected static function url( $tab = 'settings', $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	protected static function notice( $msg, $ok = true ) {
		set_transient( 'bvcb_notice_' . get_current_user_id(), array( $msg, $ok ), 120 );
	}

	/* ---------- 保存処理 ---------- */

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) || empty( $_POST['bvcb_action'] ) ) return;
		$action = sanitize_key( $_POST['bvcb_action'] );
		check_admin_referer( 'bvcb_' . $action );
		$P = wp_unslash( $_POST );

		if ( 'settings' === $action ) {
			$o = BVCB_Settings::get();
			$new = $o;
			$new['enabled'] = ! empty( $P['enabled'] ) ? 1 : 0;
			/* APIキーは画面に出さない。空欄なら前の値のまま、「削除」にチェックで消す */
			$key = trim( (string) ( $P['claude_api_key'] ?? '' ) );
			if ( '' !== $key ) $new['claude_api_key'] = $key;
			if ( ! empty( $P['clear_api_key'] ) ) $new['claude_api_key'] = '';
			$new['model']  = isset( BVCB_Settings::models()[ $P['model'] ?? '' ] ) ? $P['model'] : 'claude-opus-5-5';
			$new['effort'] = isset( BVCB_Settings::efforts()[ $P['effort'] ?? '' ] ) ? $P['effort'] : 'low';
			$new['api_url'] = esc_url_raw( trim( (string) ( $P['api_url'] ?? '' ) ) );
			$ck = trim( (string) ( $P['api_key'] ?? '' ) );
			if ( '' !== $ck ) $new['api_key'] = sanitize_text_field( $ck );
			$new['stores'] = array_values( array_filter( array_map( 'sanitize_key', (array) ( $P['stores'] ?? array() ) ) ) );
			foreach ( array( 'booking_url_ja', 'booking_url_en' ) as $f ) $new[ $f ] = esc_url_raw( trim( (string) ( $P[ $f ] ?? '' ) ) );
			foreach ( array( 'contact_ja', 'contact_en', 'welcome_ja', 'welcome_en' ) as $f ) $new[ $f ] = sanitize_textarea_field( (string) ( $P[ $f ] ?? '' ) );
			$new['bot_name']    = sanitize_text_field( (string) ( $P['bot_name'] ?? '' ) ) ?: 'レンタカー案内チャット';
			$new['floating']    = ! empty( $P['floating'] ) ? 1 : 0;
			$new['rate_ip']     = max( 0, (int) ( $P['rate_ip'] ?? 20 ) );
			$new['daily_limit'] = max( 0, (int) ( $P['daily_limit'] ?? 500 ) );
			$new['log_enabled'] = ! empty( $P['log_enabled'] ) ? 1 : 0;
			$new['log_days']    = min( 365, max( 1, (int) ( $P['log_days'] ?? 30 ) ) );
			BVCB_Settings::save( $new );
			delete_transient( BVCB_Central::CONFIG_CACHE );
			self::notice( '設定を保存しました。' );
			wp_safe_redirect( self::url( 'settings' ) );
			exit;
		}

		if ( 'knowledge' === $action ) {
			$text = (string) ( $P['knowledge'] ?? '' );
			if ( ! empty( $_FILES['kb_file']['name'] ) ) {
				$up = BVCB_Knowledge::from_upload( $_FILES['kb_file'] );
				if ( is_wp_error( $up ) ) {
					self::notice( $up->get_error_message(), false );
					wp_safe_redirect( self::url( 'knowledge' ) );
					exit;
				}
				$text = ( 'append' === ( $P['kb_mode'] ?? '' ) ) ? trim( $text ) . "\n\n" . $up : $up;
			}
			$res = BVCB_Knowledge::save( $text );
			self::notice( is_wp_error( $res ) ? $res->get_error_message() : 'Q&A集を保存しました（' . number_format( BVCB_Knowledge::length( BVCB_Knowledge::get() ) ) . '文字）。', ! is_wp_error( $res ) );
			wp_safe_redirect( self::url( 'knowledge' ) );
			exit;
		}
	}

	/* ---------- 画面 ---------- */

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$tab = sanitize_key( $_GET['tab'] ?? 'settings' );
		$tabs = array( 'settings' => '設定', 'knowledge' => 'Q&A集', 'test' => '動作テスト', 'logs' => '会話ログ' );
		if ( ! isset( $tabs[ $tab ] ) ) $tab = 'settings';

		echo '<div class="wrap"><h1>チャットボット</h1>';
		$n = get_transient( 'bvcb_notice_' . get_current_user_id() );
		if ( $n ) {
			delete_transient( 'bvcb_notice_' . get_current_user_id() );
			echo '<div class="notice notice-' . ( $n[1] ? 'success' : 'error' ) . ' is-dismissible"><p>' . esc_html( $n[0] ) . '</p></div>';
		}
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $v ) {
			echo '<a class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( $k ) ) . '">' . esc_html( $v ) . '</a>';
		}
		echo '</h2>';
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		echo '</div>';
	}

	protected static function tab_settings() {
		$o = BVCB_Settings::get();
		$u = BVCB_Log::usage();

		if ( ! BVCB_Settings::ready() ) {
			echo '<div class="notice notice-warning inline"><p><strong>チャットはまだ表示されていません。</strong>'
				. 'ClaudeのAPIキーを入れて「チャットを有効にする」にチェックすると、サイトに表示されます。'
				. '先に「動作テスト」タブで答え方を確認するのがおすすめです。</p></div>';
		}

		/* 中央サイトとの接続確認 */
		$cfg = ( $o['api_url'] && $o['api_key'] ) ? BVCB_Central::config( true ) : new WP_Error( 'x', '未設定' );
		$all_stores = is_wp_error( $cfg ) ? array() : (array) ( $cfg['stores'] ?? array() );

		echo '<form method="post">';
		wp_nonce_field( 'bvcb_settings' );
		echo '<input type="hidden" name="bvcb_action" value="settings">';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th>チャット</th><td><label><input type="checkbox" name="enabled" value="1"' . checked( $o['enabled'], 1, false ) . '> チャットを有効にする</label>'
			. '<p><label><input type="checkbox" name="floating" value="1"' . checked( $o['floating'], 1, false ) . '> すべてのページの右下に「お問い合わせ」ボタンを表示する</label></p>'
			. '<p class="description">特定のページだけに置く場合はチェックを外し、そのページに <code>[bv_chatbot]</code>（英語ページは <code>[bv_chatbot lang="en"]</code>）を入れてください。</p></td></tr>';

		echo '<tr><th>Claude APIキー</th><td>';
		echo '<input type="password" name="claude_api_key" class="regular-text" autocomplete="new-password" placeholder="' . ( $o['claude_api_key'] ? '設定済み（変更するときだけ入力）' : 'sk-ant-…' ) . '">';
		if ( $o['claude_api_key'] ) echo ' <label><input type="checkbox" name="clear_api_key" value="1"> 削除する</label>';
		echo '<p class="description">Anthropicの管理画面（console.anthropic.com → API Keys）で作成したキー。このサイトの中にだけ保存され、お客様の画面には送られません。</p></td></tr>';

		echo '<tr><th>モデル</th><td><select name="model">';
		foreach ( BVCB_Settings::models() as $k => $v ) echo '<option value="' . esc_attr( $k ) . '"' . selected( $o['model'], $k, false ) . '>' . esc_html( $v ) . '</option>';
		echo '</select> <select name="effort">';
		foreach ( BVCB_Settings::efforts() as $k => $v ) echo '<option value="' . esc_attr( $k ) . '"' . selected( $o['effort'], $k, false ) . '>考える深さ：' . esc_html( $v ) . '</option>';
		echo '</select><p class="description">まずは既定のまま試し、費用や応答の速さが気になる場合に変えてください（Haikuでは「考える深さ」は使われません）。</p></td></tr>';

		echo '<tr><th>中央サイトの接続先</th><td>';
		echo '<input type="url" name="api_url" class="large-text" value="' . esc_attr( $o['api_url'] ) . '" placeholder="https://be-village.com/wp-json/bvrm/v1/">';
		echo '<input type="password" name="api_key" class="regular-text" style="margin-top:6px" autocomplete="new-password" placeholder="' . ( $o['api_key'] ? 'APIキー設定済み（変更するときだけ入力）' : '中央サイトのAPIキー' ) . '">';
		echo '<p class="description">予約フォームと同じURL・APIキーです。同じサイトに予約フォームのプラグインがあれば自動で引き継ぎます。空車確認に使います。</p>';
		if ( is_wp_error( $cfg ) ) {
			echo '<p style="color:#b32d2e">接続できません：' . esc_html( $cfg->get_error_message() ) . '（空車確認なしで、Q&A集だけで答えます）</p>';
		} else {
			echo '<p style="color:#00a32a">接続OK（店舗 ' . count( $all_stores ) . '件）</p>';
		}
		echo '</td></tr>';

		echo '<tr><th>案内する店舗</th><td>';
		if ( $all_stores ) {
			foreach ( $all_stores as $k => $st ) {
				echo '<label style="display:block"><input type="checkbox" name="stores[]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, $o['stores'], true ), true, false ) . '> ' . esc_html( $st['ja'] ?? $k ) . '</label>';
			}
			echo '<p class="description">このサイトのチャットで空車確認・案内する店舗。何も選ばなければ全店舗です。</p>';
		} else {
			echo '<span class="description">中央サイトに接続すると選べます。</span>';
		}
		echo '</td></tr>';

		echo '<tr><th>予約フォームのページ</th><td>'
			. '<input type="url" name="booking_url_ja" class="large-text" value="' . esc_attr( $o['booking_url_ja'] ) . '" placeholder="日本語の予約ページのURL">'
			. '<input type="url" name="booking_url_en" class="large-text" style="margin-top:6px" value="' . esc_attr( $o['booking_url_en'] ) . '" placeholder="英語の予約ページのURL">'
			. '<p class="description">空きがあったときなどに、ここへご案内します。</p></td></tr>';

		echo '<tr><th>問い合わせ先</th><td>'
			. '<textarea name="contact_ja" rows="3" class="large-text" placeholder="例：お電話 0261-00-0000（9:00〜18:00）／メール info@example.com">' . esc_textarea( $o['contact_ja'] ) . '</textarea>'
			. '<textarea name="contact_en" rows="2" class="large-text" placeholder="English contact info (optional)">' . esc_textarea( $o['contact_en'] ) . '</textarea>'
			. '<p class="description">チャットで答えられないときに案内します。</p></td></tr>';

		echo '<tr><th>表示</th><td>'
			. '<p><label style="display:inline-block;width:110px">チャットの名前</label><input type="text" name="bot_name" class="regular-text" value="' . esc_attr( $o['bot_name'] ) . '"></p>'
			. '<p><label>最初のあいさつ（日本語）</label><br><textarea name="welcome_ja" rows="3" class="large-text">' . esc_textarea( $o['welcome_ja'] ) . '</textarea></p>'
			. '<p><label>最初のあいさつ（英語）</label><br><textarea name="welcome_en" rows="3" class="large-text">' . esc_textarea( $o['welcome_en'] ) . '</textarea></p></td></tr>';

		echo '<tr><th>使いすぎ防止</th><td>'
			. '<p>1つの接続元から10分間に <input type="number" name="rate_ip" min="0" style="width:80px" value="' . (int) $o['rate_ip'] . '"> 回まで質問できる</p>'
			. '<p>Claude APIの呼び出しは1日 <input type="number" name="daily_limit" min="0" style="width:90px" value="' . (int) $o['daily_limit'] . '"> 回まで（0＝無制限）</p>'
			. '<p class="description">本日の利用：呼び出し ' . number_format( $u['calls'] ) . '回／入力 ' . number_format( $u['input'] ) . '・キャッシュ読込 ' . number_format( $u['cache_read'] )
			. '・キャッシュ作成 ' . number_format( $u['cache_write'] ) . '・出力 ' . number_format( $u['output'] ) . ' トークン。'
			. 'Anthropicの管理画面でも利用額の上限（Spend limit）を設定しておくと安心です。</p></td></tr>';

		echo '<tr><th>会話ログ</th><td><label><input type="checkbox" name="log_enabled" value="1"' . checked( $o['log_enabled'], 1, false ) . '> 会話を記録する（Q&A集の改善用）</label>'
			. '<p><input type="number" name="log_days" min="1" max="365" style="width:80px" value="' . (int) $o['log_days'] . '"> 日を過ぎたら自動で削除する</p>'
			. '<p class="description">IPアドレスは記録しません。お客様が個人情報を書き込む可能性があるため、保存期間は短めをおすすめします。</p></td></tr>';

		echo '</tbody></table>';
		submit_button( '設定を保存' );
		echo '</form>';
	}

	protected static function tab_knowledge() {
		$kb = BVCB_Knowledge::get();
		$len = BVCB_Knowledge::length( $kb );
		echo '<p>チャットは、ここに書いた内容と、予約管理システムの店舗・料金・キャンセルポリシーの設定をもとに答えます。'
			. 'ここにないことは推測で答えず、問い合わせ先をご案内します。</p>';
		echo '<p class="description">書き方の例：<br><code>Q：チャイルドシートはありますか？<br>A：あります。1台1日550円です。予約フォームのオプションでお申し込みください。</code><br>'
			. '・項目は空行で区切ると読みやすくなります。<br>・料金・営業時間・キャンセルポリシーは予約管理システムの設定から自動で読み込むため、ここに書かなくても大丈夫です（書くと食い違いの原因になります）。<br>'
			. '・エクセルで作る場合は、A列に質問・B列に回答を入れて「CSV」で保存し、下からアップロードしてください（C列以降は補足として扱います）。</p>';

		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field( 'bvcb_knowledge' );
		echo '<input type="hidden" name="bvcb_action" value="knowledge">';
		echo '<textarea name="knowledge" rows="22" class="large-text code">' . esc_textarea( $kb ) . '</textarea>';
		echo '<p>現在 <strong>' . number_format( $len ) . '</strong> 文字（上限 ' . number_format( BVCB_Knowledge::MAX_CHARS ) . ' 文字）。'
			. '<span class="description">長いほど1回の質問あたりの費用が増えます（同じ内容を続けて使う間は、キャッシュで安くなります）。</span></p>';
		echo '<p><strong>ファイルから読み込む</strong>（.txt / .md / .csv、1MBまで）：<input type="file" name="kb_file" accept=".txt,.md,.csv">'
			. ' <label><input type="radio" name="kb_mode" value="append" checked> 今の内容の後ろに追加</label>'
			. ' <label><input type="radio" name="kb_mode" value="replace"> 置き換える</label></p>';
		submit_button( 'Q&A集を保存' );
		echo '</form>';
	}

	protected static function tab_test() {
		$o = BVCB_Settings::get();
		echo '<p>お客様と同じ方法で回答を作ります（サイトに公開していなくても試せます）。空車確認を使った場合は、その内容も表示します。</p>';
		if ( '' === $o['claude_api_key'] ) {
			echo '<div class="notice notice-warning inline"><p>先に「設定」タブでClaude APIキーを入れてください。</p></div>';
			return;
		}
		$q = isset( $_POST['bvcb_test_q'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bvcb_test_q'] ) ) : '';
		$lang = ( isset( $_POST['bvcb_test_lang'] ) && 'en' === $_POST['bvcb_test_lang'] ) ? 'en' : 'ja';
		echo '<form method="post">';
		wp_nonce_field( 'bvcb_test' );
		echo '<textarea name="bvcb_test_q" rows="3" class="large-text" placeholder="例：来週の土曜10時から日曜18時まで、白馬駅前店でミニバンは空いていますか？">' . esc_textarea( $q ) . '</textarea>';
		echo '<p><label><input type="radio" name="bvcb_test_lang" value="ja"' . checked( $lang, 'ja', false ) . '> 日本語ページとして</label> '
			. '<label><input type="radio" name="bvcb_test_lang" value="en"' . checked( $lang, 'en', false ) . '> 英語ページとして</label></p>';
		submit_button( '質問してみる', 'primary', 'submit', false );
		echo '</form>';

		if ( '' !== $q && check_admin_referer( 'bvcb_test' ) ) {
			if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 180 );
			$t0 = microtime( true );
			$res = BVCB_Claude::answer( array(), $q, $lang );
			$sec = round( microtime( true ) - $t0, 1 );
			echo '<h3>回答（' . esc_html( $sec ) . '秒）</h3>';
			echo '<div style="background:#fff;border:1px solid #dcdcde;padding:12px 16px;white-space:pre-wrap;max-width:760px">' . esc_html( $res['reply'] ) . '</div>';
			if ( ! empty( $res['error'] ) ) echo '<p style="color:#b32d2e">エラー：' . esc_html( $res['error'] ) . '</p>';
			if ( $res['tools'] ) {
				echo '<h3>空車確認の内容</h3><ol>';
				foreach ( $res['tools'] as $t ) {
					echo '<li><code>' . esc_html( wp_json_encode( $t['input'], JSON_UNESCAPED_UNICODE ) ) . '</code><br>→ <code style="white-space:pre-wrap">' . esc_html( $t['result'] ) . '</code></li>';
				}
				echo '</ol>';
			}
		}
	}

	protected static function tab_logs() {
		$o = BVCB_Settings::get();
		if ( empty( $o['log_enabled'] ) ) echo '<p class="description">会話の記録はオフになっています（設定タブで変更できます）。</p>';
		$sid = isset( $_GET['sid'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $_GET['sid'] ) : '';
		if ( $sid ) {
			echo '<p><a href="' . esc_url( self::url( 'logs' ) ) . '">← 一覧に戻る</a></p>';
			$labels = array( 'user' => 'お客様', 'bot' => 'チャット', 'tool' => '空車確認', 'error' => 'エラー' );
			$colors = array( 'user' => '#f0f6fc', 'bot' => '#fff', 'tool' => '#f6f7f7', 'error' => '#fcf0f1' );
			foreach ( BVCB_Log::thread( $sid ) as $row ) {
				echo '<div style="background:' . esc_attr( $colors[ $row->role ] ?? '#fff' ) . ';border:1px solid #dcdcde;padding:8px 12px;margin:6px 0;max-width:760px">'
					. '<strong>' . esc_html( $labels[ $row->role ] ?? $row->role ) . '</strong> <span class="description">' . esc_html( $row->created_at ) . '</span>'
					. '<div style="white-space:pre-wrap;margin-top:4px">' . esc_html( $row->body ) . '</div></div>';
			}
			return;
		}
		$rows = BVCB_Log::sessions( 100 );
		if ( ! $rows ) { echo '<p>まだ会話はありません。</p>'; return; }
		echo '<p class="description">直近100件の会話です。答えられなかった質問は、Q&A集に追加すると次から答えられるようになります。</p>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>最後のやり取り</th><th>質問数</th><th>空車確認</th><th>エラー</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( $r->last_at ) . '</td><td>' . (int) $r->questions . '</td><td>' . (int) $r->tool_uses . '</td><td>' . ( (int) $r->errors ? '<span style="color:#b32d2e">' . (int) $r->errors . '</span>' : '0' ) . '</td>'
				. '<td><a href="' . esc_url( self::url( 'logs', array( 'sid' => $r->sid ) ) ) . '">内容を見る</a></td></tr>';
		}
		echo '</tbody></table>';
	}
}
