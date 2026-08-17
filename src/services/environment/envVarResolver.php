<?php

declare(strict_types=1);

namespace gcgov\framework\services\environment;

/**
 * Resolves Symfony-style `%env(...)%` references inside a config JSON document.
 *
 * This is a small, standalone, directly-testable resolver — it is intentionally
 * NOT coupled to Symfony's dependency-injection container (where Symfony's own
 * env processors live). It is applied to the raw JSON string of app.json /
 * environment.json before that string is handed to `jsonDeserialize()`.
 *
 * ## Backwards compatibility
 * A config file that contains no `%env(` substring is returned byte-for-byte
 * unchanged (including its existing malformed-JSON error behavior). Only files
 * that opt in by using `%env(...)%` are decoded and re-serialized.
 *
 * ## Syntax
 * `%env(PROCESSOR:...:VAR_NAME)%`
 *  - The last `:`-delimited segment is the environment variable name
 *    (`[A-Za-z_][A-Za-z0-9_]*`).
 *  - Preceding segments form a processor chain, applied right-to-left (Symfony
 *    order): `%env(trim:file:DB_PASS_FILE)%` = `trim(file(env(DB_PASS_FILE)))`.
 *  - When the whole string is a single `%env(...)%`, the typed result
 *    (int/bool/float/array/stdClass/string) replaces the value. When `%env(...)%`
 *    appears embedded inside a larger string, its result is substituted as a
 *    string (a non-scalar embedded result throws).
 *
 * ## Processors
 *  string, bool, not, int, float, trim, file, base64, json, default.
 *
 * ## The `default` processor (deliberate deviation from Symfony)
 * Unlike Symfony — where `default:` names a fallback *parameter* — here `default`
 * takes a **literal** fallback value. It must be innermost (closest to the var),
 * and its argument is greedy: everything between `default:` and the final `:VAR`,
 * so colons are legal in the fallback:
 *   `%env(default:mongodb://mongodb:27017:MONGO_URI)%`
 * The fallback applies only when the variable is unset:
 *   `%env(default::VAR)%`            → '' when VAR is unset
 *   `%env(int:default:587:SMTP_PORT)%` → int 587 when SMTP_PORT is unset
 */
final class envVarResolver {

	/**
	 * Resolve every `%env(...)%` reference in $json.
	 *
	 * @param  string                 $json               Raw config JSON.
	 * @param  string                 $sourceDescription  Human-readable source (e.g. the file path) for error messages.
	 * @param  array<string, string>  $overlayVars        Variables that take precedence over the ambient
	 *                                                    environment during this resolution. Used by the gf CLI
	 *                                                    to resolve a *foreign* environment's config (e.g.
	 *                                                    `app/config/prod.env` for `db:restore --from=prod`) —
	 *                                                    an explicit variant request must beat the local
	 *                                                    environment. A variable missing from the overlay falls
	 *                                                    back to the ambient lookup, so an incomplete overlay
	 *                                                    silently picks up local values — overlay files should
	 *                                                    define every environment-specific variable.
	 *
	 * @return string|\stdClass  The original string (fast path / undecodable), or the resolved object tree.
	 * @throws \gcgov\framework\services\environment\environmentException
	 */
	public static function resolveJson( string $json, string $sourceDescription, array $overlayVars = [] ): string|\stdClass {
		// Fast path: configs that do not opt in take a byte-identical route, preserving
		// full backwards compatibility (including today's malformed-JSON error behavior).
		if( !str_contains( $json, '%env(' ) ) {
			return $json;
		}

		$decoded = json_decode( $json, false );
		if( !$decoded instanceof \stdClass ) {
			// Malformed JSON, or a non-object root we cannot hydrate: hand the raw string
			// back so the caller's jsonDeserialize() raises its normal error.
			return $json;
		}

		$resolved = self::resolveNode( $decoded, $sourceDescription, $overlayVars );

		return $resolved instanceof \stdClass ? $resolved : $json;
	}


