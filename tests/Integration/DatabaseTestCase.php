<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Service\RuleService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Base class for FCIAS integration tests that need a real database.
 *
 * Provides:
 * - NC-bootstrapped IDBConnection via \OCP\Server
 * - Table prefix helper
 * - makeAccount(), which provisions a randomly named account with a
 *   random password for the length of the run, and removes it afterwards
 * - preserveStoredRules(), which puts the instance's rule set back after
 *   a test that had to replace it
 * - after the class, this app's rows for the files its tests made and
 *   removed without the delete event, so none is left queued
 * - assertTableExists / assertColumnExists / assertTableNotExists
 * - Transaction-wrapped setUp/tearDown (subclasses opt in via beginTransaction)
 *
 * Extend this for any test that needs real MariaDB access through
 * the Nextcloud ddev container.
 */
abstract class DatabaseTestCase
    extends
    TestCase
{

//  protected properties

	protected IDBConnection $db;


//  private properties

	private bool            $inTransaction = false;

	/** Cache for dbtableprefix (lazy-loaded). */
	private ?string $tablePrefix = null;

	/**
	 * Accounts {@see makeAccount()} created for this run, and so the only
	 * ones it may delete again.
	 *
	 * @var list<string>
	 */
	private static array $provisionedUsers = [];

	/**
	 * The highest file id when the class began: the files its tests make
	 * lie above it, and what was there before is not theirs to touch.
	 */
	private static ?int $fileIdFloor = null;


//  constants

	/** Where the rules live, for {@see preserveStoredRules()}. */
	private const RULES_CONFIG_KEY = 'rule_definitions';

	/** The stored rules as this test found them, or null if not preserved. */
	private ?string $rulesBefore = null;


//  getters / setters / is* / has*

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->db = Server::get( IDBConnection::class );
	}


