<?php

namespace gcgov\framework\traits;

use andrewsauder\jsonDeserialize\attributes\excludeJsonSerialize;
use gcgov\framework\services\mongodb\attributes\label;
use OpenApi\Attributes as OA;

trait userTrait {

	#[label( 'Name' )]
	#[OA\Property()]
	public string $name = '';

	#[label( 'Username' )]
	#[OA\Property()]
	public string $username = '';

	#[OA\Property()]
	public string $oauthId = '';

	#[OA\Property()]
	public string $oauthProvider = '';

	#[label( 'Email' )]
	#[OA\Property()]
	public string $email = '';

	#[label( 'Password' )]
	#[excludeJsonSerialize]
	#[OA\Property()]
	public string $password = '';

	#[label( 'Authorization Roles' )]
	#[OA\Property()]
	/** @var string[] $roles */
	public array $roles = [];

	#[label( 'Active' )]
	#[OA\Property()]
	public bool $active = true;


	public function getName(): string {
		return $this->name;
	}


	public function getUsername(): string {
		return $this->username;
	}


	public function getPassword(): string {
		return $this->password;
	}


	public function getOauthId(): string {
		return $this->oauthId;
	}


	public function getOauthProvider(): string {
		return $this->oauthProvider;
	}


	public function getEmail(): string {
		return $this->email;
	}


	/** @return string[] */
	public function getRoles(): array {
		return $this->roles;
	}


	public function getActive(): bool {
		return $this->active;
	}

}
