<?php
namespace RCAC;

/**
 * Q&A 集（カスタム投稿タイプ rcac_faq）と、ボットに渡す知識テキストの組み立て。
 */
final class KnowledgeBase {

	public const POST_TYPE = 'rcac_faq';
	public const TAXONOMY  = 'rcac_faq_cat';

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => 'Q&A集',
					'singular_name' => 'Q&A',
					'add_new'       => 'Q&Aを追加',
					'add_new_item'  => 'Q&Aを追加',
					'edit_item'     => 'Q&Aを編集',
					'all_items'     => 'Q&A集',
					'search_items'  => 'Q&Aを検索',
					'not_found'     => 'Q&Aはまだありません。CSV取り込みから一括登録できます。',
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'rcac',
				'show_in_rest' => false,
				'supports'     => array( 'title', 'editor', 'page-attributes' ),
				'map_meta_cap' => true,
			)
		);
		register_taxonomy(
			self::TAXONOMY,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => 'Q&Aカテゴリ',
					'singular_name' => 'カテゴリ',
				),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'hierarchical'      => true,
				'show_in_rest'      => false,
			)
		);
	}

	public static function init_admin(): void {
		add_filter( 'enter_title_here', array( self::class, 'title_placeholder' ), 10, 2 );
		add_action( 'edit_form_after_title', array( self::class, 'answer_label' ) );
	}

	public static function title_placeholder( string $text, \WP_Post $post ): string {
		return self::POST_TYPE === $post->post_type ? '質問（例: 免許証は何が必要ですか？）' : $text;
	}

	public static function answer_label( \WP_Post $post ): void {
		if ( self::POST_TYPE === $post->post_type ) {
			echo '<h2 style="padding:16px 0 4px;">回答</h2>';
		}
	}

	/**
	 * 公開中の Q&A をすべて取得する（並び順・ID順で固定し、プロンプトキャッシュが効くようにする）。
	 *
	 * @return list<array{question:string,answer:string,category:string}>
	 */
	public static function entries(): array {
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'orderby'          => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'suppress_filters' => true,
			)
		);
		$out   = array();
		foreach ( $posts as $post ) {
			$terms = get_the_terms( $post, self::TAXONOMY );
			$out[] = array(
				'question' => trim( $post->post_title ),
				'answer'   => trim( wp_strip_all_tags( $post->post_content ) ),
				'category' => is_array( $terms ) && $terms ? $terms[0]->name : '',
			);
		}
		return $out;
	}

	/** ボットに渡す Q&A テキスト。 */
	public static function faq_text(): string {
		$groups = array();
		foreach ( self::entries() as $e ) {
			$groups[ '' !== $e['category'] ? $e['category'] : 'その他' ][] = "Q: {$e['question']}\nA: {$e['answer']}";
		}
		$out = array();
		foreach ( $groups as $cat => $items ) {
			$out[] = "## {$cat}\n" . implode( "\n\n", $items );
		}
		return implode( "\n\n", $out );
	}
}
