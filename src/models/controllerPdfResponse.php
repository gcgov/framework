<?php

namespace gcgov\framework\models;

use gcgov\framework\interfaces\_controllerPdfResponse;
use JetBrains\PhpStorm\Deprecated;

#[Deprecated('Use controllerFileResponse instead')]
class controllerPdfResponse extends controllerFileResponse implements _controllerPdfResponse {

	public const SupportedContentTypes = [
		'application/pdf'
	];

	/**
	 * @param string                                             $filePathname
	 * @param \gcgov\framework\models\controllerResponseHeader[] $headers
	 *
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	public function __construct( string $filePathname = '', array $headers = [] ) {
		parent::__construct( $filePathname, $headers );
	}


	/**
	 * @param string $contentType
	 *
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	public function setContentType( string $contentType ): void {
		if( in_array( $contentType, self::SupportedContentTypes ) ) {
			$this->contentType = $contentType;
		}
		else {
			throw new \gcgov\framework\exceptions\controllerException( 'Content-Type: ' . $contentType . ' has not been implemented in \gcgov\framework\renderer. Supported types: ' . json_encode( self::SupportedContentTypes ) );
		}
	}

}
