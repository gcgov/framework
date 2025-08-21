<?php

namespace gcgov\framework\services\mongodb\tools\reflectionCache;

use gcgov\framework\services\mongodb\exceptions\databaseException;

/**
 * Holds hydrated runtime metadata (ReflectionClass + reflectionCacheProperty objects)
 * built from a stable snapshot.
 */
final class reflectionCacheClass {
	use reflectionCacheAttributeTrait;

	public string $classFQN   = '';
	public string $name       = '';
	public string $fileName   = '';
	public int    $filemtime  = 0;

	public bool   $isCloneable = true;

	/** @var \ReflectionClass */
	public \ReflectionClass $reflectionClass;

	/** @var array<string, reflectionCacheProperty> */
	public array $properties = [];

	/** @var array<string, array<string, array{args: array}>> attributeName => [propertyName => snapshot] */
	private array $attributeIndex = [];

	/**
	 * Build a stable snapshot (plain array) from ReflectionClass
	 */
	public static function buildSnapshotFromReflection(\ReflectionClass $rClass): array {
		$file = $rClass->getFileName() ?: '';
		$mtime = $file !== '' ? @filemtime($file) ?: 0 : 0;

		$defaults = $rClass->getDefaultProperties();
		$classAttrSnapshot = [];
		foreach ($rClass->getAttributes() as $a) {
			$classAttrSnapshot[$a->getName()] = ['args' => $a->getArguments()];
		}

		// cloneability memo for property declared types
		$typeCloneableMemo = [];

		$props = [];
		$attrIndex = [];

		foreach ($rClass->getProperties() as $rp) {
			// gather property attributes once
			$propAttrSnapshot = [];
			foreach ($rp->getAttributes() as $a) {
				$propAttrSnapshot[$a->getName()] = ['args' => $a->getArguments()];
				$attrIndex[$a->getName()][$rp->getName()] = $propAttrSnapshot[$a->getName()];
			}

			// Determine the "effective" type for feature flags:
			// - if declared 'array', try @var Some\Class[] to get element type
			// - else use declared named type
			$declaredTypeName = '';
			if ($rp->hasType()) {
				$t = $rp->getType();
				if ($t instanceof \ReflectionNamedType) {
					$declaredTypeName = $t->getName();
				}
			}

			$docType = \gcgov\framework\services\mongodb\typeHelpers::getVarTypeFromDocComment($rp->getDocComment() ?: '');
			$effectiveTypeName = $declaredTypeName;

			if ($declaredTypeName === 'array' && $docType && $docType !== 'array') {
				// treat typed array element class as effective type
				$effectiveTypeName = $docType;
			} elseif (!$rp->hasType() && $docType && $docType !== 'array') {
				// untyped property but documented class
				$effectiveTypeName = $docType;
			}

			// Normalize to FQN if it's a class-like name
			$skipScalars = ['','array','mixed','string','int','float','bool','callable','iterable','object'];
			$typeFqn = '';
			if (!in_array($effectiveTypeName, $skipScalars, true)) {
				$typeFqn = \gcgov\framework\services\mongodb\typeHelpers::classNameToFqn($effectiveTypeName);
			}

			// compute cloneability for declared class type (not for built-ins)
			$typeIsCloneable = false;
			if ($typeFqn !== '') {
				if (!isset($typeCloneableMemo[$typeFqn])) {
					try {
						$typeCloneableMemo[$typeFqn] = (new \ReflectionClass($typeFqn))->isCloneable();
					} catch (\Throwable) {
						$typeCloneableMemo[$typeFqn] = false;
					}
				}
				$typeIsCloneable = $typeCloneableMemo[$typeFqn];
			}

			// NEW: compute type feature flags once
			$isEnum        = ($typeFqn !== '' && \enum_exists($typeFqn));
			$enumIsBacked  = $isEnum && \is_subclass_of($typeFqn, \BackedEnum::class);
			$isDateTime    = ($typeFqn !== '' && \is_a($typeFqn, \DateTimeInterface::class, true));
			$isPersistable = ($typeFqn !== '' && \is_subclass_of($typeFqn, \MongoDB\BSON\Persistable::class));
			$isEmbeddable  = ($typeFqn !== '' && \is_subclass_of($typeFqn, \gcgov\framework\services\mongodb\embeddable::class));

			$props[$rp->getName()] = [
				'declaring'  => $rp->getDeclaringClass()->getName(),
				'defaults'   => $defaults,
				'attr'       => $propAttrSnapshot,
				'typeClone'  => $typeIsCloneable,
				// pass effective type and flags to snapshot (optional to keep typeFqn)
				'typeFqn'    => $typeFqn,
				'typeFlags'  => [
					'isEnum'        => $isEnum,
					'enumIsBacked'  => $enumIsBacked,
					'isDateTime'    => $isDateTime,
					'isPersistable' => $isPersistable,
					'isEmbeddable'  => $isEmbeddable,
				],
			];
		}

		return [
			'class'      => $rClass->getName(),
			'name'       => $rClass->getShortName(),
			'file'       => $file,
			'filemtime'  => $mtime,
			'isCloneable'=> $rClass->isCloneable(),
			'attributes' => $classAttrSnapshot,
			'properties' => $props,
			'attrIndex'  => $attrIndex,
		];
	}


