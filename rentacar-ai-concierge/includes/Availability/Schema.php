<?php
namespace RCAC\Availability;

/**
 * DB のテーブル・列の確認。管理画面で入力されたテーブル名・列名は必ずここで実在を確認してから SQL に使う。
 */
final class Schema {

	/** @var array<string,array<string,string>> */
	private static array $columns = array();

	/** @var list<string>|null */
	private static ?array $tables = null;

	/**
	 * @return list<string>
	 */
	public static function tables(): array {
		global $wpdb;
		if ( null === self::$tables ) {
			$rows         = $wpdb->get_col( 'SHOW TABLES' );
			self::$tables = array_values( array_map( 'strval', $rows ? $rows : array() ) );
			sort( self::$tables );
		}
		return self::$tables;
	}

	/**
	 * 入力されたテーブル名を実在するテーブル名に解決する（接頭辞の省略も許可）。
	 */
	public static function resolve_table( string $name ): ?string {
		global $wpdb;
		$name = trim( $name );
		if ( '' === $name ) {
			return null;
		}
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			foreach ( array( $name, $wpdb->prefix . $name ) as $candidate ) {
				if ( in_array( $candidate, self::tables(), true ) ) {
					return $candidate;
				}
			}
			self::$tables = null; // 同じリクエスト内で作られたテーブルにも対応するため一度だけ取り直す
		}
		return null;
	}

	/**
	 * @return array<string,string> 列名 => 型
	 */
	public static function columns( string $table ): array {
		global $wpdb;
		if ( ! in_array( $table, self::tables(), true ) ) {
			return array();
		}
		if ( ! isset( self::$columns[ $table ] ) ) {
			$cols = array();
			$rows = $wpdb->get_results( 'SHOW COLUMNS FROM ' . self::quote( $table ), ARRAY_A );
			foreach ( $rows ? $rows : array() as $row ) {
				$cols[ (string) $row['Field'] ] = strtolower( (string) $row['Type'] );
			}
			self::$columns[ $table ] = $cols;
		}
		return self::$columns[ $table ];
	}

	/**
	 * 列が実在すれば列名を返す。
	 */
	public static function resolve_column( string $table, string $column ): ?string {
		$column = trim( $column );
		if ( '' === $column ) {
			return null;
		}
		$cols = self::columns( $table );
		if ( isset( $cols[ $column ] ) ) {
			return $column;
		}
		foreach ( array_keys( $cols ) as $c ) {
			if ( 0 === strcasecmp( $c, $column ) ) {
				return $c;
			}
		}
		return null;
	}

	/** 列の型から日時の保存形式を推定する: datetime / unix / text */
	public static function date_kind( string $table, string $column ): string {
		$type = self::columns( $table )[ $column ] ?? '';
		if ( preg_match( '/^(datetime|timestamp|date)/', $type ) ) {
			return 'datetime';
		}
		if ( preg_match( '/^(int|bigint|integer|mediumint)/', $type ) ) {
			return 'unix';
		}
		return 'text';
	}

	/** 実在確認済みの識別子をバッククォートで囲む。 */
	public static function quote( string $identifier ): string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}
}
