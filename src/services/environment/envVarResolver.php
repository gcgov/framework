<?php

declare(strict_types=1);

namespace gcgov\framework\services\environment;

/**
 * Resolves `%env(...)%` references inside the unified {root}/config.json.
 *
 * This is a small, standalone, directly-testable resolver — it is intentionally
 * NOT coupled to Symfony's dependency-injection container (where Symfony's own
 * env processors live). The framework applies it to config.json before the JSON
 * is handed to `jsonDeserialize()` (see configLoader).
 *
 * ## Every reference is required
 * There is no fallback mechanism. A referenced variable that is unset — or set
 * to the empty string, which counts as unset — is a startup failure naming the
 * variable. A configuration value that does not vary between Environments is
 * written as a literal in config.json rather than referenced.
 *
 * ## Backwards compatibility
 * A config string that contains no `%env(` substring is returned byte-for-byte
 * unchanged. Only values that opt in by using `%env(...)%` are touched. A value
 * that still contains the literal text `%env(` AFTER resolution throws — there is
 * no escape syntax, so a config value cannot contain that literal text.
 *
 * ## Syntax
 * `%env(PROCESSOR:...:VAR_NAME)%`
 *  - The last `:`-delimited segment is the environment variable name
 *    (`[A-Za-z_][A-Za-z0-9_]*`).
 *  - Preceding segments form a processor chain, applied right-to-left (Symfony
 *    order): `%env(trim:file:DB_PASS_FILE)%` = `trim(file(env(DB_PASS_FILE)))`.
 *  - When the whole string is a single `%env(...)%`, the typed result
 *    (int/bool/array/stdClass/string) replaces the value. When `%env(...)%`
 *    appears embedded inside a larger string, its result is substituted as a
 *    string (a non-scalar embedded result throws).
 *
 * ## Processors
 *  secret, file, trim, int, bool, json.
 *
 * ## The `secret` lookup
 * `%env(secret:MONGO_URI)%` implements the conventional `_FILE` indirection used
 * by the official database images: if `MONGO_URI_FILE` is set, its value is a path
 * whose (trimmed) contents are the result; otherwise `MONGO_URI` is read directly.
 * A `_FILE` variable pointing at a missing or unreadable file is an error and never
 * falls back to the plain variable — falling back would silently substitute a stale
 * environment value for a secret that failed to mount.
 *
 * This is what lets one committed config.json serve both a developer's machine
 * (plain variables in .env) and production (files provisioned to /run/secrets).
 * `secret` must be the innermost element of a processor chain.
 *
 * ## Reserved (blocked) variable names — request-data injection guard
 * In web SAPIs, request data leaks into the ambient lookup sources: CGI/FastCGI
 * turns request headers into `HTTP_*` variables that reach the real process
 * environment (getenv) and, depending on `variables_order`, `$_ENV`; `$_SERVER`
 * additionally carries request-derived CGI meta-variables (SERVER_NAME,
 * PHP_AUTH_PW, QUERY_STRING, …). To guarantee a `%env(...)%` reference can never
 * be satisfied by request data, names matching the CGI meta-variable set are
 * treated as UNSET in every ambient source, so the reference fails loudly. Do not
 * name real configuration variables after CGI meta-variables.
 */
final class envVarResolver {

	/** Suffix of the companion variable naming a secret's file, per the conventional `_FILE` indirection. */
	public const string SECRET_FILE_SUFFIX = '_FILE';

	/** Name prefixes never resolved from the ambient environment (request-derived under web SAPIs). */
	private const array BLOCKED_NAME_PREFIXES = [ 'HTTP_', 'SERVER_', 'REQUEST_', 'REMOTE_', 'PHP_AUTH_', 'SCRIPT_', 'DOCUMENT_' ];

	/** Exact names never resolved from the ambient environment (request-derived under web SAPIs). */
	private const array BLOCKED_NAMES = [ 'HTTPS', 'QUERY_STRING', 'CONTENT_TYPE', 'CONTENT_LENGTH', 'AUTH_TYPE', 'GATEWAY_INTERFACE', 'PHP_SELF', 'PATH_INFO', 'PATH_TRANSLATED' ];

	/** Every supported processor. `secret` changes the lookup; the rest transform the value. */
	private const array PROCESSORS = [ 'secret', 'file', 'trim', 'int', 'bool', 'json' ];


	/**
	 * Resolve every `%env(...)%` reference in $json.
	 *
	 * @param  string  $json               Raw config JSON.
	 * @param  string  $sourceDescription  Human-readable source (e.g. the file path) for error messages.
	 *
	 * @return string|\stdClass  The original string (fast path / undecodable), or the resolved object tree.
	 * @throws \gcgov\framework\services\environment\environmentException
	 */
	public static function resolveJson( string $json, string $sourceDescription ): string|\stdClass {
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

		return self::resolveDecoded( $decoded, $sourceDescription );
	}


