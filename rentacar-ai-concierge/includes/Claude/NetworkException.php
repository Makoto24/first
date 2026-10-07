<?php
namespace RCAC\Claude;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * 接続失敗（DNS・TLS・タイムアウトなど）。SDK はこれを受けて自動で再試行する。
 */
final class NetworkException extends \RuntimeException implements NetworkExceptionInterface {

	public function __construct( private RequestInterface $request, string $message ) {
		parent::__construct( $message );
	}

	public function getRequest(): RequestInterface {
		return $this->request;
	}
}
