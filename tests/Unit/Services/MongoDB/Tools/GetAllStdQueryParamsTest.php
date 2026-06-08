<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\MongoDB\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\services\mongodb\tools\getAllStdQueryParams;

#[CoversClass(getAllStdQueryParams::class)]
final class GetAllStdQueryParamsTest extends TestCase {

	protected function setUp(): void {
		unset( $_GET[ 'limit' ], $_GET[ 'page' ], $_GET[ 'sortBy' ] );
	}

	public function testDefaultsAreLimit10Page1NoSort(): void {
		$params = new getAllStdQueryParams();
		$this->assertSame( 10, $params->limit );
		$this->assertSame( 1, $params->page );
		$this->assertSame( [], $params->sort );
	}

	public function testConstructorAcceptsCustomDefaults(): void {
		$params = new getAllStdQueryParams( 25, 4 );
		$this->assertSame( 25, $params->limit );
		$this->assertSame( 4, $params->page );
	}

	public function testGetUsesQueryStringWhenPresent(): void {
		$_GET[ 'limit' ] = 50;
		$_GET[ 'page' ] = 3;
		$params = getAllStdQueryParams::get();
		$this->assertSame( 50, $params->limit );
		$this->assertSame( 3, $params->page );
	}

	public function testGetFallsBackToDefaultsWhenNoQueryString(): void {
		$params = getAllStdQueryParams::get( 30, 2 );
		$this->assertSame( 30, $params->limit );
		$this->assertSame( 2, $params->page );
	}

	public function testGetParsesSortByAscDirection(): void {
		$_GET[ 'sortBy' ] = [ 'name|asc' ];
		$params = getAllStdQueryParams::get();
		$this->assertSame( [ 'name' => 1 ], $params->sort );
	}

	public function testGetParsesSortByDescDirection(): void {
		$_GET[ 'sortBy' ] = [ 'name|desc' ];
		$params = getAllStdQueryParams::get();
		$this->assertSame( [ 'name' => -1 ], $params->sort );
	}

	public function testGetIgnoresSortByWhenNotArray(): void {
		$_GET[ 'sortBy' ] = 'name|asc';
		$params = getAllStdQueryParams::get();
		$this->assertSame( [], $params->sort );
	}

	public function testGetParsesMultipleSortFields(): void {
		$_GET[ 'sortBy' ] = [ 'name|asc', 'createdAt|desc' ];
		$params = getAllStdQueryParams::get();
		$this->assertSame( [ 'name' => 1, 'createdAt' => -1 ], $params->sort );
	}

}