	/**
	 * Recursively resolve string leaves within the decoded tree.
	 *
	 * @param  mixed                  $node
	 * @param  string                 $sourceDescription
	 * @param  array<string, string>  $overlayVars
	 *
	 * @return mixed
	 * @throws \gcgov\framework\services\environment\environmentException
	 */
	private static function resolveNode( mixed $node, string $sourceDescription, array $overlayVars ): mixed {
		if( $node instanceof \stdClass ) {
			foreach( get_object_vars( $node ) as $key => $value ) {
				$node->$key = self::resolveNode( $value, $sourceDescription, $overlayVars );
			}

			return $node;
		}

		if( is_array( $node ) ) {
			return array_map( static fn( $value ) => self::resolveNode( $value, $sourceDescription, $overlayVars ), $node );
		}

		if( is_string( $node ) ) {
			return self::resolveString( $node, $sourceDescription, $overlayVars );
		}

		return $node;
	}


	/**
	 * Resolve `%env(...)%` occurrences in a single string leaf.
	 *
	 * @param  array<string, string>  $overlayVars
	 *
	 * @return mixed  Typed value when the whole string is one reference; a string otherwise.
	 * @throws \gcgov\framework\services\environment\environmentException
	 */
	private static function resolveString( string $value, string $sourceDescription, array $overlayVars ): mixed {
		if( !str_contains( $value, '%env(' ) ) {
			return $value;
		}

		// Whole-string reference → typed result.
		if( preg_match( '/^%env\(([^)]+)\)%$/', $value, $matches )===1 ) {
			return self::resolveExpression( $matches[ 1 ], $sourceDescription, $overlayVars );
		}

		// Embedded reference(s) → string substitution.
		$result = preg_replace_callback( '/%env\(([^)]+)\)%/', static function( array $matches ) use ( $sourceDescription, $overlayVars ): string {
			$resolved = self::resolveExpression( $matches[ 1 ], $sourceDescription, $overlayVars );
			if( is_bool( $resolved ) ) {
				return $resolved ? 'true' : 'false';
			}
			if( $resolved===null ) {
				return '';
			}
			if( is_scalar( $resolved ) ) {
				return (string)$resolved;
			}
			throw new environmentException( 'Cannot embed non-scalar environment value for %env(' . $matches[ 1 ] . ')% inside a larger string in ' . $sourceDescription . '. Reference it as the whole value ("%env(...)%") instead.' );
		}, $value );

		return $result ?? $value;
	}


	/**
	 * Resolve one `%env(...)%` expression (the text between the parentheses).
	 *
	 * @param  array<string, string>  $overlayVars
	 *
	 * @return mixed
	 * @throws \gcgov\framework\services\environment\environmentException
	 */
	private static function resolveExpression( string $expression, string $sourceDescription, array $overlayVars = [] ): mixed {
		$lastColon = strrpos( $expression, ':' );
		if( $lastColon===false ) {
			$varName       = $expression;
			$processorSpec = '';
		}
		else {
			$varName       = substr( $expression, $lastColon + 1 );
			$processorSpec = substr( $expression, 0, $lastColon );
		}

		if( preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $varName )!==1 ) {
			throw new environmentException( 'Invalid environment variable reference "%env(' . $expression . ')%" in ' . $sourceDescription . ': "' . $varName . '" is not a valid variable name.' );
		}

		// Parse the processor chain left-to-right (outer → inner). `default` is greedy:
		// it consumes the remainder of the spec as its literal fallback and is innermost.
		$processors    = [];
		$default       = null;
		$remainingSpec = $processorSpec;
		while( $remainingSpec!=='' ) {
			$colon = strpos( $remainingSpec, ':' );
			$token = $colon===false ? $remainingSpec : substr( $remainingSpec, 0, $colon );
			$rest  = $colon===false ? '' : substr( $remainingSpec, $colon + 1 );

			if( $token==='default' ) {
				$default       = $rest;
				$remainingSpec = '';
				break;
			}

			$processors[]  = $token;
			$remainingSpec = $rest;
		}

		// Environment lookup (with optional literal default fallback).
		$raw = self::lookupEnv( $varName, $overlayVars );
		if( $raw===null ) {
			if( $default===null ) {
				throw new environmentException( 'Required environment variable "' . $varName . '" is not set (referenced as "%env(' . $expression . ')%" in ' . $sourceDescription . '). Set it in the process environment, a Docker secret, or a .env file.' );
			}
			$value = $default;
		}
		else {
			$value = $raw;
		}

