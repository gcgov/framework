<?php

namespace gcgov\framework\cli\commands;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\cliException;
use gcgov\framework\config;
use gcgov\framework\exceptions\modelException;
use gcgov\framework\interfaces\auth\user as userInterface;
use gcgov\framework\services\request;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand( name: 'user:create', description: 'Create (or update) an application user, saved through the application\'s own user model' )]
final class userCreateCommand extends Command {

	/** Characters a generated password is drawn from: unambiguous, and safe to paste into a shell. */
	private const PASSWORD_ALPHABET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	private const PASSWORD_LENGTH = 24;


	protected function configure(): void {
		$this->addOption( 'email', null, InputOption::VALUE_REQUIRED, 'The user\'s email address. Also the default username.' );
		$this->addOption( 'password', null, InputOption::VALUE_REQUIRED, 'Password. Omit to have one generated and printed once.' );
		$this->addOption( 'name', null, InputOption::VALUE_REQUIRED, 'Display name' );
		$this->addOption( 'username', null, InputOption::VALUE_REQUIRED, 'Username to sign in with. Defaults to the email address.' );
		$this->addOption( 'roles', null, InputOption::VALUE_REQUIRED, 'Comma separated authorization roles, e.g. "User.Read,User.Write"' );
		$this->addOption( 'force', null, InputOption::VALUE_NONE, 'Update the existing user with this email instead of refusing' );
		$this->setHelp( <<<'HELP'
			Create the account an application is signed into with.

			An application whose config.json enables services.auth starts with no way in:
			blockNewUsers defaults to true, so only users already in the database may sign
			in, and every /user route requires a caller already holding User.Write. Nothing
			can authenticate, so nothing can create the first user. A direct mongosh insert
			cannot break the cycle either — the user model hashes the password as it writes,
			so a hand written document has no password anyone can sign in with.

			  gf user:create --email=dev@example.test --roles="User.Read,User.Write"

			The user is saved through the model the application actually resolves —
			\app\models\user when it defines one, otherwise the framework's Mongo user model
			— so the password is hashed and every model hook runs exactly as they do when the
			application writes a user itself. Pass the password rather than having one
			generated when you would otherwise be copying it out of the terminal anyway.

			Writing a user is a transactional write, so the database must be a replica set
			(or mongos). A standalone mongod fails this command, and every other write the
			application makes, with "Transaction numbers are only allowed on a replica set
			member or mongos".

			An email that already exists is refused unless --force, which updates that user
			in place. On an update, an option you do not pass is left as it is — including
			the password, so --force is safe to use to add a role.
			HELP );
	}


	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$context = appContext::require();
		$context->assertAppLoadable();

		$io = new SymfonyStyle( $input, $output );

		$email = trim( (string)( $input->getOption( 'email' ) ?? '' ) );
		if( $email==='' ) {
			throw new cliException( '--email is required.' );
		}

		/** @var class-string<userInterface> $userClass */
		$userClass = request::getUserClassFqdn();

		$existing = self::findByEmail( $userClass, $email );
		if( $existing!==null && !$input->getOption( 'force' ) ) {
			throw new cliException( 'A user with the email ' . $email . ' already exists. Pass --force to update it in place.' );
		}

		// Generated only for a NEW user with no --password. On an update, an omitted
		// password means "leave the stored one alone" — the model unsets an empty password
		// rather than writing it, so a --force run adding a role must not invent one.
		$password  = (string)( $input->getOption( 'password' ) ?? '' );
		$generated = $password==='' && $existing===null;
		if( $generated ) {
			$password = self::generatePassword();
		}

		$user = $existing ?? new $userClass();
		self::applyTo( $user, [
			'email'    => $email,
			'username' => (string)( $input->getOption( 'username' ) ?? '' ),
			'name'     => (string)( $input->getOption( 'name' ) ?? '' ),
			'password' => $password,
			'roles'    => $input->getOption( 'roles' )===null ? null : self::parseRoles( (string)$input->getOption( 'roles' ) ),
		] );

		try {
			$userClass::save( $user );
		}
		catch( modelException $e ) {
			throw new cliException( 'Saving the user failed: ' . $e->getMessage() . self::saveHint( $e ), 0, $e );
		}

		$io->success( ( $existing===null ? 'Created ' : 'Updated ' ) . $email );
		$io->text( 'id:       ' . (string)$user->getId() );
		$io->text( 'username: ' . $user->getUsername() );
		$io->text( 'roles:    ' . ( count( $user->getRoles() )>0 ? implode( ', ', $user->getRoles() ) : '(none — every role gated route will answer 403)' ) );
		if( $generated ) {
			$io->text( 'password: ' . $password );
			$io->warning( 'This password is shown once and is not recoverable — it is stored hashed.' );
		}

		self::warnAboutSignIn( $io );