//  other non-static methods

	/**
	 * Begin a transaction that will be rolled back in tearDown().
	 *
	 * Call this from your test's setUp() or at the start of each test
	 * method when you need isolation.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function beginTransaction(): void
	{
		if ( $this->inTransaction )
		{
			return;
		}

		$this->db->beginTransaction();
		$this->inTransaction = true;
	}


//  static methods

	// ─── accounts a test needs ───────────────────────────────────────
	/**
	 * Make an account for this run, and hand back how to authenticate as it.
	 *
	 * A test that needs an account other than `admin` used to name a fixed
	 * one, state it as a prerequisite in a docblock, and error ten times
	 * over when nobody had read it — with `NoUserException: Backends
	 * provided no user object`, which names neither the account nor the fact
	 * that creating it is the remedy.
	 *
	 * It is created here instead, and deleted again in
	 * {@see tearDownAfterClass()}. Three things about how:
	 *
	 * - **A fresh account every run, never a reused one.** A test can only
	 *   authenticate as an account whose password it knows, and the only
	 *   way to know a pre-existing account's password is to have written it
	 *   down in the source — which is precisely what should not be there.
	 *   Creating the account is what makes a secret-free test possible.
	 * - **A random name.** `$base` is a prefix, not the account. Nothing
	 *   collides with an account somebody made by hand, so nothing has to
	 *   decide whether it may delete one — this only ever removes what it
	 *   made, and an account that is already there is left entirely alone,
	 *   along with its files.
	 * - **A random password, discarded when the run ends.** It exists in
	 *   this process and nowhere else. If teardown fails and the account
	 *   survives, what survives is an obviously-ephemeral name with a
	 *   password nobody holds, rather than a predictable account with a
	 *   published one.
	 *
	 * If it cannot be created — a password policy, a backend that refuses —
	 * the suite is skipped with the reason, rather than failing every test
	 * in it for a cause none of them mention.
	 *
	 * @return array{0: string, 1: string}  The account's id and its password.
	 */
	protected static function makeAccount( string $base ): array
	{
		$random   = Server::get( ISecureRandom::class );
		$uid      = $base . '_' . $random->generate( 8, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS );
		$password = self::strongPassword( $random );

		try
		{
			$created = Server::get( IUserManager::class )
			                 ->createUser( $uid, $password )
			;
		}
		catch ( Throwable $e )
		{
			$created = false;
			$reason  = $e->getMessage();
		}

		if ( $created === false )
		{
			self::markTestSkipped(
				sprintf(
					'FCIAS integration: could not create the test account "%s"%s. '
					. 'This suite needs an account other than admin to authenticate as.',
					$uid,
					isset( $reason )
						? ' (' . $reason . ')'
						: '',
				),
			);
		}

		self::$provisionedUsers[] = $uid;

		return [
			$uid,
			$password,
		];
	}

	/**
	 * A password no policy will refuse and nobody will guess.
	 *
	 * One character from each class the common policies ask for, then
	 * length from the full alphabet — assembled rather than generated and
	 * retried, so a strict policy cannot turn this into a loop.
	 */
	private static function strongPassword( ISecureRandom $random ): string
	{
		$password = $random->generate( 1, ISecureRandom::CHAR_UPPER )
		            . $random->generate( 1, ISecureRandom::CHAR_LOWER )
		            . $random->generate( 1, ISecureRandom::CHAR_DIGITS )
		            . $random->generate( 1, '!#$%&*+-=?@^_' )
		            . $random->generate(
			            28,
			            ISecureRandom::CHAR_ALPHANUMERIC,
		            )
		;

		return $password;
	}

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$fileIdFloor = self::highestFileId();
	}

	public static function tearDownAfterClass(): void
	{
		$userManager = Server::get( IUserManager::class );

		// The trash first, the administrator's included: a test that deletes
		// a file it made trashes it, and in this process the trash can keep
		// what the app's own delete listener would have cleared. Five runs
		// of this suite once left five hashed copies of one test file in
		// the administrator's trash, which the Duplicates page then listed.
		foreach ( array_merge( [ 'admin' ], self::$provisionedUsers ) as $uid )
		{
			self::emptyTrashOf( $uid );
		}

		foreach ( self::$provisionedUsers as $uid )
		{
			$userManager->get( $uid )
			            ?->delete()
			;
		}

		self::$provisionedUsers = [];

		self::forgetVanishedFiles();

		parent::tearDownAfterClass();
	}

	/** The filecache's highest file id, or 0 for an empty one. */
	private static function highestFileId(): int
	{
		$qb = Server::get( IDBConnection::class )->getQueryBuilder();
		$qb->select( $qb->func()->max( 'fileid' ) )
		   ->from( 'filecache' )
		;

		return (int) $qb->executeQuery()->fetchOne();
	}

	/**
	 * This app's rows for the files the class made that are gone again.
	 *
	 * A test that deletes filecache rows directly, and an account deleted
	 * above, take a file without the delete event that would take its rows;
	 * on an instance with an enabled rule each such file was queued when it
	 * was made, and its mark would stay queued for a file nobody can hash.
	 * Only files above {@see $fileIdFloor}, and only this app's keys: what
	 * was there before the class began is the instance's, not the test's.
	 * A vanished file above the floor is one the filecache numbered after
	 * every file it still holds, which earlier runs' tests made and removed:
	 * its rows go too.
	 */
	private static function forgetVanishedFiles(): void
	{
		if ( self::$fileIdFloor === null )
		{
			return;
		}

		$db = Server::get( IDBConnection::class );

		$qb = $db->getQueryBuilder();
		$qb->selectDistinct( 'i.file_id' )
		   ->from( 'files_metadata_index', 'i' )
		   ->leftJoin( 'i', 'filecache', 'f', $qb->expr()->eq( 'f.fileid', 'i.file_id' ) )
		   ->where(
			   $qb->expr()->gt( 'i.file_id', $qb->createNamedParameter( self::$fileIdFloor, IQueryBuilder::PARAM_INT ) ),
			   $qb->expr()->like( 'i.meta_key', $qb->createNamedParameter( 'file-checksum-%' ) ),
			   $qb->expr()->isNull( 'f.fileid' ),
		   )
		;
		$fileIds = array_map( intval( ... ), $qb->executeQuery()->fetchFirstColumn() );

		foreach ( array_chunk( $fileIds, 1000 ) as $chunk )
		{
			$qb = $db->getQueryBuilder();
			$qb->delete( 'files_metadata_index' )
			   ->where(
				   $qb->expr()->in( 'file_id', $qb->createNamedParameter( $chunk, IQueryBuilder::PARAM_INT_ARRAY ) ),
				   $qb->expr()->like( 'meta_key', $qb->createNamedParameter( 'file-checksum-%' ) ),
			   )
			   ->executeStatement()
			;

			// The file's document goes with it, as Nextcloud's own delete
			// would have taken it.
			$qb = $db->getQueryBuilder();
			$qb->delete( 'files_metadata' )
			   ->where( $qb->expr()->in( 'file_id', $qb->createNamedParameter( $chunk, IQueryBuilder::PARAM_INT_ARRAY ) ) )
			   ->executeStatement()
			;
		}

		self::$fileIdFloor = null;
	}

	/**
	 * Every item in $uid's trash removed for good. Not through the trashbin
	 * app's manager: in this process its backend is not registered, so the
	 * manager lists nothing while the folder fills. The folder itself, as
	 * the app's own "empty trash" deletes it once the hooks have run.
	 */
	protected static function emptyTrashOf( string $uid ): void
	{
		if ( Server::get( IUserManager::class )->get( $uid ) === null )
		{
			return;
		}

		try
		{
			$home  = Server::get( IRootFolder::class )->getUserFolder( $uid )->getParent();
			$trash = $home->get( 'files_trashbin' );

			foreach ( $trash instanceof Folder ? $trash->getDirectoryListing() : [] as $area )
			{
				$area->delete();
			}
		}
		catch ( Throwable )
		{
			// No trash, or one that cannot be read: nothing to empty.
		}
	}

	// ─── the instance's own rules ────────────────────────────────────
	/**
	 * Remember the stored rules, and put them back when the test ends.
	 *
	 * This suite runs against a live instance — a developer's, and in CI a
	 * throwaway one — and several of its tests replace the rule set to get
	 * a state they can assert on. Whoever configured that instance did not
	 * agree to have it emptied, so a test that overwrites the key restores
	 * it afterwards.
	 *
	 * Call from setUp(); {@see tearDown()} does the rest.
	 */
	protected function preserveStoredRules(): void
	{
		$this->rulesBefore = Server::get( IAppConfig::class )
		                           ->getValueString( Application::APP_ID, self::RULES_CONFIG_KEY )
		;
	}

	private function restoreStoredRules(): void
	{
		if ( $this->rulesBefore === null )
		{
			return;
		}

		Server::get( IAppConfig::class )
		      ->setValueString( Application::APP_ID, self::RULES_CONFIG_KEY, $this->rulesBefore )
		;

		// The decoded list is memoised per process and invalidated only
		// through the write path this went around.
		Server::get( RuleService::class )
		      ->loadRules( refresh: true )
		;

		$this->rulesBefore = null;
	}

	protected function tearDown(): void
	{
		$this->restoreStoredRules();

		if ( $this->inTransaction )
		{
			try
			{
				$this->db->rollBack();
			}
			catch ( Throwable )
			{
				// Connection may already be closed — ignore
			}

			$this->inTransaction = false;
		}

		parent::tearDown();
	}

	// ─── connection helpers (typed, matching DatabaseService pattern) ─
	protected function getRawConnection(): Connection
	{
		return $this->db->getInner();
	}

	/**
	 * What a write the app missed leaves: the filecache's ETag moved on.
	 *
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function etagMovesOn( File $file ): void
	{
		$this->getRawConnection()
		     ->executeStatement(
			     'UPDATE `*PREFIX*filecache` SET `etag` = ? WHERE `fileid` = ?',
			     [ 'moved' . bin2hex( random_bytes( 4 ) ), $file->getId() ],
		     )
		;
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function getSchemaManager(): AbstractSchemaManager
	{
		return $this->getRawConnection()
		            ->createSchemaManager()
		;
	}

	// ─── naming helpers ──────────────────────────────────────────────
	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function getTablePrefix(): string
	{
		if ( $this->tablePrefix === null )
		{
			/** @var \OCP\IConfig $config */
			$config            = Server::get( IConfig::class );
			$this->tablePrefix = $config->getSystemValueString( 'dbtableprefix', 'oc_' );
		}

		return $this->tablePrefix;
	}

	protected function getFilecacheTableName(): string
	{
		return $this->getTablePrefix() . 'filecache';
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function assertTableExists( string $tableName ): void
	{
		$this->assertTrue(
			$this->getSchemaManager()
			     ->tablesExist( [ $tableName ] ),
			"Table '$tableName' should exist.",
		);
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function assertTableNotExists( string $tableName ): void
	{
		$this->assertFalse(
			$this->getSchemaManager()
			     ->tablesExist( [ $tableName ] ),
			"Table '$tableName' should NOT exist.",
		);
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function assertColumnExists(
		string $tableName,
		string $columnName,
	): void
	{
		$columns = $this->getSchemaManager()
		                ->listTableColumns( $tableName )
		;
		$names   = array_map(
			static fn(
				$col,
			) => $col->getName(),
			$columns,
		);

		$this->assertContains(
			$columnName,
			$names,
			"Column '$columnName' should exist in table '$tableName'.",
		);
	}

	/**
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function assertColumnNotExists(
		string $tableName,
		string $columnName,
	): void
	{
		$columns = $this->getSchemaManager()
		                ->listTableColumns( $tableName )
		;
		$names   = array_map(
			static fn(
				$col,
			) => $col->getName(),
			$columns,
		);

		$this->assertNotContains(
			$columnName,
			$names,
			"Column '$columnName' should NOT exist in table '$tableName'.",
		);
	}


//  config/init/exe/run methods

	/**
	 * Execute raw SQL via the Doctrine connection.
	 *
	 * Use sparingly — prefer IDBConnection::getQueryBuilder() for
	 * portable queries.  This is intended for DDL helpers and cleanup.
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function executeRawSql( string $sql ): void
	{
		$this->getRawConnection()
		     ->executeStatement( $sql )
		;
	}

	/**
	 * Count rows in a table (simple convenience wrapper).
	 * @noinspection PhpUnhandledExceptionInspection
	 */
	protected function countRows( string $tableName ): int
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select(
			$qb->func()
			   ->count( '*', 'cnt' ),
		)
		   ->from( $tableName )
		;

		return (int) $qb->executeQuery()
		                ->fetchOne()
		;
	}
}
