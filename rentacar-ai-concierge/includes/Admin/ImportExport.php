<?php
namespace RCAC\Admin;

use RCAC\KnowledgeBase;

/**
 * Q&A 集の CSV 取り込み・書き出し。
 *
 * CSV の列: 質問, 回答, カテゴリ（任意）, 並び順（任意）
 * Excel で保存した Shift_JIS の CSV も、UTF-8 の CSV も読み込める。
 */
final class ImportExport {

	private const MAX_BYTES = 5 * 1024 * 1024;

	public static function init(): void {
		add_action( 'admin_post_rcac_import_faq', array( self::class, 'handle_import' ) );
		add_action( 'admin_post_rcac_export_faq', array( self::class, 'handle_export' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>Q&A CSV取り込み</h1>';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 結果表示のみ
		if ( isset( $_GET['imported'] ) ) {
			printf(
				'<div class="notice notice-success"><p>取り込みが完了しました。追加 %1$d 件 / 更新 %2$d 件 / スキップ %3$d 件%4$s</p></div>',
				(int) $_GET['imported'],
				(int) ( $_GET['updated'] ?? 0 ),
				(int) ( $_GET['skipped'] ?? 0 ),
				! empty( $_GET['deleted'] ) ? ' / 既存の Q&A ' . (int) $_GET['deleted'] . ' 件を削除' : ''
			);
		}
		if ( isset( $_GET['rcac_error'] ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( sanitize_text_field( wp_unslash( $_GET['rcac_error'] ) ) ) );
		}
		// phpcs:enable

		?>
		<p>Q&A 集を CSV ファイルで一括登録します。Excel で作成し「CSV（コンマ区切り）」または「CSV UTF-8」で保存したファイルをそのまま使えます。</p>
		<table class="widefat striped" style="max-width:760px">
			<thead><tr><th>列</th><th>内容</th></tr></thead>
			<tbody>
				<tr><td>1列目: 質問</td><td>必須。例: 免許証は何が必要ですか？</td></tr>
				<tr><td>2列目: 回答</td><td>必須。改行を含めても構いません。</td></tr>
				<tr><td>3列目: カテゴリ</td><td>任意。例: 予約・料金・保険・車両</td></tr>
				<tr><td>4列目: 並び順</td><td>任意。数字が小さいほど先に並びます。</td></tr>
			</tbody>
		</table>
		<p>1行目が見出し（「質問」「回答」など）の場合は自動で読み飛ばします。同じ質問が既にある場合は回答を上書きします。
			<a href="<?php echo esc_url( RCAC_URL . 'sample/faq-sample.csv' ); ?>" download>サンプルCSVをダウンロード</a></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin-top:20px">
			<?php wp_nonce_field( 'rcac_import_faq' ); ?>
			<input type="hidden" name="action" value="rcac_import_faq">
			<p><input type="file" name="rcac_csv" accept=".csv,text/csv" required></p>
			<p>
				<label><input type="radio" name="mode" value="merge" checked> 追加・更新する（既存の Q&A は残す）</label><br>
				<label><input type="radio" name="mode" value="replace"> 既存の Q&A をすべて削除してから取り込む</label>
			</p>
			<?php submit_button( '取り込む', 'primary', 'submit', false ); ?>
		</form>

		<h2 style="margin-top:40px">書き出し</h2>
		<p>登録済みの Q&A を CSV でダウンロードします。Excel で編集して再度取り込めます。</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'rcac_export_faq' ); ?>
			<input type="hidden" name="action" value="rcac_export_faq">
			<?php submit_button( 'CSV をダウンロード', 'secondary', 'submit', false ); ?>
		</form>
		</div>
		<?php
	}

	public static function handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません。' );
		}
		check_admin_referer( 'rcac_import_faq' );
		$back = admin_url( 'admin.php?page=rcac-import' );

