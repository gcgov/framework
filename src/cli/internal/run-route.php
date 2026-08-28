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
 *
 * This file runs before the framework is loaded and must therefore assume nothing
 * about the interpreter it was started with: STDERR only exists under the CLI SAPI
 * (and only when the process has real console handles), and $argv/$argc only exist
 * when register_argc_argv is enabled — php.ini-production ships it Off.
 */

/**
 * Write a message to stderr, falling back to the PHP error log when this
 * interpreter has no usable stderr stream (php-cgi, php-win.exe, ...).
 */
$gfWriteError = static function( string $message ): void {
	$handle = defined( 'STDERR' ) ? STDERR : @fopen( 'php://stderr', 'wb' );
	if( !is_resource( $handle ) ) {
		error_log( 'gf run-route: ' . $message );

		return;
	}
	fwrite( $handle, 'gf run-route: ' . $message . PHP_EOL );
};

if( PHP_SAPI!=='cli' ) {
	$gfWriteError( 'application CLI routes must run under the PHP CLI binary, but this process is running the "' . PHP_SAPI . '" SAPI. Point gf at php/php.exe instead of php-cgi/php-fpm with `gf cli --php=<binary or directory>`, or the GF_PHP environment variable.' );
	exit( 2 );
}

/** @var string[]|null $gfArguments */
$gfArguments = $argv ?? ( $_SERVER[ 'argv' ] ?? null );

if( !is_array( $gfArguments ) ) {
	$gfWriteError( 'command line arguments are unavailable ($argv is undefined). Enable register_argc_argv in the php.ini used by this interpreter, or upgrade gcgov/framework so gf passes -dregister_argc_argv=1 to the child process.' );
	exit( 2 );
}

if( count( $gfArguments )<3 ) {
	$gfWriteError( 'usage: php run-route.php <vendor/autoload.php> <route>' );
	exit( 2 );
}

require $gfArguments[ 1 ];

$_SERVER[ 'REQUEST_METHOD' ] = 'CLI';
$_SERVER[ 'REQUEST_URI' ]    = $gfArguments[ 2 ];
$_SERVER[ 'REMOTE_ADDR' ]    = '127.0.0.1';

$framework = new \gcgov\framework\framework();
echo $framework->runApp();

$httpStatus = http_response_code();
exit( ( is_int( $httpStatus ) && $httpStatus>=400 ) ? 1 : 0 );