	/**
	 * Resolve every `%env(...)%` reference in an already-decoded config tree, in place.
	 *
	 * @throws \gcgov\framework\services\environment\environmentException
	 */
	public static function resolveDecoded( \stdClass $decoded, string $sourceDescription ): \stdClass {
		self::resolveNode( $decoded, $sourceDescription );

		return $decoded;
	}


	/**
	 * Every variable referenced by a decoded config tree, WITHOUT resolving any of them —
	 * so this works on a machine that has none of them set. Used by `gf env --list` and
	 * `gf env --init` to generate the .env manifest from config.json itself, which is what
	 * keeps the two from drifting.
	 *
	 * A `secret` reference reports the variable's own name; the companion `{NAME}_FILE`
	 * variable is implied by the `secret` flag rather than listed separately.
	 *
	 * @return array<string, bool>  variable name => is a secret, ordered by first appearance
	 * @throws \gcgov\framework\services\environment\environmentException  On a malformed reference
	 */
	public static function collectReferences( \stdClass $decoded, string $sourceDescription ): array {
		$references = [];
		self::walkStrings( $decoded, function( string $value ) use ( &$references, $sourceDescription ): void {
			if( !str_contains( $value, '%env(' ) ) {
				return;
			}
			preg_match_all( '/%env\(([^)]+)\)%/', $value, $matches );
			foreach( $matches[ 1 ] as $expression ) {
				[ $varName, $processors ] = self::parseExpression( $expression, $sourceDescription );
				$isSecret = in_array( 'secret', $processors, true );
				// A name referenced both ways is a secret: the stricter reading wins.
				$references[ $varName ] = ( $references[ $varName ] ?? false ) || $isSecret;
			}
		} );

		return $references;
	}


	/**
	 * Recursively resolve string leaves within the decoded tree.
	 *
	 * @param  mixed   $node
	 * @param  string  $sourceDescription
	 *
	 * @return mixed
	 * @throws \gcgov\framework\services\environment\environmentException
	 */
	private static function resolveNode( mixed $node, string $sourceDescription ): mixed {
		if( $node instanceof \stdClass ) {
			foreach( get_object_vars( $node ) as $key => $value ) {
				$node->$key = self::resolveNode( $value, $sourceDescription );
			}

			return $node;
		}

		if( is_array( $node ) ) {
			return array_map( static fn( $value ) => self::resolveNode( $value, $sourceDescription ), $node );
		}

		if( is_string( $node ) ) {
			return self::resolveString( $node, $sourceDescription );
		}

		return $node;
	}


	/**
	 * Visit every string leaf of a decoded tree without modifying it.
	 *
	 * @param  mixed                  $node
	 * @param  callable(string):void  $visitor
	 */
	private static function walkStrings( mixed $node, callable $visitor ): void {
		if( $node instanceof \stdClass ) {
			foreach( get_object_vars( $node ) as $value ) {
				self::walkStrings( $value, $visitor );
			}

			return;
		}

		if( is_array( $node ) ) {
			foreach( $node as $value ) {
				self::walkStrings( $value, $visitor );
			}

			return;
		}

		if( is_string( $node ) ) {
			$visitor( $node );
		}
	}


	/**
	 * Resolve `%env(...)%` occurrences in a single string leaf.
	 *
	 * @return mixed  Typed value when the whole string is one reference; a string otherwise.
	 * @throws \gcgov\framework\services\environment\environmentException
	 */
	private static function resolveString( string $value, string $sourceDescription ): mixed {
		if( !str_contains( $value, '%env(' ) ) {
			return $value;
		}

		// Whole-string reference → typed result.
		if( preg_match( '/^%env\(([^)]+)\)%$/', $value, $matches )===1 ) {
			return self::resolveExpression( $matches[ 1 ], $sourceDescription );
		}

		// Embedded reference(s) → string substitution.
		$result = preg_replace_callback( '/%env\(([^)]+)\)%/', static function( array $matches ) use ( $sourceDescription ): string {
			$resolved = self::resolveExpression( $matches[ 1 ], $sourceDescription );
			if( is_bool( $resolved ) ) {
				return $resolved ? 'true' : 'false';
			}
			if( is_scalar( $resolved ) ) {
				return (string)$resolved;
			}
			throw new environmentException( 'Cannot embed non-scalar environment value for %env(' . $matches[ 1 ] . ')% inside a larger string in ' . $sourceDescription . '. Reference it as the whole value ("%env(...)%") instead.' );
		}, $value ) ?? $value;

		// Fail loud instead of silently shipping an unresolved reference: a leftover
		// '%env(' means an unterminated reference or a literal '%env(' in a config
		// value — neither is supported.
		if( str_contains( $result, '%env(' ) ) {
			throw new environmentException( 'Unresolvable %env(...) reference in ' . $sourceDescription . ': "' . $value . '". A reference ends at the first ")", and a config value cannot contain the literal text "%env(".' );
		}

		return $result;
	}


