<?php
namespace RCAC\Admin;

use RCAC\Availability\AvailabilityService;
use RCAC\Availability\Schema;

/**
 * 空車連携タブの補助ツール：DB 構造の確認と、簡易ガントチャートによる接続テスト。
 */
final class AvailabilityTools {

	public static function render(): void {
		echo '<hr style="margin:32px 0">';
		self::render_test();
		echo '<hr style="margin:32px 0">';
		self::render_schema_browser();
	}

	private static function render_test(): void {
		echo '<h2 id="rcac-test">接続テスト（簡易ガントチャート）</h2>';
		if ( ! AvailabilityService::enabled() ) {
			echo '<p>空車案内は無効になっています。</p>';
			return;
		}
		echo '<p>保存済みの設定で今日から14日間の予約状況を表示します。既存の予約プラグインのガントチャートと同じ場所が埋まっていれば連携できています（オレンジ = 予約あり）。</p>';

		try {
			$data = AvailabilityService::timeline( new \DateTimeImmutable( 'today', wp_timezone() ), 14 );
		} catch ( \Throwable $e ) {
			printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( $e->getMessage() ) );
			return;
		}

		printf( '<p>車両 %1$d 台 / 期間内の予約 %2$d 件</p>', count( $data['rows'] ), (int) $data['bookings'] );
		if ( ! $data['rows'] ) {
			echo '<p>車両が見つかりません。車両テーブル（投稿タイプ）の設定を確認してください。</p>';
			return;
		}
		$week = array( '日', '月', '火', '水', '木', '金', '土' );
		echo '<div style="overflow-x:auto"><table class="rcac-gantt"><thead><tr><th class="name">車両</th><th>クラス</th>';
		foreach ( $data['days'] as $d ) {
			printf( '<th>%s<br>%s</th>', esc_html( $d->format( 'n/j' ) ), esc_html( $week[ (int) $d->format( 'w' ) ] ) );
		}
		echo '</tr></thead><tbody>';
		foreach ( $data['rows'] as $row ) {
			$v = $row['vehicle'];
			printf( '<tr><th class="name">%s <small>(ID: %s)</small></th><td>%s</td>', esc_html( $v['name'] ), esc_html( $v['id'] ), esc_html( $v['class'] ) );
			foreach ( $row['cells'] as $busy ) {
				echo $busy ? '<td class="busy" title="予約あり">●</td>' : '<td class="free"></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_schema_browser(): void {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 表示のみの GET パラメーター
		$sel_table = isset( $_GET['rcac_table'] ) ? sanitize_text_field( wp_unslash( $_GET['rcac_table'] ) ) : '';
		$sel_pt    = isset( $_GET['rcac_pt'] ) ? sanitize_key( wp_unslash( $_GET['rcac_pt'] ) ) : '';
		// phpcs:enable

		echo '<h2 id="rcac-schema">予約プラグインのデータ構造を調べる</h2>';
		echo '<p>上の設定に入れるテーブル名・列名・カスタムフィールド名を探すための一覧です（列名とデータ型のみ表示します）。</p>';

		// --- テーブル型
		$tables = Schema::tables();
		$core   = array( 'posts', 'postmeta', 'users', 'usermeta', 'options', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'comments', 'commentmeta', 'links', 'rcac_conversations', 'rcac_messages' );
		echo '<h3>データベースのテーブル</h3><form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '#rcac-schema">';
		echo '<input type="hidden" name="page" value="rcac"><input type="hidden" name="tab" value="availability">';
		echo '<select name="rcac_table"><option value="">テーブルを選択</option>';
		foreach ( $tables as $t ) {
			$short = str_starts_with( $t, $wpdb->prefix ) ? substr( $t, strlen( $wpdb->prefix ) ) : $t;
			if ( in_array( $short, $core, true ) ) {
				continue;
			}
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $t ), selected( $sel_table, $t, false ) );
		}
		echo '</select> <button class="button">列を表示</button></form>';
		echo '<p class="description">WordPress 標準のテーブルは除いています。予約プラグイン名を含むテーブル（例: ' . esc_html( $wpdb->prefix ) . 'xxx_booking）を探してください。</p>';

		if ( '' !== $sel_table && in_array( $sel_table, $tables, true ) ) {
			$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::quote( $sel_table ) );
			printf( '<h4>%1$s（%2$s 行）</h4>', esc_html( $sel_table ), esc_html( number_format( $count ) ) );
			echo '<table class="widefat striped" style="max-width:600px"><thead><tr><th>列名</th><th>型</th></tr></thead><tbody>';
			foreach ( Schema::columns( $sel_table ) as $col => $type ) {
				printf( '<tr><td><code>%s</code></td><td>%s</td></tr>', esc_html( $col ), esc_html( $type ) );
			}
			echo '</tbody></table>';
		}

		// --- 投稿型
		echo '<h3 style="margin-top:24px">カスタム投稿タイプ</h3><form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '#rcac-schema">';
		echo '<input type="hidden" name="page" value="rcac"><input type="hidden" name="tab" value="availability">';
		echo '<select name="rcac_pt"><option value="">投稿タイプを選択</option>';
		foreach ( get_post_types( array( '_builtin' => false ), 'objects' ) as $pt ) {
			if ( str_starts_with( $pt->name, 'rcac_' ) ) {
				continue;
			}
			$n = wp_count_posts( $pt->name );
			$n = (int) array_sum( array_map( 'intval', (array) $n ) );
			printf( '<option value="%1$s"%2$s>%3$s（%1$s / %4$d 件）</option>', esc_attr( $pt->name ), selected( $sel_pt, $pt->name, false ), esc_html( $pt->label ), (int) $n );
		}
		echo '</select> <button class="button">カスタムフィールドを表示</button></form>';

		if ( '' !== $sel_pt && post_type_exists( $sel_pt ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.meta_key, MAX(pm.meta_value) AS sample FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE p.post_type = %s GROUP BY pm.meta_key ORDER BY pm.meta_key LIMIT 200",
					$sel_pt
				),
				ARRAY_A
			);
			$taxes = get_object_taxonomies( $sel_pt );
			printf( '<h4>%s のカスタムフィールド</h4>', esc_html( $sel_pt ) );
			echo '<table class="widefat striped" style="max-width:700px"><thead><tr><th>キー</th><th>値の例（日付・数値のみ表示）</th></tr></thead><tbody>';
			foreach ( $rows ? $rows : array() as $r ) {
				$sample = (string) $r['sample'];
				// 個人情報を表示しないよう、日時や数値に見える値だけを例として出す。
				$show = preg_match( '/^[0-9\-\/: T.]{1,30}$/', $sample ) ? $sample : '';
				printf( '<tr><td><code>%s</code></td><td>%s</td></tr>', esc_html( (string) $r['meta_key'] ), esc_html( $show ) );
			}
			echo '</tbody></table>';
			if ( $taxes ) {
				echo '<p>タクソノミー: ' . esc_html( implode( ', ', array_map( static fn( $t ) => 'tax:' . $t, $taxes ) ) ) . '</p>';
			}
		}
	}
}
