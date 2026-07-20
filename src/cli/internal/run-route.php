<?php
/**
 * Internal child-process entry used by `gf cli <route>` to execute an application
 * CLI route. Not a public API — invoke routes through gf.
 *
 *   php run-route.php <path-to-app-vendor-autoload.php> <route-uri>
 *
 * Mirrors the legacy app/cli/index.php contract: the route executes through the
 * full framework lifecycle with REQUEST_METHOD=CLI. The framework renderer records
 * the response status via http_response_code(), which the CLI SAPI retains — a
 * status of 400+ maps to a non-zero process exit code so schedulers and scripts
 * can detect failures.
 */

if( $argc<3 ) {
	fwrite( STDERR, 'Usage: php run-route.php <vendor/autoload.php> <route>' . PHP_EOL );
	exit( 2 );
}

require $argv[ 1 ];

$_SERVER[ 'REQUEST_METHOD' ] = 'CLI';
$_SERVER[ 'REQUEST_URI' ]    = $argv[ 2 ];
$_SERVER[ 'REMOTE_ADDR' ]    = '127.0.0.1';

$framework = new \gcgov\framework\framework();
echo $framework->runApp();

$httpStatus = http_response_code();
exit( ( is_int( $httpStatus ) && $httpStatus>=400 ) ? 1 : 0 );
