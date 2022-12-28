<?php

namespace gcgov\framework\services\mongodb;

class getResult extends \andrewsauder\jsonDeserialize\jsonDeserialize implements \gcgov\framework\interfaces\dbGetResult {

	private array $data;
	private int   $limit;
	private int   $page;
	private int   $totalDocumentCount = -1;


	/**
	 * @param int|string|null $limit
	 * @param int|string|null $page
	 * @param mixed|null      $data
	 */
	public function __construct( int|string|null $limit = 10, int|string|null $page = 1, array $data = [] ) {
		$this->page  = isset( $page ) && is_numeric( $page ) ? (int)$page : 1;
		$this->limit = isset( $limit ) && is_numeric( $limit ) ? (int)$limit : 10;
		$this->data  = $data;
	}


	/**
	 * @return array
	 */
	public function getData(): array {
		return $this->data;
	}


	/**
	 * @param array $data
	 *
	 * @return void
	 */
	public function setData( array $data ): void {
		$this->data = $data;
	}


	/**
	 * @return int
	 */
	public function getLimit(): int {
		return $this->limit;
	}


	/**
	 * @param int $limit
	 */
	public function setLimit( int $limit ): void {
		$this->limit = $limit;
	}


	/**
	 * @return int
	 */
	public function getSkip(): int {
		return ( $this->page - 1 ) * $this->limit;
	}


	/**
	 * @return int
	 */
	public function getCount(): int {
		return count( $this->data );
	}


	/**
	 * @return int
	 */
	public function getPage(): int {
		return $this->page;
	}


	/**
	 * @param int $page
	 */
	public function setPage( int $page ): void {
		$this->page = $page;
	}


	/**
	 * @return int
	 */
	public function getTotalDocumentCount(): int {
		return $this->totalDocumentCount;
	}


	/**
	 * @param int $totalDocumentCount
	 */
	public function setTotalDocumentCount( int $totalDocumentCount ): void {
		$this->totalDocumentCount = $totalDocumentCount;
	}


	/**
	 * @return int
	 */
	public function getTotalPageCount(): int {
		return ceil( $this->totalDocumentCount / $this->limit );
	}

}