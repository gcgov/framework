<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\models\controllerPagedDataResponse;
use gcgov\framework\interfaces\dbGetResult;
use gcgov\framework\exceptions\controllerException;

#[CoversClass(controllerPagedDataResponse::class)]
final class ControllerPagedDataResponseTest extends TestCase {

	public function testHeadersIncludePagingMetadata(): void {
		$result = $this->buildDbResult( 1, 10, 3, 3 );
		$response = new controllerPagedDataResponse( $result );

		$headerNames = array_map(
			fn( $h ) => $this->getHeaderName( $h ),
			$response->getHeaders()
		);
		$this->assertContains( 'X-Page', $headerNames );
		$this->assertContains( 'X-Count', $headerNames );
		$this->assertContains( 'X-Limit', $headerNames );
		$this->assertContains( 'X-Page-Count', $headerNames );
		$this->assertContains( 'X-Total-Count', $headerNames );
	}

	public function testDataIsPassedThrough(): void {
		$result = $this->buildDbResult( 1, 10, 2, 2, [ 'a', 'b' ] );
		$response = new controllerPagedDataResponse( $result );
		$this->assertSame( [ 'a', 'b' ], $response->getData() );
	}

	public function testThrowsWhenPageOutOfRangeAbove(): void {
		$result = $this->buildDbResult( 99, 10, 5, 5 );
		$this->expectException( controllerException::class );
		new controllerPagedDataResponse( $result );
	}

	public function testThrowsWhenPageZeroAndDocumentsExist(): void {
		$result = $this->buildDbResult( 0, 10, 5, 5 );
		$this->expectException( controllerException::class );
		new controllerPagedDataResponse( $result );
	}

	public function testEmptyResultSetIsNotConsideredOutOfRange(): void {
		$result = $this->buildDbResult( 1, 10, 0, 0 );
		$response = new controllerPagedDataResponse( $result );
		$this->assertSame( [], $response->getData() );
	}

	private function buildDbResult( int $page, int $limit, int $count, int $totalDocs, array $data = [] ): dbGetResult {
		return new class( $page, $limit, $count, $totalDocs, $data ) implements dbGetResult {
			public function __construct(
				private int $page,
				private int $limit,
				private int $count,
				private int $totalDocs,
				private array $data
			) {}
			public function setData( array $data ): void { $this->data = $data; }
			public function setLimit( int $limit ): void { $this->limit = $limit; }
			public function setPage( int $page ): void { $this->page = $page; }
			public function getData(): array { return $this->data; }
			public function getLimit(): int { return $this->limit; }
			public function getSkip(): int { return ( $this->page - 1 ) * $this->limit; }
			public function getCount(): int { return $this->count; }
			public function getPage(): int { return $this->page; }
			public function getTotalDocumentCount(): int { return $this->totalDocs; }
			public function setTotalDocumentCount( int $totalDocumentCount ): void { $this->totalDocs = $totalDocumentCount; }
			public function getTotalPageCount(): int {
				return $this->limit > 0 ? (int) ceil( $this->totalDocs / $this->limit ) : 0;
			}
		};
	}

	private function getHeaderName( object $header ): string {
		$prop = new \ReflectionProperty( $header, 'name' );
		return (string) $prop->getValue( $header );
	}

}
