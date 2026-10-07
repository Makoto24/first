<?php
namespace RCAC;

/**
 * 会話ログの保存と読み出し。
 *
 * Claude に渡す会話履歴はここに保存したテキスト（お客様の発言とボットの最終回答）だけで組み立てる。
 * ブラウザから送られた履歴は使わないため、過去の発言の改ざんによる指示の書き換えを防げる。
 */
final class ConversationStore {

	/** モデルごとの料金（USD / 100万トークン）。コストの目安表示に使う。 */
	private const PRICING = array(
		'claude-opus-5-5'   => array( 4.0, 20.0, 0.20, 5.0 ),
		'claude-sonnet-5-5' => array( 2.0, 10.0, 0.20, 2.5 ),
		'claude-haiku-4-5'  => array( 1.0, 5.0, 0.10, 1.25 ),
	);

	private static function conv_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rcac_conversations';
	}

	private static function msg_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rcac_messages';
	}

	public static function ensure_conversation( string $id, string $page_url ): void {
		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::conv_table() . ' WHERE id = %s', $id ) );
		if ( $exists ) {
			return;
		}
		$now = current_time( 'mysql', true );
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$wpdb->insert(
			self::conv_table(),
			array(
				'id'         => $id,
				'ip_hash'    => Guard::ip_hash(),
				'page_url'   => mb_substr( $page_url, 0, 255 ),
				'user_agent' => mb_substr( $ua, 0, 255 ),
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
	}

	/**
	 * @param array<string,mixed> $meta
	 */
	public static function add_message( string $conversation_id, string $role, string $content, array $meta = array() ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			self::msg_table(),
			array(
				'conversation_id' => $conversation_id,
				'role'            => $role,
				'content'         => $content,
				'meta'            => $meta ? wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ) : null,
				'created_at'      => $now,
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::conv_table() . ' SET message_count = message_count + 1, updated_at = %s WHERE id = %s',
				$now,
				$conversation_id
			)
		);
	}

	/**
	 * @param array{input:int,output:int,cache_read:int,cache_write:int} $usage
	 */
	public static function add_usage( string $conversation_id, string $model, array $usage ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::conv_table() . ' SET input_tokens = input_tokens + %d, output_tokens = output_tokens + %d, cache_read_tokens = cache_read_tokens + %d, cache_write_tokens = cache_write_tokens + %d, cost_usd = cost_usd + %f WHERE id = %s',
				$usage['input'],
				$usage['output'],
				$usage['cache_read'],
				$usage['cache_write'],
				self::estimate_cost( $model, $usage ),
				$conversation_id
			)
		);
	}

	/**
	 * @param array{input:int,output:int,cache_read:int,cache_write:int} $usage
	 */
	public static function estimate_cost( string $model, array $usage ): float {
		$p = self::PRICING[ $model ] ?? self::PRICING['claude-opus-5-5'];
		return ( $usage['input'] * $p[0] + $usage['output'] * $p[1] + $usage['cache_read'] * $p[2] + $usage['cache_write'] * $p[3] ) / 1000000;
	}

	public static function link_inquiry( string $conversation_id, int $inquiry_id ): void {
		global $wpdb;
		$wpdb->update( self::conv_table(), array( 'inquiry_id' => $inquiry_id ), array( 'id' => $conversation_id ) );
	}

	/** 会話の往復数（お客様の発言数）。 */
	public static function user_turns( string $conversation_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::msg_table() . " WHERE conversation_id = %s AND role = 'user'", $conversation_id )
		);
	}

	/**
	 * Claude に渡す直近の会話履歴。user / assistant が交互になるよう整形し、user から始まるようにする。
	 *
	 * @return list<array{role:string,content:string}>
	 */
	public static function history( string $conversation_id, int $max_messages = 20 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT role, content FROM ' . self::msg_table() . " WHERE conversation_id = %s AND role IN ('user','assistant') ORDER BY id DESC LIMIT %d",
				$conversation_id,
				$max_messages
			),
			ARRAY_A
		);
		$rows = array_reverse( $rows ? $rows : array() );

		$out = array();
		foreach ( $rows as $row ) {
			$last = count( $out ) - 1;
			if ( $last >= 0 && $out[ $last ]['role'] === $row['role'] ) {
				$out[ $last ]['content'] .= "\n\n" . $row['content'];
			} else {
				$out[] = array(
					'role'    => $row['role'],
					'content' => (string) $row['content'],
				);
			}
		}
		while ( $out && 'user' !== $out[0]['role'] ) {
			array_shift( $out );
		}
		return $out;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public static function messages( string $conversation_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::msg_table() . ' WHERE conversation_id = %s ORDER BY id ASC', $conversation_id ),
			ARRAY_A
		);
		return $rows ? $rows : array();
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function conversation( string $conversation_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::conv_table() . ' WHERE id = %s', $conversation_id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * 管理画面用の一覧。
	 *
	 * @return array{rows:list<array<string,mixed>>,total:int}
	 */
	public static function list_conversations( int $page, int $per_page ): array {
		global $wpdb;
		$offset = max( 0, ( $page - 1 ) * $per_page );
		$conv   = self::conv_table();
		$msgs   = self::msg_table();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*, (SELECT m.content FROM {$msgs} m WHERE m.conversation_id = c.id AND m.role = 'user' ORDER BY m.id ASC LIMIT 1) AS first_message
				FROM {$conv} c ORDER BY c.updated_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$conv}" );
		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * 期間内の集計（管理画面の概要表示用）。
	 *
	 * @return array{conversations:int,messages:int,cost:float}
	 */
	public static function stats_since( string $since_utc ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS conversations, COALESCE(SUM(message_count),0) AS messages, COALESCE(SUM(cost_usd),0) AS cost FROM ' . self::conv_table() . ' WHERE created_at >= %s',
				$since_utc
			),
			ARRAY_A
		);
		return array(
			'conversations' => (int) ( $row['conversations'] ?? 0 ),
			'messages'      => (int) ( $row['messages'] ?? 0 ),
			'cost'          => (float) ( $row['cost'] ?? 0 ),
		);
	}

	public static function delete( string $conversation_id ): void {
		global $wpdb;
		$wpdb->delete( self::msg_table(), array( 'conversation_id' => $conversation_id ) );
		$wpdb->delete( self::conv_table(), array( 'id' => $conversation_id ) );
	}

	/** 保存期間を過ぎた会話を削除する（日次 cron）。 */
	public static function cleanup(): void {
		global $wpdb;
		$days   = max( 1, (int) Settings::get( 'log_retention_days' ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$ids    = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::conv_table() . ' WHERE updated_at < %s LIMIT 5000', $cutoff ) );
		foreach ( $ids as $id ) {
			self::delete( (string) $id );
		}
	}
}
