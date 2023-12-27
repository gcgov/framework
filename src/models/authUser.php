<?php
namespace gcgov\framework\models;

/**
 * Class authUser
 * Singleton to store authenticated user globally
 * @OA\Schema()
 */
class authUser {

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

	private static authUser $instance;

	private function __construct() {
	}


	/**
	 * @return $this
	 */
	final public static function getInstance(): static {
		$calledClass = get_called_class();

		if( !isset( self::$instance ) ) {
			self::$instance = new $calledClass();
		}

		return self::$instance;
	}

	/**
	 * Avoid clone instance
	 */
	final public function __clone() {
	}

	/**
	 * Avoid serialize instance
	 */
	final public function __sleep() {
	}

	/**
	 * Avoid unserialize instance
	 */
	final public  function __wakeup() {
	}

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
	public function setFromJwtToken( array $tokenUser, array $tokenScopes ): self {
		$this->userId           = $tokenUser[ 'userId' ] ?? '';
		$this->username         = $tokenUser[ 'username' ] ?? '';
		$this->externalId       = $tokenUser[ 'externalId' ] ?? '';
		$this->externalProvider = $tokenUser[ 'externalProvider' ] ?? '';
		$this->name             = $tokenUser[ 'name' ] ?? '';
		$this->email            = $tokenUser[ 'email' ] ?? '';
		$this->roles            = $tokenScopes;

		return self::getInstance();
	}

	/**
	 * @param \gcgov\framework\interfaces\auth\user $user
	 *
	 * @return self
	 */
	public function setFromUser( \gcgov\framework\interfaces\auth\user $user ): self {
		$this->userId           = $user->getId();
		$this->externalId       = $user->getOauthId();
		$this->externalProvider = $user->getOauthProvider();
		$this->name             = $user->getName();
		$this->username         = $user->getUsername();
		$this->email            = $user->getEmail();
		$this->roles            = $user->getRoles();
		return self::getInstance();
	}

	public function hasRole( string $role ): bool {
		return in_array( $role, $this->roles );
	}

}