		return Command::SUCCESS;
	}


	/**
	 * Copy the requested values onto a user model.
	 *
	 * Pure, and deliberately typed against a plain object rather than the user interface:
	 * it writes the trait's public properties, which the interface only exposes as getters.
	 * Keeping it here rather than inline in execute() is what lets the test drive the
	 * mapping — role parsing, the username default, the empty-password rule — without a
	 * database.
	 *
	 * A null entry means "not supplied": the property is left as it is, which on an update
	 * preserves the stored value. An empty password is likewise left alone, because the
	 * model's _beforeBsonSerialize() unsets an empty password rather than storing it, and
	 * hashing happens there — never here, or the hash would be hashed again and no password
	 * would ever verify.
	 *
	 * @param  array{email: string, username?: string, name?: string, password?: string, roles?: string[]|null}  $options
	 */
	public static function applyTo( object $user, array $options ): void {
		$email = trim( $options[ 'email' ] );

		$user->email = $email;

		// The username is what verifyUsernamePassword() matches first, so a user created
		// without one could never sign in by username. The email is the sensible default
		// and is what every caller of this command would otherwise type twice.
		$username = trim( (string)( $options[ 'username' ] ?? '' ) );
		if( $username!=='' || !isset( $user->username ) || $user->username==='' ) {
			$user->username = $username!=='' ? $username : $email;
		}

		$name = trim( (string)( $options[ 'name' ] ?? '' ) );
		if( $name!=='' ) {
			$user->name = $name;
		}

		$password = (string)( $options[ 'password' ] ?? '' );
		if( $password!=='' ) {
			$user->password = $password;
		}

		if( isset( $options[ 'roles' ] ) ) {
			$user->roles = $options[ 'roles' ];
		}

		$user->active = true;

		// A model whose $_id is a typed, non-nullable ObjectId is read unconditionally by
		// factory::save() to build its update filter, so leaving it uninitialized is a fatal
		// Error rather than an insert. The framework's own user model assigns one in its
		// constructor; an application model that does not still gets one here.
		if( !isset( $user->_id ) ) {
			$user->_id = new \MongoDB\BSON\ObjectId();
		}
	}


	/**
	 * Split a --roles value into role names.
	 *
	 * Pure. Empty entries are dropped so a trailing comma, or the empty string, means no
	 * roles rather than one role named "".
	 *
	 * @return string[]
	 */
	public static function parseRoles( string $roles ): array {
		$parsed = [];
		foreach( explode( ',', $roles ) as $role ) {
			$role = trim( $role );
			if( $role!=='' && !in_array( $role, $parsed, true ) ) {
				$parsed[] = $role;
			}
		}

		return $parsed;
	}


	/**
	 * The existing user with this email, or null when there is none.
	 *
	 * getOneByEmail() reports "not found" by throwing, which is the right shape for the
	 * request lifecycle and the wrong one here: not finding a user is this command's normal
	 * case. A modelException carrying any other status is a real failure and is re-thrown.
	 *
	 * @param  class-string<userInterface>  $userClass
	 *
	 * @throws \gcgov\framework\cli\cliException
	 */
	private static function findByEmail( string $userClass, string $email ): ?object {
		try {
			return $userClass::getOneByEmail( $email );
		}
		catch( modelException $e ) {
			if( $e->getCode()===404 ) {
				return null;
			}

			throw new cliException( 'Looking up ' . $email . ' failed: ' . $e->getMessage() . self::saveHint( $e ), 0, $e );
		}
	}


	/**
	 * The failure a developer running this on a fresh local stack is most likely to hit is
	 * a standalone mongod, whose driver message ("Transaction numbers are only allowed on
	 * a replica set member or mongos") explains what happened but not what to do about it.
	 */
	private static function saveHint( \Throwable $e ): string {
		$message = $e->getMessage() . ( $e->getPrevious()?->getMessage() ?? '' );
		if( stripos( $message, 'transaction numbers are only allowed' )===false ) {
			return '';
		}

		return ' The database is a standalone mongod, and every write this framework makes runs in a transaction. Run MongoDB as a replica set — the application template\'s docker-compose.yml starts one.';
	}


	/**
	 * What stands between this account and a working sign-in, when it is not simply "nothing".
	 *
	 * Both cases are ones where the command succeeds and the account still cannot be used, which
	 * is the failure worth saying out loud — the same reason readiness checks the signing keys
	 * rather than letting an unusable deployment report itself healthy.
	 */
	private static function warnAboutSignIn( SymfonyStyle $io ): void {
		try {
			if( config::getServices()->auth===null ) {
				$io->warning( 'config.json does not enable services.auth, so nothing in this application signs a user in. The account is stored, but no route will accept it.' );

				return;
			}

			if( config::getSettings()->forceMfaForPasswordUsers ) {
				$io->warning( 'settings.forceMfaForPasswordUsers is on, so this account cannot sign in with its password alone. The first POST /auth/authorize returns an MFA enrolment challenge and a token carrying NO roles; the account can do nothing until it completes POST /auth/verifyMfaSecret and then POST /auth/verifyMfaCode.' );
			}
		}
		catch( \Throwable ) {
			// Configuration that does not resolve cannot have got this far — the save above
			// reads it. Nothing to warn about that the caller has not already seen.
		}
	}


	private static function generatePassword(): string {
		$password = '';
		$max      = strlen( self::PASSWORD_ALPHABET ) - 1;
		for( $i = 0; $i<self::PASSWORD_LENGTH; $i++ ) {
			$password .= self::PASSWORD_ALPHABET[ random_int( 0, $max ) ];
		}

		return $password;
	}

}
