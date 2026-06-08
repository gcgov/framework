<?php

namespace gcgov\framework\traits;

/**
 * @internal
 *
 * Bridge class consumed by PHPStan. The framework's only first-party consumer
 * of {@see userTrait} is {@see \gcgov\framework\services\mongodb\models\auth\user},
 * which is excluded from static analysis due to PHP 8.4 property-hook
 * patterns. Without this bridge PHPStan reports the trait itself as unused.
 *
 * Apps that consume the framework use {@see userTrait} directly on their
 * own custom user model classes; this bridge has no runtime callers.
 */
abstract class _userTraitAnalysisTarget {
	use userTrait;
}
