<?php

namespace gcgov\framework\interfaces;

interface dbGetResult {

	public function setData( array $data ): void;


	public function setLimit( int $limit ): void;


	public function setPage( int $page ): void;


	public function getData(): array;


	public function getLimit(): int;


	public function getSkip(): int;


	public function getCount(): int;


	public function getPage(): int;


	public function getTotalDocumentCount(): int;


	public function setTotalDocumentCount( int $totalDocumentCount ): void;


	public function getTotalPageCount(): int;

}