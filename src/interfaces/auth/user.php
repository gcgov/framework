<?php

namespace gcgov\framework\interfaces\auth;

interface user {

	public function getId(): string|int|\MongoDB\BSON\ObjectId;


	public function getName(): string;


	public function getUsername(): string;


	public function getPassword(): string;


	public function getOauthId(): string;


	public function getOauthProvider(): string;


	public function getEmail(): string;


	/**
	 * @return string[]
	 */
	public function getRoles(): array;


	public function getActive(): bool;


	public static function getFromOauth( string $email, string $externalId, string $externalProvider, ?string $firstName = '', ?string $lastName = '', bool $addIfNotExisting = false ): self;


	public static function verifyUsernamePassword( string $username, string $password ): self;


	public static function getOneByExternalId( string $externalId ): self;


	public static function getOneByEmail( string $email ): self;


	public static function getOne( \MongoDB\BSON\ObjectId|string|int $_id ): self;
	public static function save( object &$object ): mixed;

}
