<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Tests\Integration\Service;

use OCA\FileChecksumSearch\Public\ChecksumApi;
use OCA\FileChecksumSearch\Service\ReachResolver;
use OCA\FileChecksumSearch\Tests\Integration\DatabaseTestCase;
use OCP\Constants;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserManager;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

/**
 * A received share is its subtree, also in the request that first records
 * it.
 *
 * The first time an account's files are set up after a share reached them,
 * Nextcloud records the new mount and keeps it in memory for the rest of
 * the request as it was mounted: its root within its own storage — `''` for
 * a share — beside the sharer's storage id. Read that way, the share is the
 * sharer's whole storage, and every file the sharer holds is in reach for
 * the rest of that request. A fresh request reads the mounts from the
 * database and is not affected, which is why only one process can show it.
 */
class ReachResolverIntegrationTest
    extends
    DatabaseTestCase
{

//  private properties

	private static string $recipientUid;

	private static string $sharedPath;

	/** @var array{shared: int, beside: int} */
	private static array $fileId;


//  static methods

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		[ $ownerUid ]           = self::makeAccount( 'fcias_inrequest_owner' );
		[ self::$recipientUid ] = self::makeAccount( 'fcias_inrequest_recipient' );

		$root  = Server::get( IRootFolder::class );
		$owner = $root->getUserFolder( $ownerUid );
		$salt  = bin2hex( random_bytes( 4 ) );

		$shared = $owner->newFolder( 'shared_' . $salt );
		$other  = $owner->newFolder( 'other_' . $salt );

		self::$sharedPath = $shared->getInternalPath();
		self::$fileId     = [
			'shared' => $shared->newFile( 'in.txt', 'in ' . $salt )->getId(),
			'beside' => $other->newFile( 'beside.txt', 'beside ' . $salt )->getId(),
		];

		$shareManager = Server::get( IShareManager::class );
		$share        = $shareManager->newShare();
		$share->setNode( $shared )
		      ->setShareType( IShare::TYPE_USER )
		      ->setSharedWith( self::$recipientUid )
		      ->setSharedBy( $ownerUid )
		      ->setPermissions( Constants::PERMISSION_READ )
		;
		$shareManager->createShare( $share );

		// The recipient's files set up in this process, after the share, and
		// in full: that is what records the share's mount here and now. A
		// partial setup — what getUserFolder() may do in a long process —
		// records only the providers it asked, and no share at all.
		Server::get( \OC\Files\SetupManager::class )
		      ->setupForUser( Server::get( IUserManager::class )->get( self::$recipientUid ) )
		;
	}


//  other non-static methods

	public function testTheShareIsItsSubtreeNotTheSharersStorage(): void
	{
		$mounts = Server::get( ReachResolver::class )->mountsFor( [ self::$recipientUid ] );

		$this->assertContains( self::$sharedPath, array_column( $mounts, 'root' ), 'the shared folder is a root' );

		$reach = Server::get( ReachResolver::class );

		$this->assertTrue( $reach->contains( $mounts, self::$fileId['shared'] ), 'a file in the share' );
		$this->assertFalse( $reach->contains( $mounts, self::$fileId['beside'] ), 'the sharer\'s file beside it' );
	}

	public function testTheSharersFileBesideItIsNotFoundForTheRecipient(): void
	{
		$this->expectException( NotFoundException::class );

		Server::get( ChecksumApi::class )->getHashesByFileId( self::$fileId['beside'], [ self::$recipientUid ] );
	}
}
