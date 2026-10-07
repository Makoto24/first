<?php
namespace RCAC\Claude;

use Http\Discovery\Strategy\DiscoveryStrategy as BaseStrategy;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;

/**
 * SDK のクライアント生成時に HTTP クライアントの自動検出が必ず成功するようにする戦略。
 * ClientFactory が生成の間だけ一時的に登録し、終わったら元に戻す。
 */
final class DiscoveryStrategy implements BaseStrategy {

	public static function getCandidates( $type ) {
		if ( ClientInterface::class === $type ) {
			return array(
				array(
					'class'     => WpHttpClient::class,
					'condition' => WpHttpClient::class,
				),
			);
		}
		if ( str_starts_with( (string) $type, 'Psr\\Http\\Message\\' ) && str_ends_with( (string) $type, 'FactoryInterface' ) ) {
			return array(
				array(
					'class'     => Psr17Factory::class,
					'condition' => Psr17Factory::class,
				),
			);
		}
		return array();
	}
}
