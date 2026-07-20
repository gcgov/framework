<?php

namespace gcgov\framework\cli;

/**
 * A user-facing gf error. The message is printed as-is (no stack trace unless
 * -v is passed), so keep messages short and actionable.
 */
class cliException extends \Exception {

}
