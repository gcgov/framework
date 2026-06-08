<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\http;

#[CoversClass(http::class)]
final class HttpTest extends TestCase {

	#[DataProvider('knownStatusCodes')]
	public function testStatusTextReturnsHumanString( int $code, string $expected ): void {
		$this->assertSame( $expected, http::statusText( $code ) );
	}

	public function testStatusTextUnknownCodeReturnsUnknownText(): void {
		$result = http::statusText( 999 );
		$this->assertStringContainsString( 'Unknown', $result );
		$this->assertStringContainsString( '999', $result );
	}

	public function testStatusTextDefaultArgumentFallsBackToCurrentResponseCode(): void {
		// http_response_code() defaults to 200 in CLI context.
		$result = http::statusText();
		$this->assertNotEmpty( $result );
	}

	public function testStatusTextEscapesHtmlInUnknownCode(): void {
		// The match-default branch htmlentities-escapes the code. 999 has no
		// special chars but the call path covers the htmlentities branch.
		$result = http::statusText( 9999 );
		$this->assertStringContainsString( '9999', $result );
	}

	public static function knownStatusCodes(): array {
		return [
			[ 100, 'Continue' ],
			[ 101, 'Switching Protocols' ],
			[ 200, 'OK' ],
			[ 201, 'Created' ],
			[ 202, 'Accepted' ],
			[ 203, 'Non-Authoritative Information' ],
			[ 204, 'No Content' ],
			[ 205, 'Reset Content' ],
			[ 206, 'Partial Content' ],
			[ 300, 'Multiple Choices' ],
			[ 301, 'Moved Permanently' ],
			[ 302, 'Moved Temporarily' ],
			[ 303, 'See Other' ],
			[ 304, 'Not Modified' ],
			[ 305, 'Use Proxy' ],
			[ 400, 'Bad Request' ],
			[ 401, 'Unauthorized' ],
			[ 402, 'Payment Required' ],
			[ 403, 'Forbidden' ],
			[ 404, 'Not Found' ],
			[ 405, 'Method Not Allowed' ],
			[ 406, 'Not Acceptable' ],
			[ 407, 'Proxy Authentication Required' ],
			[ 408, 'Request Time-out' ],
			[ 409, 'Conflict' ],
			[ 410, 'Gone' ],
			[ 411, 'Length Required' ],
			[ 412, 'Precondition Failed' ],
			[ 413, 'Request Entity Too Large' ],
			[ 414, 'Request-URI Too Large' ],
			[ 415, 'Unsupported Media Type' ],
			[ 500, 'Internal Server Error' ],
			[ 501, 'Not Implemented' ],
			[ 502, 'Bad Gateway' ],
			[ 503, 'Service Unavailable' ],
			[ 504, 'Gateway Time-out' ],
			[ 505, 'HTTP Version not supported' ],
		];
	}

}