	/**
	 * Split one `%env(...)%` expression (the text between the parentheses) into its
	 * variable name and its processor chain, outermost first.
	 *
	 * @return array{0: string, 1: string[]}
	 * @throws \gcgov\framework\services\environment\environmentException
	 */
	private static function parseExpression( string $expression, string $sourceDescription ): array {
		$segments = explode( ':', $expression );
		$varName  = (string)array_pop( $segments );

		if( preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $varName )!==1 ) {
			throw new environmentException( 'Invalid environment variable reference "%env(' . $expression . ')%" in ' . $sourceDescription . ': "' . $varName . '" is not a valid variable name.' );
		}

		foreach( $segments as $index => $processor ) {
			if( !in_array( $processor, self::PROCESSORS, true ) ) {
				throw new environmentException( 'Unknown environment processor "' . $processor . '" in "%env(' . $expression . ')%" (' . $sourceDescription . '). Supported: ' . implode( ', ', self::PROCESSORS ) . '.' );
			}
			if( $processor==='secret' && $index!==count( $segments ) - 1 ) {
				throw new environmentException( '"secret" must be the innermost processor in "%env(' . $expression . ')%" (' . $sourceDescription . '), i.e. immediately before the variable name — it selects where the value is read from, so nothing can come between it and the variable.' );
			}
		}

