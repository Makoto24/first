<?php
namespace RCAC\Claude;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * WordPress の HTTP API（wp_remote_request）で通信する PSR-18 クライアント。
 *
 * Anthropic SDK の送信部分をこれに差し替えることで、Guzzle などを同梱せずに済み、
 * 他プラグインとのライブラリ衝突を避けられる。WordPress のプロキシ設定もそのまま使われる。
 */
final class WpHttpClient implements ClientInterface {

	private Psr17Factory $factory;

	public function __construct( private float $timeout = 120.0 ) {
		$this->factory = new Psr17Factory();
	}

	public function sendRequest( RequestInterface $request ): ResponseInterface {
		$headers = array();
		foreach ( $request->getHeaders() as $name => $values ) {
			// Host と Content-Length は WordPress（Requests ライブラリ）が付けるので二重にしない。
			if ( in_array( strtolower( (string) $name ), array( 'host', 'content-length' ), true ) ) {
				continue;
			}
			$headers[ $name ] = implode( ', ', $values );
		}

		$result = wp_remote_request(
			(string) $request->getUri(),
			array(
				'method'      => $request->getMethod(),
				'headers'     => $headers,
				'body'        => (string) $request->getBody(),
				'timeout'     => $this->timeout,
				'redirection' => 0,
				'httpversion' => '1.1',
				'sslverify'   => true,
			)
		);

		if ( is_wp_error( $result ) ) {
			throw new NetworkException( $request, $result->get_error_message() );
		}

		$code     = (int) wp_remote_retrieve_response_code( $result );
		$response = $this->factory->createResponse( $code, (string) wp_remote_retrieve_response_message( $result ) );
		foreach ( wp_remote_retrieve_headers( $result ) as $name => $value ) {
			foreach ( (array) $value as $v ) {
				$response = $response->withAddedHeader( (string) $name, (string) $v );
			}
		}
		return $response->withBody( $this->factory->createStream( (string) wp_remote_retrieve_body( $result ) ) );
	}
}
