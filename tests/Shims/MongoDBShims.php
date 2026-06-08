<?php

declare(strict_types=1);

namespace MongoDB\Driver\Exception {

	if ( !interface_exists( Exception::class, false ) ) {
		interface Exception {}
	}

	if ( !class_exists( RuntimeException::class, false ) ) {
		class RuntimeException extends \RuntimeException implements Exception {}
	}

	if ( !class_exists( InvalidArgumentException::class, false ) ) {
		class InvalidArgumentException extends \InvalidArgumentException implements Exception {}
	}

	if ( !class_exists( CommandException::class, false ) ) {
		class CommandException extends RuntimeException {}
	}

	if ( !class_exists( UnexpectedValueException::class, false ) ) {
		class UnexpectedValueException extends \UnexpectedValueException implements Exception {}
	}
}

namespace MongoDB\BSON {

	interface Type {}

	interface Serializable extends Type {
		public function bsonSerialize(): array|object;
	}

	interface Unserializable {
		public function bsonUnserialize( array $data ): void;
	}

	interface Persistable extends Serializable, Unserializable {}

	interface ObjectIdInterface extends \Stringable {
		public function __toString(): string;
		public function getTimestamp(): int;
	}

	if ( !class_exists( ObjectId::class, false ) ) {
		final class ObjectId implements Type, ObjectIdInterface, \JsonSerializable {
			private string $hex;

			public function __construct( ?string $id = null ) {
				if ( $id === null ) {
					$this->hex = bin2hex( random_bytes( 12 ) );
				}
				else {
					if ( !preg_match( '/^[0-9a-f]{24}$/i', $id ) ) {
						throw new \MongoDB\Driver\Exception\InvalidArgumentException( 'Invalid ObjectId hex string' );
					}
					$this->hex = strtolower( $id );
				}
			}

			public function __toString(): string { return $this->hex; }
			public function jsonSerialize(): array { return [ '$oid' => $this->hex ]; }
			public function getTimestamp(): int { return (int) hexdec( substr( $this->hex, 0, 8 ) ); }
		}
	}

	if ( !class_exists( UTCDateTime::class, false ) ) {
		final class UTCDateTime implements Type, \JsonSerializable, \Stringable {
			private int $milliseconds;

			public function __construct( int|float|string|\DateTimeInterface|null $milliseconds = null ) {
				if ( $milliseconds === null ) {
					$this->milliseconds = (int) ( microtime( true ) * 1000 );
				}
				elseif ( $milliseconds instanceof \DateTimeInterface ) {
					$this->milliseconds = (int) ( $milliseconds->format( 'U.u' ) * 1000 );
				}
				else {
					$this->milliseconds = (int) $milliseconds;
				}
			}

			public function __toString(): string { return (string) $this->milliseconds; }
			public function jsonSerialize(): array { return [ '$date' => [ '$numberLong' => (string) $this->milliseconds ] ]; }
			public function toDateTime(): \DateTime { return ( new \DateTime() )->setTimestamp( intdiv( $this->milliseconds, 1000 ) ); }
		}
	}

	if ( !class_exists( Binary::class, false ) ) {
		final class Binary implements Type, \JsonSerializable, \Stringable {
			public function __construct( private string $data, private int $type = 0 ) {}
			public function __toString(): string { return $this->data; }
			public function getData(): string { return $this->data; }
			public function getType(): int { return $this->type; }
			public function jsonSerialize(): array { return [ '$binary' => [ 'base64' => base64_encode( $this->data ), 'subType' => sprintf( '%02x', $this->type ) ] ]; }
		}
	}
}
