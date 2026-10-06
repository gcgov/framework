<?php

namespace gcgov\framework\models;

/**
 * @deprecated v7 — environmentConfig was merged into {@see unifiedConfig}. This
 *             autoloadable alias keeps v6 type references working (parameter/return
 *             type-hints, instanceof, static jsonDeserialize calls) until call
 *             sites migrate; unifiedConfig carries every former field and helper.
 */
\class_alias( unifiedConfig::class, __NAMESPACE__ . '\environmentConfig' );
