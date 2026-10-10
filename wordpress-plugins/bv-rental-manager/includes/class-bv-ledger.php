<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 貸渡簿（法令で保存が義務付けられた記録）
 *
 * 記載事項
 *   借受人の氏名・住所／運転者の氏名・住所／運転免許の種類・免許証番号／
 *   貸渡車両の登録番号／貸渡日時・時間／貸渡事務所・返還事務所／走行キロ数／
 *   貸渡料金／事故に関する事項
 *
 * 記録は予約データに持ち、免許証の画像とは別に保存する（画像は返却・キャンセル後に消去しても、
 * 読み取った種類・番号は残る）。保存期間中は、貸渡簿にあたる予約を削除できないようにしている。
 */
class BV_Ledger {

	/** 貸渡簿の対象（実際に貸し渡したもの） */
	const STATUSES = array( 'in_use', 'returned' );

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'handle_csv' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_admin_read' ) );
	}

	/** 保存年数（設定。既定2年） */
	public static function years() {
		return max( 1, (int) ( BV_Util::settings()['ledger_years'] ?? 2 ) );
	}

	/** この予約は貸渡簿として保存期間中か（削除できない） */
	public static function is_protected( $r ) {
		if ( ! $r || ! in_array( (string) $r->status, self::STATUSES, true ) ) return false;
		$base = $r->returned_at ?: $r->return_dt;
		return strtotime( (string) $base ) > current_time( 'timestamp' ) - self::years() * YEAR_IN_SECONDS;
	}

	/* ---------- 記載事項 ---------- */

	public static function accident_state( $r ) {
		if ( '' !== trim( (string) $r->accident_note ) ) return 'yes';
		if ( ! empty( $r->no_accident ) ) return 'none';
		return '';
	}

	/** 1件分の記載事項（画面・CSV共通） */
	public static function row( $r ) {
		$v = $r->vehicle_id ? BV_DB::get_vehicle( $r->vehicle_id ) : null;
		$renter = trim( $r->sei . ' ' . $r->mei );
		$same = ! empty( $r->driver_same ) || '' === trim( (string) $r->driver_name );
		$start = (string) $r->pickup_dt;
		$end   = (string) ( $r->returned_at ?: $r->return_dt );
		$hours = max( 0, ( strtotime( $end ) - strtotime( $start ) ) / HOUR_IN_SECONDS );
		$acc = self::accident_state( $r );
		$ret_office = $r->return_location ? BV_Util::label( BV_Util::locations(), $r->return_location ) : '';
		return array(
			'code'            => $r->code,
			'status'          => $r->status,
			'renter_name'     => $renter,
			'renter_address'  => (string) $r->address,
			'driver_name'     => $same ? $renter : (string) $r->driver_name,
			'driver_address'  => $same ? (string) $r->address : (string) $r->driver_address,
			'driver_same'     => $same,
			'license_type'    => (string) $r->license_type,
			'license_number'  => (string) $r->license_number,
			'license_expiry'  => (string) $r->license_expiry,
			'license_source'  => (string) $r->license_source,
			'license_note'    => (string) $r->license_read_note,
			'plate'           => $v ? (string) $v->plate : '',
			'vehicle'         => $v ? (string) $v->name : '',
			'start'           => $start ? date( 'Y-m-d H:i', strtotime( $start ) ) : '',
			'end'             => $end ? date( 'Y-m-d H:i', strtotime( $end ) ) : '',
			'end_actual'      => ! empty( $r->returned_at ),
			'duration'        => self::duration_text( $hours ),
			'office'          => BV_Util::label( BV_Util::stores(), $r->store ),
			'return_office'   => $ret_office,
			'odo_out'         => (int) $r->pickup_odometer,
			'odo_in'          => (int) $r->return_odometer,
			'km'              => (int) $r->trip_distance,
			'price'           => (int) $r->price_total,
			'refund'          => (int) $r->refund_amount,
			'accident'        => 'yes' === $acc ? 'あり' : ( 'none' === $acc ? 'なし' : '' ),
			'accident_note'   => (string) $r->accident_note,
		);
	}

	public static function duration_text( $hours ) {
		$h = (int) round( $hours );
		$d = intdiv( $h, 24 );
		$rest = $h % 24;
		return ( $d ? $d . '日' : '' ) . ( $rest || ! $d ? $rest . '時間' : '' );
	}

	/** 足りない記載事項（返却済みの予約で確認） */
	public static function missing( $r ) {
		$row = self::row( $r );
		$m = array();
		if ( '' === trim( $row['renter_address'] ) ) $m[] = '借受人の住所';
		if ( ! $row['driver_same'] && ( '' === trim( $row['driver_name'] ) || '' === trim( $row['driver_address'] ) ) ) $m[] = '運転者の氏名・住所';
		if ( '' === $row['license_number'] ) $m[] = '免許証番号';
		if ( '' === $row['license_type'] ) $m[] = '免許の種類';
		if ( '' === $row['plate'] ) $m[] = '登録番号';
		if ( 'returned' === $r->status ) {
			if ( ! $row['km'] ) $m[] = '走行キロ';
			if ( '' === $row['accident'] ) $m[] = '事故の有無';
		}
		return $m;
	}

	/* ---------- 入力（管理画面・スタッフポータル共通） ---------- */

	/**
	 * 運転者・免許・事故・貸出時メーターを保存する
	 * @param string $by admin / staff
	 * @return array { ok, msg }
	 */
	public static function save( $r, $P, $by = 'admin' ) {
		$upd = array();
		$upd['driver_same'] = empty( $P['ledger_driver_other'] ) ? 1 : 0;
		$upd['driver_name']    = $upd['driver_same'] ? '' : sanitize_text_field( (string) ( $P['ledger_driver_name'] ?? '' ) );
		$upd['driver_address'] = $upd['driver_same'] ? '' : sanitize_textarea_field( (string) ( $P['ledger_driver_address'] ?? '' ) );
		if ( ! $upd['driver_same'] && ( '' === $upd['driver_name'] || '' === $upd['driver_address'] ) ) {
			return array( 'ok' => false, 'msg' => '運転者が借受人と違う場合は、運転者の氏名と住所を入力してください。' );
		}

		$num = strtoupper( preg_replace( '/[^0-9A-Za-z]/', '', mb_convert_kana( (string) ( $P['ledger_license_number'] ?? '' ), 'a', 'UTF-8' ) ) );
		$type = sanitize_text_field( (string) ( $P['ledger_license_type'] ?? '' ) );
		$exp = sanitize_text_field( (string) ( $P['ledger_license_expiry'] ?? '' ) );
		$exp = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $exp ) ? $exp : null;
		if ( $num !== (string) $r->license_number || $type !== (string) $r->license_type || (string) $exp !== (string) $r->license_expiry ) {
			$upd['license_number'] = mb_substr( $num, 0, 40, 'UTF-8' );
			$upd['license_type']   = mb_substr( $type, 0, 160, 'UTF-8' );
			$upd['license_expiry'] = $exp;
			/* 手で直した値は、あとの自動読み取りで上書きしない */
			$upd['license_source'] = 'manual';
			if ( ! $r->license_read_at ) $upd['license_read_at'] = current_time( 'mysql' );
		}

		$acc = (string) ( $P['ledger_accident'] ?? '' );
		$note = sanitize_textarea_field( (string) ( $P['ledger_accident_note'] ?? '' ) );
		if ( 'yes' === $acc ) {
			if ( '' === trim( $note ) ) return array( 'ok' => false, 'msg' => '事故ありの場合は、事故の内容を入力してください。' );
			$upd['accident_note'] = $note;
			$upd['no_accident'] = 0;
		} elseif ( 'none' === $acc ) {
			$upd['accident_note'] = '';
			$upd['no_accident'] = 1;
		}

		if ( isset( $P['ledger_pickup_odometer'] ) && '' !== trim( (string) $P['ledger_pickup_odometer'] ) ) {
			$out = max( 0, (int) $P['ledger_pickup_odometer'] );
			$upd['pickup_odometer'] = $out;
			/* 返却メーターがあれば走行キロも直す */
			if ( (int) $r->return_odometer > $out && $out > 0 ) $upd['trip_distance'] = (int) $r->return_odometer - $out;
		}

		$changed = false;
		foreach ( $upd as $k => $v ) {
			if ( (string) $v !== (string) ( $r->$k ?? '' ) ) { $changed = true; break; }
		}
		if ( ! $changed ) return array( 'ok' => true, 'msg' => '貸渡簿の記載事項に変更はありません。' );
		BV_DB::update_reservation( $r->id, $upd );
		$who = 'staff' === $by ? 'スタッフポータル' : '管理画面';
		$cur = BV_DB::get_reservation( $r->id );
		BV_DB::update_reservation( $r->id, array( 'admin_memo' => trim( (string) $cur->admin_memo . "\n[貸渡簿の記載事項を更新 " . current_time( 'Y-m-d H:i' ) . '（' . $who . '）]' ) ) );
		return array( 'ok' => true, 'msg' => '貸渡簿の記載事項を保存しました。' );
	}

	/**
	 * 入力欄（form タグは呼び出し側で用意する）
	 */
	public static function fields_html( $r ) {
		$row = self::row( $r );
		$h  = '';
		$h .= '<div class="bvrm-ledger">';
		/* 免許 */
		$src = array( 'ocr' => '自動読み取り', 'manual' => '手入力', 'failed' => '読み取り不可' );
		$st = $r->license_read_at
			? ( $src[ $r->license_source ] ?? '' ) . '（' . date( 'n/j H:i', strtotime( $r->license_read_at ) ) . '）'
			: ( $r->license_files && '[]' !== $r->license_files ? '未読み取り（自動で読み取ります）' : '書類なし' );
		$h .= '<p style="margin:0 0 6px"><strong>運転免許</strong> <span style="font-size:12px;color:#646970">' . esc_html( $st ) . '</span></p>';
		if ( '' !== $row['license_note'] ) {
			$h .= '<p style="margin:0 0 8px;padding:6px 10px;background:#fcf9e8;border-left:3px solid #dba617;font-size:13px">確認事項：' . esc_html( $row['license_note'] ) . '</p>';
		}
		$h .= '<label style="display:block;font-size:13px">免許証番号</label><input type="text" name="ledger_license_number" value="' . esc_attr( $row['license_number'] ) . '" inputmode="numeric" autocomplete="off" style="width:100%;max-width:320px">';
		$h .= '<label style="display:block;font-size:13px;margin-top:6px">免許の種類</label><input type="text" name="ledger_license_type" value="' . esc_attr( $row['license_type'] ) . '" placeholder="例：普通・普自二／条件：眼鏡等" style="width:100%;max-width:420px">';
		$h .= '<label style="display:block;font-size:13px;margin-top:6px">有効期限</label><input type="date" name="ledger_license_expiry" value="' . esc_attr( $row['license_expiry'] ) . '">';

		/* 運転者 */
		$h .= '<p style="margin:12px 0 6px"><strong>運転者</strong></p>';
		$h .= '<label style="display:block;font-weight:normal"><input type="checkbox" name="ledger_driver_other" value="1"' . ( $row['driver_same'] ? '' : ' checked' ) . ' onchange="this.closest(\'.bvrm-ledger\').querySelector(\'.bvrm-driver\').style.display=this.checked?\'block\':\'none\'"> 運転者が借受人と違う</label>';
		$h .= '<div class="bvrm-driver" style="display:' . ( $row['driver_same'] ? 'none' : 'block' ) . '">';
		$h .= '<label style="display:block;font-size:13px">運転者の氏名</label><input type="text" name="ledger_driver_name" value="' . esc_attr( $row['driver_same'] ? '' : $row['driver_name'] ) . '" style="width:100%;max-width:320px">';
		$h .= '<label style="display:block;font-size:13px;margin-top:6px">運転者の住所</label><textarea name="ledger_driver_address" rows="2" style="width:100%;max-width:520px">' . esc_textarea( $row['driver_same'] ? '' : $row['driver_address'] ) . '</textarea>';
		$h .= '</div>';

		/* 事故・メーター */
		$acc = self::accident_state( $r );
		$h .= '<p style="margin:12px 0 6px"><strong>事故に関する事項</strong></p>';
		$h .= '<label style="font-weight:normal;margin-right:14px"><input type="radio" name="ledger_accident" value="none"' . checked( $acc, 'none', false ) . '> 事故なし</label>';
		$h .= '<label style="font-weight:normal"><input type="radio" name="ledger_accident" value="yes"' . checked( $acc, 'yes', false ) . '> 事故あり</label>';
		$h .= '<textarea name="ledger_accident_note" rows="2" placeholder="事故ありの場合：日時・場所・内容・相手方・警察への届出など" style="width:100%;max-width:520px;margin-top:6px">' . esc_textarea( $row['accident_note'] ) . '</textarea>';
		$h .= '<label style="display:block;font-size:13px;margin-top:8px">貸出時メーター（km）</label><input type="number" name="ledger_pickup_odometer" min="0" value="' . ( $row['odo_out'] ? (int) $row['odo_out'] : '' ) . '" style="width:140px">';
		if ( $row['odo_in'] ) $h .= ' <span style="font-size:13px;color:#646970">返却時 ' . number_format( $row['odo_in'] ) . ' km／走行 ' . number_format( $row['km'] ) . ' km</span>';
		$h .= '</div>';
		return $h;
	}

	/* ---------- 管理画面：貸渡簿の一覧・CSV ---------- */

	protected static function query( $month, $store ) {
		global $wpdb;
		$t = BV_DB::table( 'reservations' );
		$from = $month . '-01 00:00:00';
		$to   = date( 'Y-m-d H:i:s', strtotime( $from . ' +1 month' ) );
		$sql = "SELECT * FROM {$t} WHERE status IN ('in_use','returned') AND pickup_dt >= %s AND pickup_dt < %s";
		$args = array( $from, $to );
		if ( $store && isset( BV_Util::stores()[ $store ] ) ) { $sql .= ' AND store = %s'; $args[] = $store; }
		$sql .= ' ORDER BY pickup_dt ASC, id ASC';
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
	}

	protected static function filters() {
		$month = isset( $_GET['month'] ) && preg_match( '/^\d{4}-\d{2}$/', (string) $_GET['month'] ) ? (string) $_GET['month'] : date( 'Y-m', current_time( 'timestamp' ) );
		$store = isset( $_GET['store'] ) ? sanitize_key( wp_unslash( $_GET['store'] ) ) : '';
		return array( $month, $store );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		list( $month, $store ) = self::filters();
		$rows = self::query( $month, $store );
		echo '<div class="wrap"><h1>貸渡簿</h1>';
		echo '<p class="description">法令で保存が義務付けられた記載事項の一覧です（貸出・返却済みの予約）。保存期間は ' . (int) self::years() . ' 年で、期間中の予約は削除できません。'
			. '免許の種類・番号は、お客様がアップロードした免許証から自動で読み取ります。読み取れなかったもの・足りない項目は赤で表示しますので、予約詳細またはスタッフポータルで補ってください。</p>';
		echo '<form method="get" style="margin:12px 0"><input type="hidden" name="page" value="bvrm-ledger">';
		echo '<input type="month" name="month" value="' . esc_attr( $month ) . '"> <select name="store"><option value="">全店舗</option>';
		foreach ( BV_Util::stores() as $k => $st ) echo '<option value="' . esc_attr( $k ) . '"' . selected( $store, $k, false ) . '>' . esc_html( $st['ja'] ) . '</option>';
		echo '</select> <button class="button">表示</button> ';
		$csv = wp_nonce_url( add_query_arg( array( 'page' => 'bvrm-ledger', 'month' => $month, 'store' => $store, 'bvrm_ledger_csv' => 1 ), admin_url( 'admin.php' ) ), 'bvrm_ledger_csv' );
		echo '<a class="button button-primary" href="' . esc_url( $csv ) . '">CSVで出力（Excel対応）</a></form>';

		$pending = BV_License_Reader::pending_count();
		if ( $pending && BV_License_Reader::enabled() ) echo '<div class="notice notice-info inline"><p>免許証の自動読み取り待ち：' . (int) $pending . '件（15分ごとに少しずつ読み取ります。設定画面からすぐに読み取ることもできます）</p></div>';
		if ( ! BV_License_Reader::enabled() ) echo '<div class="notice notice-warning inline"><p>免許証の自動読み取りが設定されていません。「設定 → 本人確認書類」でClaude APIキーを入力してください。</p></div>';

		if ( ! $rows ) { echo '<p>この月の貸渡はありません。</p></div>'; return; }
		$miss_n = 0;
		echo '<table class="widefat striped" style="font-size:12px"><thead><tr>'
			. '<th>貸渡日時／返還日時</th><th>借受人（氏名・住所）</th><th>運転者</th><th>免許の種類・番号</th><th>登録番号</th><th>貸渡／返還事務所</th><th>走行</th><th>料金</th><th>事故</th><th>不足</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$x = self::row( $r );
			$miss = self::missing( $r );
			if ( $miss ) $miss_n++;
			$edit = admin_url( 'admin.php?page=bvrm-reservations&edit=' . (int) $r->id );
			echo '<tr><td><a href="' . esc_url( $edit ) . '">' . esc_html( $x['code'] ) . '</a><br>' . esc_html( $x['start'] ) . '<br>〜' . esc_html( $x['end'] ) . ( $x['end_actual'] ? '' : '（予定）' ) . '<br>' . esc_html( $x['duration'] ) . '</td>'
				. '<td>' . esc_html( $x['renter_name'] ) . '<br><span style="color:#646970">' . esc_html( $x['renter_address'] ) . '</span></td>'
				. '<td>' . ( $x['driver_same'] ? '借受人と同じ' : esc_html( $x['driver_name'] ) . '<br><span style="color:#646970">' . esc_html( $x['driver_address'] ) . '</span>' ) . '</td>'
				. '<td>' . esc_html( $x['license_type'] ) . '<br>' . esc_html( $x['license_number'] ? BV_License_Reader::mask( $x['license_number'] ) : '' )
				. ( $x['license_note'] ? '<br><span style="color:#b26200">' . esc_html( $x['license_note'] ) . '</span>' : '' ) . '</td>'
				. '<td>' . esc_html( $x['plate'] ) . '<br><span style="color:#646970">' . esc_html( $x['vehicle'] ) . '</span></td>'
				. '<td>' . esc_html( $x['office'] ) . '<br>' . esc_html( $x['return_office'] ) . '</td>'
				. '<td>' . ( $x['km'] ? number_format( $x['km'] ) . ' km' : '' ) . '</td>'
				. '<td>' . esc_html( BV_Util::money( $x['price'] ) ) . ( $x['refund'] ? '<br>返金 ' . esc_html( BV_Util::money( $x['refund'] ) ) : '' ) . '</td>'
				. '<td>' . esc_html( $x['accident'] ) . ( $x['accident_note'] ? '<br>' . esc_html( $x['accident_note'] ) : '' ) . '</td>'
				. '<td style="color:#b32d2e">' . esc_html( implode( '、', $miss ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p>' . count( $rows ) . '件' . ( $miss_n ? '（うち記載事項が足りないもの <strong style="color:#b32d2e">' . (int) $miss_n . '件</strong>）' : '' ) . '。一覧では免許証番号の一部を伏せています（CSVには全桁を出力します）。</p></div>';
	}

	/** CSV出力（Excelで文字化けしないよう UTF-8 BOM 付き） */
	public static function handle_csv() {
		if ( empty( $_GET['bvrm_ledger_csv'] ) || ! current_user_can( 'manage_options' ) ) return;
		check_admin_referer( 'bvrm_ledger_csv' );
		list( $month, $store ) = self::filters();
		$rows = self::query( $month, $store );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="kashiwatashibo-' . $month . ( $store ? '-' . $store : '' ) . '.csv"' );
		echo "\xEF\xBB\xBF";
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, self::csv_header() );
		foreach ( $rows as $r ) fputcsv( $out, self::csv_line( $r ) );
		fclose( $out );
		exit;
	}

	public static function csv_header() {
		return array( '予約番号', '貸渡日時', '返還日時', '貸渡時間', '借受人氏名', '借受人住所', '運転者氏名', '運転者住所',
			'運転免許の種類', '免許証番号', '免許の有効期限', '登録番号', '車名', '貸渡事務所', '返還事務所',
			'貸出時メーター', '返却時メーター', '走行キロ数', '貸渡料金', '返金額', '事故の有無', '事故の内容', '不足している記載事項', '免許の確認事項' );
	}

	public static function csv_line( $r ) {
		$x = self::row( $r );
		return array( $x['code'], $x['start'], $x['end'] . ( $x['end_actual'] ? '' : '（予定）' ), $x['duration'],
			$x['renter_name'], $x['renter_address'], $x['driver_name'], $x['driver_address'],
			$x['license_type'], $x['license_number'] !== '' ? "\t" . $x['license_number'] : '', $x['license_expiry'],
			$x['plate'], $x['vehicle'], $x['office'], $x['return_office'],
			$x['odo_out'] ?: '', $x['odo_in'] ?: '', $x['km'], $x['price'], $x['refund'],
			$x['accident'], $x['accident_note'], implode( '、', self::missing( $r ) ), $x['license_note'] );
	}

	/** 管理画面：予約詳細の「免許証を読み取り直す」 */
	public static function handle_admin_read() {
		if ( empty( $_GET['bvrm_read_license'] ) || ! current_user_can( 'manage_options' ) ) return;
		$id = (int) $_GET['bvrm_read_license'];
		check_admin_referer( 'bvrm_read_license_' . $id );
		$r = BV_DB::get_reservation( $id );
		if ( ! $r ) return;
		/* 手で直した値も読み直したいときのため、手入力の印を外してから読む */
		BV_DB::update_reservation( $id, array( 'license_source' => '', 'license_read_at' => null ) );
		$res = BV_License_Reader::read_reservation( $id );
		set_transient( 'bvrm_notice', $res['msg'], 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=bvrm-reservations&edit=' . $id ) . '#bvrm-ledger' );
		exit;
	}
}
