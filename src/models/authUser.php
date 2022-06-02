<?php
namespace gcgov\framework\models;

/**
 * Class auth
 * Singleton to store authenticated user globally
 * @OA\Schema()
 */
class authUser extends \gcgov\framework\interfaces\singleton {

	/** @OA\Property() */
	public string $userId = '';

	/** @OA\Property() */
	public string $externalId = '';

	/** @OA\Property() */
	public string $externalProvider = '';

	/** @OA\Property() */
	public string $name = '';

	/** @OA\Property() */
	public string $username = '';

	/** @OA\Property() */
	public string $email = '';

	/**
	 * @OA\Property()
	 * @var string[]
	 */
	public array $roles = [];


	public function toJwtData(): array {
		return [
			'userId'           => $this->userId,
			'username'         => $this->username,
			'externalId'       => $this->externalId,
			'externalProvider' => $this->externalProvider,
			'name'             => $this->name,
			'email'            => $this->email,
			'roles'            => $this->roles
		];
	}

	/**
	 * @param array $tokenUser
	 * @param array $tokenScopes
	 *
	 * @return \gcgov\framework\models\authUser
	 */
	public function setFromJwtToken( array $tokenUser, array $tokenScopes ): authUser {
		$this->userId           = $tokenUser[ 'userId' ] ?? '';
		$this->username         = $tokenUser[ 'username' ] ?? '';
		$this->externalId       = $tokenUser[ 'externalId' ] ?? '';
		$this->externalProvider = $tokenUser[ 'externalProvider' ] ?? '';
		$this->name             = $tokenUser[ 'name' ] ?? '';
		$this->email            = $tokenUser[ 'email' ] ?? '';
		$this->roles            = $tokenScopes;

		return self::getInstance();
	}

	public function hasRole( string $role ): bool {
		return in_array( $role, $this->roles );
	}

}