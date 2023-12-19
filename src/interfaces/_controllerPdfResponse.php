<?php

namespace gcgov\framework\interfaces;

use JetBrains\PhpStorm\Deprecated;

#[Deprecated('Use _controllerFileResponse instead')]
interface _controllerPdfResponse extends _controllerFileResponse {

	public function setContentType( string $contentType ): void;

}