		$file = $_FILES['rcac_csv'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== ( $file['error'] ?? -1 ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::redirect_error( $back, 'ファイルをアップロードできませんでした。' );
		}
		if ( $file['size'] > self::MAX_BYTES ) {
			self::redirect_error( $back, 'ファイルが大きすぎます（5MB まで）。' );
		}
		$ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'txt' ), true ) ) {
			self::redirect_error( $back, 'CSV ファイルを選択してください。' );
		}

		$rows = self::parse_csv( (string) file_get_contents( $file['tmp_name'] ) );
		if ( ! $rows ) {
			self::redirect_error( $back, 'CSV にデータがありません。' );
		}

		$deleted = 0;
		$mode    = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'merge';
		if ( 'replace' === $mode ) {
			$ids = get_posts(
				array(
					'post_type'   => KnowledgeBase::POST_TYPE,
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			);
			foreach ( $ids as $id ) {
				wp_delete_post( (int) $id, true );
				++$deleted;
			}
		}

		$result = self::import_rows( $rows );
		wp_safe_redirect(
			add_query_arg(
				array(
					'imported' => $result['added'],
					'updated'  => $result['updated'],
					'skipped'  => $result['skipped'],
					'deleted'  => $deleted,
				),
				$back
			)
		);
		exit;
	}

	/**
	 * CSV テキストを行の配列にする（文字コード自動判定・BOM 除去）。
	 *
	 * @return list<list<string>>
	 */
	public static function parse_csv( string $raw ): array {
		if ( str_starts_with( $raw, "\xEF\xBB\xBF" ) ) {
			$raw = substr( $raw, 3 );
		} elseif ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw = (string) mb_convert_encoding( $raw, 'UTF-8', 'SJIS-win' );
		}
		$raw = str_replace( array( "\r\n", "\r" ), "\n", $raw );

		$fh = fopen( 'php://temp', 'r+' );
		fwrite( $fh, $raw );
		rewind( $fh );
		$rows = array();
		while ( ( $row = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
			if ( null === $row || array( null ) === $row ) {
				continue;
			}
			$row = array_map( static fn( $v ) => trim( (string) $v ), $row );
			if ( '' === implode( '', $row ) ) {
				continue;
			}
			$rows[] = $row;
		}
		fclose( $fh );
		return $rows;
	}

	/**
	 * @param list<list<string>> $rows
	 * @return array{added:int,updated:int,skipped:int}
	 */
	public static function import_rows( array $rows ): array {
		$map = array(
			'q'     => 0,
			'a'     => 1,
			'cat'   => 2,
			'order' => 3,
		);
		// 1行目が見出しなら列の並びを読み取る。
		$first  = array_map( static fn( $v ) => mb_strtolower( $v ), $rows[0] );
		$header = false;
		foreach ( $first as $i => $h ) {
			if ( preg_match( '/^(質問|question|q)$/u', $h ) ) {
				$map['q'] = $i;
				$header   = true;
			} elseif ( preg_match( '/^(回答|answer|a|答え)$/u', $h ) ) {
				$map['a'] = $i;
				$header   = true;
			} elseif ( preg_match( '/^(カテゴリ|カテゴリー|分類|category)$/u', $h ) ) {
				$map['cat'] = $i;
			} elseif ( preg_match( '/^(並び順|順番|order)$/u', $h ) ) {
				$map['order'] = $i;
			}
		}
		if ( $header ) {
			array_shift( $rows );
		}

		$added   = 0;
		$updated = 0;
		$skipped = 0;
		foreach ( $rows as $row ) {
			$q = sanitize_text_field( $row[ $map['q'] ] ?? '' );
			$a = sanitize_textarea_field( $row[ $map['a'] ] ?? '' );
			if ( '' === $q || '' === $a ) {
				++$skipped;
				continue;
			}
			$cat   = sanitize_text_field( $row[ $map['cat'] ] ?? '' );
			$order = isset( $row[ $map['order'] ] ) && is_numeric( $row[ $map['order'] ] ) ? (int) $row[ $map['order'] ] : 0;

			$existing = get_posts(
				array(
					'post_type'   => KnowledgeBase::POST_TYPE,
					'post_status' => 'any',
					'title'       => $q,
					'numberposts' => 1,
					'fields'      => 'ids',
				)
			);
			$postarr  = array(
				'post_type'    => KnowledgeBase::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $q,
				'post_content' => $a,
				'menu_order'   => $order,
			);
			if ( $existing ) {
				$postarr['ID'] = (int) $existing[0];
				$id            = wp_update_post( wp_slash( $postarr ), true );
				++$updated;
			} else {
				$id = wp_insert_post( wp_slash( $postarr ), true );
				++$added;
			}
			if ( ! is_wp_error( $id ) && '' !== $cat ) {
				wp_set_object_terms( (int) $id, array( $cat ), KnowledgeBase::TAXONOMY );
			}
		}
		return array(
			'added'   => $added,
			'updated' => $updated,
			'skipped' => $skipped,
		);
	}

	public static function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません。' );
		}
		check_admin_referer( 'rcac_export_faq' );

		$posts = get_posts(
			array(
				'post_type'   => KnowledgeBase::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
			)
		);
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="faq-' . wp_date( 'Ymd' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // Excel で文字化けしないよう BOM を付ける
		fputcsv( $out, array( '質問', '回答', 'カテゴリ', '並び順' ), ',', '"', '' );
		foreach ( $posts as $p ) {
			$terms = get_the_terms( $p, KnowledgeBase::TAXONOMY );
			fputcsv(
				$out,
				array(
					$p->post_title,
					wp_strip_all_tags( $p->post_content ),
					is_array( $terms ) && $terms ? $terms[0]->name : '',
					(string) $p->menu_order,
				),
				',',
				'"',
				''
			);
		}
		fclose( $out );
		exit;
	}

	private static function redirect_error( string $url, string $message ): void {
		wp_safe_redirect( add_query_arg( 'rcac_error', rawurlencode( $message ), $url ) );
		exit;
	}
}
