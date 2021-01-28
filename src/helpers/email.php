<?php
//TODO: rework (burn down?) for \gcgov\framework
namespace gcgov\framework\helpers;

use \PHPMailer\PHPMailer\PHPMailer;
use \PHPMailer\PHPMailer\SMTP;
use \PHPMailer\PHPMailer\Exception;

class email {

	private static $from        = '';

	private static $sep;

	private static $images      = null;

	private static $attachments = [];

	private static $bcc         = [];

	public static function images( $attachments ) {

		self::$images = [];

		foreach( $attachments as $i => $attachment ) {

			if( !is_array( $attachment ) ) {
				$theme = sessionController::getTheme();
				$path  = AS_WWW_PATH . "/theme/" . $theme[ 'dirname' ] . "/images/email/" . $attachment;
			}
			else {
				$path = $attachment[ 'path' ];
			}

			if( file_exists( $path ) ) {
				//get the mime type
				$finfo = finfo_open( FILEINFO_MIME_TYPE );
				$type  = finfo_file( $finfo, $path );
				finfo_close( $finfo );


				self::$images[] = [
					'path'     => $path,
					'name'     => $attachment,
					'contents' => chunk_split( base64_encode( file_get_contents( $path ) ) ),
					'cid'      => 'cid' . $i,
					'type'     => $type
				];
			}

		}

	}


	public static function bcc( $to ) {

		self::$bcc = [];

		if( is_string( $to ) ) {
			self::$bcc[] = $to;
		}
		elseif( is_array( $to ) ) {
			foreach( $to as $addTo ) {
				self::$bcc[] = $addTo;
			}
		}

	}


	public static function clearAttachments() {

		self::$attachments = [];
	}


	public static function attachments( $attachments ) {

		self::$attachments = [];

		foreach( $attachments as $attachmentPath ) {

			self::$attachments[] = $attachmentPath;
		}

	}


	public static function send( $to, $subject, $message, $from = '', $replyTo = '', $replyToName = '' ) {

		if( trim( $from ) == '' ) {
			$from     = $_SESSION[ 'AS' ][ 'config' ][ 'email' ][ 'from_address' ];
			$fromName = $_SESSION[ 'AS' ][ 'config' ][ 'email' ][ 'from_name' ];
		}
		else {
			$fromName = $from;
		}

		$mail = new PHPMailer( true );

		if( isset( $_SESSION[ 'AS' ][ 'config' ][ 'email' ][ 'smtp' ] ) ) {
			error_log( 'sending using config email settings' );
			$mail->IsSMTP();
			$mail->SMTPAuth    = $_SESSION[ 'AS' ][ 'config' ][ 'email' ][ 'smtp' ][ 'auth' ] == 'true';  // enable SMTP authentication
			$mail->SMTPSecure  = $_SESSION[ 'AS' ][ 'config' ][ 'email' ][ 'smtp' ][ 'secure' ];
			$mail->SMTPOptions = [
				'ssl' => [
					'verify_peer'       => false,
					'verify_peer_name'  => false,
					'allow_self_signed' => true
				]
			];
			$mail->Host        = $_SESSION[ 'AS' ][ 'config' ][ 'email' ][ 'smtp' ][ 'host' ];                            // sets the SMTP server
			$mail->Port        = $_SESSION[ 'AS' ][ 'config' ][ 'email' ][ 'smtp' ][ 'port' ];                            // set the SMTP port for the GMAIL server
			if( $_SESSION[ 'AS' ][ 'config' ][ 'email' ][ 'smtp' ][ 'auth' ] == 'true' ) {
				$mail->Username = $_SESSION[ 'AS' ][ 'config' ][ 'email' ][ 'smtp' ][ 'username' ];                        // SMTP account username
				$mail->Password = $_SESSION[ 'AS' ][ 'config' ][ 'email' ][ 'smtp' ][ 'password' ];                        // SMTP account password
			}
		}

		try {
			if( is_string( $to ) ) {
				$mail->AddAddress( $to );
			}
			elseif( is_array( $to ) ) {
				foreach( $to as $addTo ) {
					$mail->AddAddress( $addTo );
				}
			}

			foreach( self::$bcc as $bcc ) {
				$mail->AddBCC( $bcc );
			}

			if( $replyTo != '' ) {
				elog( 'reply to: ' . $replyTo );
				$mail->addReplyTo( $replyTo, $replyToName );
			}
			$mail->SetFrom( $from, $fromName );
			$mail->Subject = $subject;
			$mail->MsgHTML( $message );

			if( is_array( self::$images ) ) {
				foreach( self::$images as $image ) {
					$mail->AddEmbeddedImage( $image[ 'path' ], $image[ 'cid' ], $image[ 'name' ], 'base64', $image[ 'type' ] );
				}
			}

			if( is_array( self::$attachments ) ) {
				foreach( self::$attachments as $attachment ) {
					$mail->AddAttachment( $attachment );
				}
			}

			$mail->Send();

			$tostr = $to;
			if( is_array( $to ) ) {
				$tostr = implode( ',', $to );
			}
			error_log( 'Successfully emailed to ' . $tostr . ' with subject line ' . $subject );

			return true;

		}
		catch( \PHPMailer\PHPMailer\Exception $e ) {
			error_log( 'MAIL: PHPMailer library exception - failed sending to ' . $to . ' with subject line ' . $subject . '. Error: ' . $e->errorMessage() );

			return false;
		}
		catch( \Exception $e ) {
			error_log( 'MAIL: Generic exception - failed sending to ' . $to . ' with subject line ' . $subject );

			return false;
		}

	}


	private static function headers() {

		if( self::$from == '' ) {
			self::setDefaultFrom();
		}

		$headers   = [];
		$headers[] = "From: " . self::$from;
		$headers[] = "X-Mailer: PHP/" . phpversion();
		$headers[] = "MIME-Version: 1.0";
		$headers[] = "Content-Type: multipart/related;boundary=\"sep-{" . self::$sep . "}\"";//"Content-Type: text/html; charset=ISO-8859-1";

		$glue = "\r\n";

		return implode( $glue, $headers );
	}


	private static function setDefaultFrom() {

		self::$from = $_SESSION[ 'AS' ][ 'config' ][ 'email' ][ 'from_address' ];
	}

}