		return [ $varName, $segments ];
	}


	/**
	 * Resolve one `%env(...)%` expression.
	 *
	 * @return mixed
	 * @throws \gcgov\framework\services\environment\environmentException
	 */
	private static function resolveExpression( string $expression, string $sourceDescription ): mixed {
		[ $varName, $processors ] = self::parseExpression( $expression, $sourceDescription );

		$useSecretLookup = false;
		if( count( $processors )>0 && end( $processors )==='secret' ) {
			array_pop( $processors );
			$useSecretLookup = true;
		}

		$value = $useSecretLookup
			? self::lookupSecret( $varName, $expression, $sourceDescription )
			: self::lookupEnv( $varName );

		if( $value===null ) {
			if( self::isBlockedName( $varName ) ) {
				throw new environmentException( '"' . $varName . '" is a reserved CGI meta-variable name and is never resolved from the environment (referenced as "%env(' . $expression . ')%" in ' . $sourceDescription . '). Rename the configuration variable.' );
			}
			throw new environmentException( 'Required environment variable "' . $varName . '" is not set' . ( $useSecretLookup ? ' (and neither is "' . $varName . self::SECRET_FILE_SUFFIX . '")' : '' ) . ' (referenced as "%env(' . $expression . ')%" in ' . $sourceDescription . '). Set it in the process environment, a provisioned secret file, or a .env file.' );
		}

		// Apply processors right-to-left (inner → outer).
		foreach( array_reverse( $processors ) as $processor ) {
			$value = self::applyProcessor( $processor, $value, $expression, $sourceDescription );
		}

		return $value;
	}


	/**
	 * The `secret` lookup: prefer the file named by `{NAME}_FILE`, else the plain variable.
	 * A `_FILE` variable that is set but unreadable is an error, never a fall-back — see the
	 * class docblock.
	 *
	 * @throws \gcgov\framework\services\environment\environmentException
	 */
	private static function lookupSecret( string $varName, string $expression, string $sourceDescription ): ?string {
		$fileVarName = $varName . self::SECRET_FILE_SUFFIX;
		$path        = self::lookupEnv( $fileVarName );

		if( $path===null ) {
			return self::lookupEnv( $varName );
		}

		if( !is_file( $path ) || !is_readable( $path ) ) {
			throw new environmentException( 'Secret file for "%env(' . $expression . ')%" in ' . $sourceDescription . ' does not exist or is not readable: "' . $path . '" (from ' . $fileVarName . '). The secret is not falling back to ' . $varName . ' — fix the mount or unset ' . $fileVarName . '.' );
		}

		$contents = file_get_contents( $path );
		if( $contents===false ) {
			throw new environmentException( 'Failed reading the secret file for "%env(' . $expression . ')%" in ' . $sourceDescription . ': "' . $path . '" (from ' . $fileVarName . ').' );
		}

		// Provisioned secret files conventionally end in a newline; a credential never
		// legitimately has surrounding whitespace.
		return trim( $contents );
	}


	/**
	 * @param  mixed  $value
	 *
	 * @return mixed
	 * @throws \gcgov\framework\services\environment\environmentException
	 */
	private static function applyProcessor( string $processor, mixed $value, string $expression, string $sourceDescription ): mixed {
		switch( $processor ) {
			case 'bool':
				// Fails closed, like "int" immediately below. The fallback used to be
				// `?? (bool)$value`, which turned every unrecognised value — "flase",
				// "disabled", "off ", "2" — into TRUE with no error: a typo in
				// AUTH_BLOCK_NEW_USERS or LOGGING_LIFECYCLE resolved silently to the wrong
				// setting and `gf env` reported success. ADR 0001 removed the default:
				// processor to keep exactly this from happening one processor over.
				$bool = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				if( $bool===null ) {
					throw new environmentException( 'Cannot apply "bool" to value "' . (string)$value . '" for "%env(' . $expression . ')%" in ' . $sourceDescription . '. Use one of: 1/0, true/false, yes/no, on/off.' );
				}

				return $bool;

			case 'int':
				if( !is_numeric( trim( (string)$value ) ) ) {
					throw new environmentException( 'Cannot apply "int" to non-numeric value for "%env(' . $expression . ')%" in ' . $sourceDescription . '.' );
				}

				return (int)$value;

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

			case 'json':
				$decoded = json_decode( (string)$value, false );
				if( json_last_error()!==JSON_ERROR_NONE ) {
					throw new environmentException( 'Cannot apply "json" for "%env(' . $expression . ')%" in ' . $sourceDescription . ': ' . json_last_error_msg() . '.' );
				}

				return $decoded;

			default:
				// parseExpression() has already rejected unknown processors, and `secret`
				// is consumed before the chain is applied.
				throw new environmentException( 'Environment processor "' . $processor . '" cannot be applied to a value in "%env(' . $expression . ')%" (' . $sourceDescription . ').' );
		}
	}


	/** Request-derived under web SAPIs — never satisfiable from the ambient environment. */
	private static function isBlockedName( string $name ): bool {
		if( in_array( $name, self::BLOCKED_NAMES, true ) ) {
			return true;
		}
		foreach( self::BLOCKED_NAME_PREFIXES as $prefix ) {
			if( str_starts_with( $name, $prefix ) ) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Look up an environment variable value.
	 * Precedence: $_ENV → $_SERVER → getenv(). The blocked-name guard applies to ALL
	 * three sources by name (not per source): under CGI/FastCGI SAPIs request headers
	 * reach the real process environment (getenv) and — with `variables_order=E` —
	 * $_ENV, so filtering only $_SERVER would be bypassable.
	 *
	 * A variable set to the empty string is reported as unset. Every reference is
	 * required, so "" is never a meaningful configured value — treating it as one
	 * would let a blank line in a .env satisfy a required secret.
	 */
	private static function lookupEnv( string $name ): ?string {
		if( self::isBlockedName( $name ) ) {
			return null;
		}

		$value = null;

		if( array_key_exists( $name, $_ENV ) ) {
			$value = (string)$_ENV[ $name ];
		}
		elseif( array_key_exists( $name, $_SERVER ) && is_scalar( $_SERVER[ $name ] ) ) {
			$value = (string)$_SERVER[ $name ];
		}
		else {
			$fromGetenv = getenv( $name );
			if( $fromGetenv!==false ) {
				$value = $fromGetenv;
			}
		}

		return ( $value===null || $value==='' ) ? null : $value;
	}


	/**
	 * Whether a reference is satisfied by the current environment, by either the plain name
	 * or its {NAME}_FILE companion.
	 *
	 * Shared with `gf env --list` so the diagnostic and the resolver cannot disagree about
	 * what "set" means. The command reimplemented this inline and got two things wrong that
	 * lookupEnv() already handles: it skipped the blocked-CGI-name rule, and its
	 * `?? ... ?: ''` chain collapsed a legitimate "0" to '' — reporting MISSING for every
	 * variable holding the value that %env(bool:...)% false is written as.
	 */
	public static function isSatisfied( string $name ): bool {
		return self::lookupEnv( $name )!==null || self::lookupEnv( $name . self::SECRET_FILE_SUFFIX )!==null;
	}


	/**
	 * Whether a name is reserved: a CGI meta-variable name this resolver never satisfies
	 * from the ambient environment (see the class docblock). Public so `gf env` can say
	 * "reserved — rename it" instead of reporting an unsatisfiable variable as MISSING
	 * and writing a dead line into .env for the developer to fill in forever.
	 */
	public static function isReservedName( string $name ): bool {
		return self::isBlockedName( $name );
	}

}
