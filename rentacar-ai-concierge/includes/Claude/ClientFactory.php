<?php
namespace RCAC\Claude;

use Anthropic\Client;
use Anthropic\RequestOptions;
use Http\Discovery\ClassDiscovery;
use Nyholm\Psr7\Factory\Psr17Factory;
use RCAC\Plugin;
use RCAC\Settings;

/**
 * Anthropic SDK のクライアントを生成する。
 */
final class ClientFactory {

	public static function create(): Client {
		if ( ! Plugin::load_vendor() ) {
			throw new \RuntimeException( 'vendor ディレクトリがありません。配布用 zip からインストールするか、プラグインのフォルダで composer install を実行してください。' );
		}
		$api_key = Settings::api_key();
		if ( '' === $api_key ) {
			throw new \RuntimeException( 'Claude API キーが設定されていません。' );
		}

		$factory     = new Psr17Factory();
		$transporter = new WpHttpClient( (float) apply_filters( 'rcac_http_timeout', 120 ) );
		$base_url    = defined( 'RCAC_ANTHROPIC_BASE_URL' ) ? (string) RCAC_ANTHROPIC_BASE_URL : 'https://api.anthropic.com';

		// SDK はコンストラクタ内で HTTP クライアントを自動検出するため、生成の間だけ自前の候補を先頭に入れる。
		// グローバルな検出設定を他プラグインに残さないよう、生成後は元に戻す。
		$saved = ClassDiscovery::getStrategies();
		ClassDiscovery::prependStrategy( DiscoveryStrategy::class );
		try {
			return new Client(
				apiKey: $api_key,
				baseUrl: $base_url,
				requestOptions: RequestOptions::with(
					maxRetries: 2,
					transporter: $transporter,
					uriFactory: $factory,
					streamFactory: $factory,
					requestFactory: $factory,
				),
			);
		} finally {
			ClassDiscovery::setStrategies( is_array( $saved ) ? $saved : iterator_to_array( $saved ) );
		}
	}
}
