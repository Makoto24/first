<?php
namespace RCAC\Admin;

use RCAC\ConversationStore;

/**
 * 会話ログ画面。お客様がどんな質問をしているかを確認し、Q&A 集の改善に使う。
 */
final class Logs {

	private const PER_PAGE = 30;

	public static function init(): void {
		add_action( 'admin_post_rcac_delete_conversation', array( self::class, 'handle_delete' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示のみ
		$id = isset( $_GET['conversation'] ) ? sanitize_key( wp_unslash( $_GET['conversation'] ) ) : '';
		echo '<div class="wrap">';
		if ( '' !== $id ) {
			self::render_detail( $id );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function render_list(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$data = ConversationStore::list_conversations( $page, self::PER_PAGE );

		echo '<h1>会話ログ</h1>';
		echo '<p>チャットでのお客様とのやり取りです。回答できなかった質問は Q&A 集に追加すると、次から答えられるようになります。保存期間を過ぎたログは自動で削除されます。</p>';
		if ( ! $data['rows'] ) {
			echo '<p>まだ会話はありません。</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th style="width:150px">最終更新</th><th>最初の質問</th><th style="width:70px">件数</th><th style="width:110px">推定コスト</th><th style="width:110px">問い合わせ</th></tr></thead><tbody>';
		foreach ( $data['rows'] as $row ) {
			$url = admin_url( 'admin.php?page=rcac-logs&conversation=' . $row['id'] );
			printf(
				'<tr><td>%1$s</td><td><a href="%2$s">%3$s</a></td><td>%4$d</td><td>$%5$s</td><td>%6$s</td></tr>',
				esc_html( get_date_from_gmt( (string) $row['updated_at'], 'Y-m-d H:i' ) ),
				esc_url( $url ),
				esc_html( mb_strimwidth( (string) ( $row['first_message'] ?? '' ), 0, 90, '…' ) ?: '（メッセージなし）' ),
				(int) $row['message_count'],
				esc_html( number_format( (float) $row['cost_usd'], 4 ) ),
				$row['inquiry_id'] ? '<a href="' . esc_url( admin_url( 'post.php?action=edit&post=' . (int) $row['inquiry_id'] ) ) . '">あり</a>' : '-'
			);
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $data['total'] / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
	}

	private static function render_detail( string $id ): void {
		$conv = ConversationStore::conversation( $id );
		printf( '<h1>会話ログ <a href="%s" class="page-title-action">一覧に戻る</a></h1>', esc_url( admin_url( 'admin.php?page=rcac-logs' ) ) );
		if ( ! $conv ) {
			echo '<p>この会話は見つかりません（保存期間を過ぎて削除された可能性があります）。</p>';
			return;
		}
		printf(
			'<p>開始: %1$s ／ ページ: %2$s ／ トークン: 入力 %3$s・出力 %4$s・キャッシュ読込 %5$s ／ 推定コスト: $%6$s</p>',
			esc_html( get_date_from_gmt( (string) $conv['created_at'], 'Y-m-d H:i' ) ),
			esc_html( (string) $conv['page_url'] ),
			esc_html( number_format( (int) $conv['input_tokens'] ) ),
			esc_html( number_format( (int) $conv['output_tokens'] ) ),
			esc_html( number_format( (int) $conv['cache_read_tokens'] ) ),
			esc_html( number_format( (float) $conv['cost_usd'], 4 ) )
		);
		if ( $conv['inquiry_id'] ) {
			printf( '<p><a href="%s">この会話の後に送信された問い合わせを見る</a></p>', esc_url( admin_url( 'post.php?action=edit&post=' . (int) $conv['inquiry_id'] ) ) );
		}

		$labels = array(
			'user'      => 'お客様',
			'assistant' => 'ボット',
			'error'     => 'エラー',
		);
		echo '<div class="rcac-chatlog">';
		foreach ( ConversationStore::messages( $id ) as $m ) {
			$meta  = $m['meta'] ? json_decode( (string) $m['meta'], true ) : array();
			$extra = '';
			if ( ! empty( $meta['tools'] ) ) {
				$extra = ' ／ 使用ツール: ' . implode( ', ', (array) $meta['tools'] );
			}
			printf(
				'<div class="m %1$s"><div class="meta">%2$s ・ %3$s%4$s</div>%5$s</div>',
				esc_attr( (string) $m['role'] ),
				esc_html( $labels[ $m['role'] ] ?? (string) $m['role'] ),
				esc_html( get_date_from_gmt( (string) $m['created_at'], 'm/d H:i:s' ) ),
				esc_html( $extra ),
				esc_html( (string) $m['content'] )
			);
		}
		echo '</div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'この会話ログを削除しますか？\');">';
		wp_nonce_field( 'rcac_delete_conversation' );
		printf( '<input type="hidden" name="action" value="rcac_delete_conversation"><input type="hidden" name="conversation" value="%s">', esc_attr( $id ) );
		submit_button( 'この会話ログを削除', 'delete', 'submit', false );
		echo '</form>';
	}

	public static function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません。' );
		}
		check_admin_referer( 'rcac_delete_conversation' );
		$id = isset( $_POST['conversation'] ) ? sanitize_key( wp_unslash( $_POST['conversation'] ) ) : '';
		if ( '' !== $id ) {
			ConversationStore::delete( $id );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=rcac-logs' ) );
		exit;
	}
}
