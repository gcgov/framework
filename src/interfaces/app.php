<?php

namespace gcgov\framework\interfaces;


interface app extends lifecycle\before, lifecycle\after {

	/**
	 * Return an array of the namespaces where framework services installed via composer
	 * @return string[]
	 */
	public function registerFrameworkServiceNamespaces() : array;

}