		// Apply processors right-to-left (inner → outer).
		foreach( array_reverse( $processors ) as $processor ) {
			$value = self::applyProcessor( $processor, $value, $expression, $sourceDescription );
		}

		return $value;
	}


	/**
	 * @param  mixed  $value
	 *
	 * @return mixed
	 * @throws \gcgov\framework\services\environment\environmentException
	 */
	private static function applyProcessor( string $processor, mixed $value, string $expression, string $sourceDescription ): mixed {
		switch( $processor ) {
			case 'string':
				return (string)$value;

			case 'bool':
				return self::toBool( $value );

			case 'not':
				return !self::toBool( $value );

			case 'int':
				if( !is_numeric( trim( (string)$value ) ) ) {
					throw new environmentException( 'Cannot apply "int" to non-numeric value for "%env(' . $expression . ')%" in ' . $sourceDescription . '.' );
				}

				return (int)$value;

			case 'float':
				if( !is_numeric( trim( (string)$value ) ) ) {
					throw new environmentException( 'Cannot apply "float" to non-numeric value for "%env(' . $expression . ')%" in ' . $sourceDescription . '.' );
				}

				return (float)$value;

			case 'trim':
				return trim( (string)$value );

			case 'file':
				$path = (string)$value;
				if( !is_file( $path ) || !is_readable( $path ) ) {
					throw new environmentException( 'Cannot apply "file" for "%env(' . $expression . ')%" in ' . $sourceDescription . ': file "' . $path . '" does not exist or is not readable.' );
				}
				$contents = file_get_contents( $path );
				if( $contents===false ) {
					throw new environmentException( 'Cannot apply "file" for "%env(' . $expression . ')%" in ' . $sourceDescription . ': failed reading "' . $path . '".' );
				}

				return $contents;

			case 'base64':
				// URL-safe tolerant: accept the URL-safe alphabet and missing padding.
				$normalized = strtr( (string)$value, '-_', '+/' );
				$padding    = strlen( $normalized ) % 4;
				if( $padding>0 ) {
					$normalized .= str_repeat( '=', 4 - $padding );
				}
				$decoded = base64_decode( $normalized, true );
				if( $decoded===false ) {
					throw new environmentException( 'Cannot apply "base64" for "%env(' . $expression . ')%" in ' . $sourceDescription . ': value is not valid base64.' );
				}

				return $decoded;

			case 'json':
				$decoded = json_decode( (string)$value, false );
				if( json_last_error()!==JSON_ERROR_NONE ) {
					throw new environmentException( 'Cannot apply "json" for "%env(' . $expression . ')%" in ' . $sourceDescription . ': ' . json_last_error_msg() . '.' );
				}

				return $decoded;

			default:
				throw new environmentException( 'Unknown environment processor "' . $processor . '" in "%env(' . $expression . ')%" (' . $sourceDescription . '). Supported: string, bool, not, int, float, trim, file, base64, json, default.' );
		}
	}


	/**
	 * @param  mixed  $value
	 */
	private static function toBool( mixed $value ): bool {
		$bool = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		if( $bool===null ) {
			return (bool)$value;
		}

		return $bool;
	}


	/**
	 * Look up an environment variable value.
	 * Precedence: overlay → $_ENV → $_SERVER (excluding HTTP_* request headers) → getenv().
	 * Returns null only when the variable is genuinely unset (a set-but-empty
	 * variable — overlay included — resolves to '', which also suppresses `default:`).
	 *
	 * @param  array<string, string>  $overlayVars
	 */
	private static function lookupEnv( string $name, array $overlayVars = [] ): ?string {
		if( array_key_exists( $name, $overlayVars ) ) {
			return (string)$overlayVars[ $name ];
		}

		if( array_key_exists( $name, $_ENV ) ) {
			return (string)$_ENV[ $name ];
		}

		if( !str_starts_with( $name, 'HTTP_' ) && array_key_exists( $name, $_SERVER ) ) {
			return (string)$_SERVER[ $name ];
		}

		$value = getenv( $name );
		if( $value!==false ) {
			return $value;
		}

		return null;
	}

}
