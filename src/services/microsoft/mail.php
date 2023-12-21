<?php

namespace gcgov\framework\services\microsoft;


use gcgov\framework\config;
use gcgov\framework\exceptions\serviceException;
use GuzzleHttp\Exception\GuzzleException;
use JetBrains\PhpStorm\Deprecated;
use Microsoft\Graph\Exception\GraphException;


#[Deprecated('Use \andrewsauder\microsoftServices instead')]
class mail {

	private array $attachments = [];


	public function addAttachment( string $filePath ) {
		if( !file_exists( $filePath ) ) {
			throw new serviceException( 'File does not exist', 500 );
		}

		$fileName     = basename( $filePath );
		$fileContents = file_get_contents( $filePath );
		$mimeType     = mime_content_type( $filePath );

		$this->attachments[] = [
			'@odata.type'  => '#microsoft.graph.fileAttachment',
			'name'         => $fileName,
			'contentType'  => $mimeType,
			'contentBytes' => base64_encode( $fileContents )
		];
	}


	/**
	 * @param  string|string[]  $to
	 * @param  string           $subject
	 * @param  string           $content
	 * @param  string           $from
	 *
	 * @return \Microsoft\Graph\Http\GraphResponse|mixed
	 *
	 * @throws \gcgov\framework\exceptions\serviceException
	 */
	public function send( string|array $to, string $subject, string $content, string $from = '' ) {
		//get application access token
		$accessToken = $this->getMicrosoftAccessToken();

		//get file list
		$graph = new \Microsoft\Graph\Graph();
		$graph->setAccessToken( $accessToken );

		$toRecipients = [];
		if( !is_array( $to ) ) {
			$to = [ $to ];
		}
		foreach( $to as $emailAddress ) {
			$toRecipients[] = [
				'emailAddress' => [
					'address' => $emailAddress
				]
			];
		}

		if( $from == '' ) {
			$from = config::getEnvironmentConfig()->microsoft->fromAddress;
		}

		$mailBody = [
			'Message' => [
				'subject'      => $subject,
				'body'         => [
					'contentType' => 'HTML',
					'content'     => $content
				],
				'from'         => [
					'emailAddress' => [
						'address' => $from
					]
				],
				'toRecipients' => $toRecipients
			]
		];

		if( count( $this->attachments ) > 0 ) {
			$mailBody[ 'Message' ][ 'attachments' ] = $this->attachments;
		}


		try {
			$response = $graph->createRequest( 'POST', '/users/' . $from . '/sendMail' )->attachBody( $mailBody )->execute();
		}
		catch(GraphException $e) {
			error_log($e);
			throw new serviceException( 'Failed to send email: '.$e->getMessage(), 500, $e );
		}
		catch(GuzzleException $e){
			error_log($e);
			throw new serviceException( 'Failed to send email: '.$e->getMessage(), $e->getCode(), $e );
		}
		return $response;
	}


	/**
	 * @return mixed
	 * @throws \gcgov\framework\exceptions\serviceException
	 */
	private function getMicrosoftAccessToken() : string {
		$microsoftAuth = new auth();

		//get user access token
		if( isset( $_SERVER[ 'HTTP_X_MSACCESSTOKEN' ] ) ) {
			return (string) $microsoftAuth->getAccessToken( $_SERVER[ 'HTTP_X_MSACCESSTOKEN' ] );
		}
		//get access token
		else {
			return $microsoftAuth->getApplicationAccessToken();
		}
	}

}
