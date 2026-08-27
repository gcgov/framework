<?php

namespace gcgov\framework\interfaces;


/**
 * The application's own router — \app\router.
 *
 * It is a {@see router} like any other, plus the two lifecycle hooks the framework
 * actually calls, plus one declaration no service router needs to make.
 */
interface appRouter extends router, lifecycle\before, lifecycle\after {

	/**
	 * Does this application authenticate its own routes?
	 *
	 * The framework refuses to boot when routes declare authentication:true and no
	 * authentication service is enabled, because such routes would be reachable by
	 * anyone: authentication() is the only guard left, and returning true from it — as
	 * the scaffolded implementation does — admits every caller.
	 *
	 * Return true only if this router's own authentication() genuinely establishes and
	 * verifies the caller's identity. Returning true without doing so re-opens exactly
	 * the hole the check exists to close.
	 */
	public function providesAuthentication() : bool;
}
