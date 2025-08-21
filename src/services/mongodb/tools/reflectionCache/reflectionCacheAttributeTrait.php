<?php

namespace gcgov\framework\services\mongodb\tools\reflectionCache;

use gcgov\framework\services\mongodb\exceptions\databaseException;

/**
 * Stores raw ReflectionAttribute objects or snapshot arrays of [name => args].
 * Lazily instantiates attribute objects when requested.
 */
trait reflectionCacheAttributeTrait {

	/** @var \ReflectionAttribute[] */
	public array $reflectionAttributes = [];

	/** @var array<string, array{args: array}> */
	public array $attributeArgsByName = [];

	private array $_attributeInstances = [];

	/**
	 * @param \ReflectionAttribute[] $reflectionAttributes
	 */
	public function setReflectionAttributes(array $reflectionAttributes): void {
		$this->reflectionAttributes = $reflectionAttributes;
		$this->attributeArgsByName  = [];
		foreach ($reflectionAttributes as $attr) {
			$this->attributeArgsByName[$attr->getName()] = ['args' => $attr->getArguments()];
		}
	}

	/**
	 * Accepts a snapshot form: [ name => ['args'=>[]], ... ]
	 */
	public function setAttributeSnapshot(array $snapshot): void {
		$this->reflectionAttributes = [];
		$this->attributeArgsByName  = $snapshot;
	}

	public function hasAttribute(string $name): bool {
		return isset($this->attributeArgsByName[$name]);
	}

	/**
	 * Returns ['args'=>[]] or null
	 */
	public function getAttribute(string $name): ?array {
		return $this->attributeArgsByName[$name] ?? null;
	}

	/**
	 * @return mixed attribute instance (constructed with stored args)
	 * @throws databaseException
	 */
	public function getAttributeInstance(string $name): mixed {
		if (!isset($this->_attributeInstances[$name])) {
			$args = $this->attributeArgsByName[$name]['args'] ?? null;
			if ($args === null) {
				return null;
			}
			try {
				$this->_attributeInstances[$name] = new $name(...$args);
			}
			catch (\Throwable $e) {
				throw new databaseException('Failed to instantiate attribute '.$name.': '.$e->getMessage(), 500, $e);
			}
		}
		return $this->_attributeInstances[$name];
	}
}
