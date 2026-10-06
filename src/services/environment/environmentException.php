<?php

declare(strict_types=1);

namespace gcgov\framework\services\environment;

/**
 * Thrown by {@see envVarResolver} when a `%env(...)%` reference cannot be
 * resolved (e.g. a required environment variable is missing).
 *
 * This is a neutral, layer-agnostic exception. Each call site wraps it in the
 * exception type appropriate for that layer: {@see \gcgov\framework\config}
 * rethrows it as a `configException`, while the gf CLI
 * ({@see \gcgov\framework\cli\appContext}) rethrows it as a `cliException`.
 */
final class environmentException extends \Exception {

}