	/**
	 * Hydrate runtime object from snapshot
	 */
	public static function fromSnapshot(array $snap, \ReflectionClass $rClass): self {
		$self = new self();

		$self->classFQN    = $snap['class'];
		$self->name        = $snap['name'];
		$self->fileName    = $snap['file'] ?? '';
		$self->filemtime   = (int)($snap['filemtime'] ?? 0);
		$self->isCloneable = (bool)($snap['isCloneable'] ?? true);

		$self->reflectionClass = $rClass;

		$self->setAttributeSnapshot($snap['attributes'] ?? []);
		$self->attributeIndex = $snap['attrIndex'] ?? [];

		$defaults = $rClass->getDefaultProperties();

		// Build property objects
		foreach ($rClass->getProperties() as $rp) {
			$pName = $rp->getName();
			$pSnap = $snap['properties'][$pName] ?? ['attr'=>[], 'typeClone'=>false, 'typeFlags'=>[]];

			$propAttr  = $pSnap['attr']      ?? [];
			$typeClone = (bool)($pSnap['typeClone'] ?? false);
			$typeFlags = (array)($pSnap['typeFlags'] ?? []);

			$prop = reflectionCacheProperty::fromReflection(
				$rp,
				$defaults,
				$propAttr,
				$typeClone,
				$typeFlags
			);

			$self->properties[$pName] = $prop;
		}

		return $self;
	}

	/**
	 * Property names and snapshots for a given attribute
	 * @return array<string, array{args: array}>
	 */
	public function getPropertyAttributeSnapshot(string $attributeName): array {
		return $this->attributeIndex[$attributeName] ?? [];
	}

	/**
	 * Instantiate and return attribute instances keyed by property name
	 * @return array<string, object>
	 * @throws databaseException
	 */
	public function getAttributeInstancesByPropertyName(string $attributeName): array {
		$result = [];
		$map = $this->getPropertyAttributeSnapshot($attributeName);
		foreach ($map as $propName => $_) {
			$prop = $this->properties[$propName] ?? null;
			if ($prop) {
				$inst = $prop->getAttributeInstance($attributeName);
				if ($inst !== null) {
					$result[$propName] = $inst;
				}
			}
		}
		return $result;
	}

	/**
	 * Fast filter: properties that have a given attribute
	 * @return array<string, reflectionCacheProperty>
	 */
	public function getPropertiesWithAttribute(string $attributeName): array {
		$out = [];
		foreach (\array_keys($this->getPropertyAttributeSnapshot($attributeName)) as $p) {
			if (isset($this->properties[$p])) {
				$out[$p] = $this->properties[$p];
			}
		}
		return $out;
	}
}
