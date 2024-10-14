<?php

namespace gcgov\framework\models\config\app;

class email extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public string $fromAddress  = '';
	public string $fromName     = '';
	public bool   $useSMTP      = false;
	public bool   $SMTPAuth     = false;
	public string $SMTPHost     = '';
	public int    $SMTPPort     = 587;
	public string $SMTPUsername = '';
	public string $SMTPPassword = '';
	public string $replyToAddress = '';
	public string $replyToName = '';


	public function __construct() {
	}


}
