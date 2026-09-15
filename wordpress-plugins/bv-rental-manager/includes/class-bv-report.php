<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 貸渡実績報告書（年度: 4/1〜翌3/31、長野運輸支局様式ベース）
 * 年度またぎの貸渡は日数比で当年度・翌年度に按分する。
 */
class BV_Report {

	/** 年度集計 */
	public static function aggregate( $fy ) {
		$fy_start = strtotime( $fy . '-04-01 00:00:00' );
		$fy_end   = strtotime( ( $fy + 1 ) . '-04-01 00:00:00' );

		$reservations = BV_DB::get_reservations( array(
			'overlap' => array( date( 'Y-m-d H:i:s', $fy_start ), date( 'Y-m-d H:i:s', $fy_end ) ),
			'exclude_cancelled' => true,
		) );

		$classes = BV_Util::classes();
		$rows = array();
		foreach ( $classes as $k => $c ) {
			$rows[ $k ] = array( 'label' => $c['ja'], 'vehicles' => 0, 'count' => 0, 'days' => 0, 'km' => 0, 'revenue' => 0 );
		}

		/* 区分ごとの車両数（年度末時点で登録済み・未廃車） */
		$vehicles = BV_DB::get_vehicles();
		foreach ( $vehicles as $v ) {
			if ( ! isset( $rows[ $v->class ] ) ) continue;
			$reg = $v->rental_reg_date && '0000-00-00' !== $v->rental_reg_date ? strtotime( $v->rental_reg_date ) : 0;
			$out = $v->disposal_date && '0000-00-00' !== $v->disposal_date ? strtotime( $v->disposal_date ) : 0;
			if ( $reg && $reg >= $fy_end ) continue;
			if ( $out && $out < $fy_start ) continue;
			$rows[ $v->class ]['vehicles']++;
		}

		foreach ( $reservations as $r ) {
			if ( ! isset( $rows[ $r->vehicle_class ] ) ) continue;
			$s = strtotime( $r->pickup_dt );
			$e = strtotime( $r->return_dt );
			$total_sec = max( 1, $e - $s );
			/* 年度内の重なり */
			$ov = min( $e, $fy_end ) - max( $s, $fy_start );
			if ( $ov <= 0 ) continue;
			$ratio = min( 1, $ov / $total_sec );

			$total_days = (int) ceil( $total_sec / DAY_IN_SECONDS );
			$fy_days = (int) round( $total_days * $ratio );
			if ( $fy_days < 1 ) $fy_days = 1;

			/* 回数は貸出日が年度内の場合のみ計上（按分は日車数・キロ・料金） */
			if ( $s >= $fy_start && $s < $fy_end ) $rows[ $r->vehicle_class ]['count']++;
			$rows[ $r->vehicle_class ]['days']    += $fy_days;
			$rows[ $r->vehicle_class ]['km']      += (int) round( (int) $r->trip_distance * $ratio );
			$rows[ $r->vehicle_class ]['revenue'] += (int) round( (int) $r->price_total * $ratio );
		}

		$total = array( 'vehicles' => 0, 'count' => 0, 'days' => 0, 'km' => 0, 'revenue' => 0 );
		foreach ( $rows as $row ) foreach ( $total as $k => $v ) $total[ $k ] += $row[ $k ];

		return array( 'rows' => array_values( $rows ), 'total' => $total );
	}

	/** xlsxダウンロード */
	public static function download_xlsx( $fy ) {
		if ( ! class_exists( 'ZipArchive' ) ) wp_die( 'サーバーにZipArchive拡張が必要です。' );
		$s = BV_Util::settings();
		$data = self::aggregate( $fy );

		$grid = array();
		$grid[] = array( 'レンタカー（貸渡）事業実績報告書' );
		$grid[] = array();
		$grid[] = array( '運輸支局', $s['transport_office'] . '運輸支局 長' );
		$grid[] = array( '報告対象期間', $fy . '年4月1日 〜 ' . ( $fy + 1 ) . '年3月31日' );
		$grid[] = array( '事業者名', $s['company_name'] );
		$grid[] = array( '住所', $s['company_address'] );
		$grid[] = array( '代表者', $s['company_rep'] );
		$grid[] = array( '電話番号', $s['company_tel'] );
		$grid[] = array( '事業所数', (int) $s['office_count'] );
		$grid[] = array();
		$grid[] = array( '車種区分', '車両数', '延貸渡回数', '延貸渡日車数', '延走行キロ', '総貸渡料金（円）' );
		foreach ( $data['rows'] as $row ) {
			$grid[] = array( $row['label'], $row['vehicles'], $row['count'], $row['days'], $row['km'], $row['revenue'] );
		}
		$t = $data['total'];
		$grid[] = array( '合計', $t['vehicles'], $t['count'], $t['days'], $t['km'], $t['revenue'] );
		$grid[] = array();
		$grid[] = array( '備考', '年度をまたぐ貸渡は当年度分・翌年度分に日数按分して計上。' );

		$file = self::build_xlsx( $grid, '貸渡実績報告書' );
		if ( ! $file ) wp_die( 'Excelファイルの生成に失敗しました。' );
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="rental-report-FY' . $fy . '.xlsx"' );
		header( 'Content-Length: ' . filesize( $file ) );
		readfile( $file );
		unlink( $file );
		exit;
	}

	/** 最小構成のxlsx生成（外部ライブラリ不要） */
	protected static function build_xlsx( $grid, $sheet_name = 'Sheet1' ) {
		$tmp = wp_tempnam( 'bvrm-xlsx' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) return false;

		$zip->addFromString( '[Content_Types].xml',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
			'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
			'<Default Extension="xml" ContentType="application/xml"/>' .
			'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
			'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
			'</Types>' );

		$zip->addFromString( '_rels/.rels',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
			'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
			'</Relationships>' );

		$zip->addFromString( 'xl/workbook.xml',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
			'<sheets><sheet name="' . htmlspecialchars( $sheet_name, ENT_XML1 ) . '" sheetId="1" r:id="rId1"/></sheets></workbook>' );

		$zip->addFromString( 'xl/_rels/workbook.xml.rels',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
			'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
			'</Relationships>' );

		$rows_xml = '';
		$rn = 0;
		foreach ( $grid as $row ) {
			$rn++;
			$cells = '';
			$cn = 0;
			foreach ( $row as $cell ) {
				$ref = self::col_letter( $cn ) . $rn;
				$cn++;
				if ( is_int( $cell ) || is_float( $cell ) || ( is_string( $cell ) && '' !== $cell && preg_match( '/^-?\d+$/', $cell ) ) ) {
					$cells .= '<c r="' . $ref . '"><v>' . $cell . '</v></c>';
				} elseif ( '' !== (string) $cell ) {
					$cells .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars( (string) $cell, ENT_XML1, 'UTF-8' ) . '</t></is></c>';
				}
			}
			$rows_xml .= '<row r="' . $rn . '">' . $cells . '</row>';
		}

		$zip->addFromString( 'xl/worksheets/sheet1.xml',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
			'<cols><col min="1" max="1" width="22" customWidth="1"/><col min="2" max="6" width="16" customWidth="1"/></cols>' .
			'<sheetData>' . $rows_xml . '</sheetData></worksheet>' );

		$zip->close();
		return $tmp;
	}

	protected static function col_letter( $n ) {
		$letters = '';
		while ( $n >= 0 ) {
			$letters = chr( 65 + ( $n % 26 ) ) . $letters;
			$n = (int) floor( $n / 26 ) - 1;
		}
		return $letters;
	}
}
