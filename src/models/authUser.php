<?php
namespace gcgov\framework\models;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Class authUser
 * Singleton to store authenticated user globally
 * @OA\Schema()
 */
#[TypeScript]
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
     * @return \gcgov\framework\models\authUser
     */
	final public static function getInstance(): authUser {
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
	 *
	 * @return string[]
	 */
	final public function __sleep(): array {
		return [];
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
		$this->roles            = self::normalizeRoles( $tokenScopes );

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
		$this->roles            = self::normalizeRoles( $user->getRoles() );
		return self::getInstance();
	}


	/**
	 * Narrow roles to strings.
	 *
	 * $roles is documented string[] but nothing enforced it, and both sources are untyped:
	 * the token's `scope` claim is whatever JSON it carried, and the user model's roles
	 * field is whatever the collection holds — BSON deserialization passes scalars through
	 * untouched. A single non-string truthy element (roles: [true]) satisfied a loose
	 * in_array() against EVERY required role, so a token carrying one authorized every
	 * gated route. Narrowing here covers both setters and makes the strict comparisons in
	 * hasRole() and the auth guard meaningful.
	 *
	 * @param  mixed[]  $roles
	 *
	 * @return string[]
	 */
	private static function normalizeRoles( array $roles ): array {
		return array_values( array_filter( $roles, static fn( $role ): bool => is_string( $role ) ) );
	}


	public function hasRole( string $role ): bool {
		return in_array( $role, $this->roles, true );
	}

}
