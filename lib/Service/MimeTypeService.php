<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use finfo;
use LogicException;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\BackgroundJob\MimeTypeDelete;
use OCA\FileChecksumSearch\BackgroundJob\MimeTypeRefresh;
use OCA\FileChecksumSearch\Config\ConfigLexicon;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IMimeTypeLoader;
use OCP\IAppConfig;

/**
 * A file's MIME type as its content says, not its name.
 *
 * Nextcloud's own type is the name's: every storage fills
 * `filecache.mimetype` from the extension. This one is libmagic's answer
 * for the first {@see HEAD_BYTES} of the content, taken from the read that
 * hashes the file, and stored beside the hashes
 * ({@see MetadataService::KEY_FILE_CHECKSUM_MIMETYPE}).
 *
 * A type's id is core's own, in `oc_mimetypes`, the table
 * `filecache.mimetype` points into as well.
 */
class MimeTypeService
{

//  constants

	/** How much of a file's content its type is detected from. */
	public const HEAD_BYTES = 65536;

	/** Switched on, and PHP able to: types are detected. */
	public const STATE_ENABLED = 'enabled';

	/** Switched off: none is detected, and the types stored are kept. */
	public const STATE_DISABLED = 'disabled';

	/** Switched on, and PHP unable to: none is detected. */
	public const STATE_UNAVAILABLE = 'unavailable';

	/** The stored types are being deleted; detection is off until they are. */
	public const STATE_DELETING = 'deleting';

	/** The width of `oc_mimetypes.mimetype`. */
	private const MAX_LENGTH = 255;


//  constructor

	public function __construct(
		private readonly IAppConfig        $appConfig,
		private readonly IMimeTypeDetector $detector,
		private readonly IMimeTypeLoader   $loader,
		private readonly IJobList          $jobList,
		private readonly ITimeFactory      $time,
	) {
	}


//  getters / setters / is* / has*

	/** Whether the administrator switched detection on. */
	public function isEnabled(): bool
	{
		return $this->appConfig->getValueBool(
			Application::APP_ID,
			ConfigLexicon::MIMETYPE_DETECTION,
		);
	}

	/**
	 * Whether PHP can detect a type: its Fileinfo extension is loaded, which
	 * Nextcloud requires and a PHP build may still leave out.
	 */
	public function isAvailable(): bool
	{
		return class_exists( finfo::class );
	}

	/** Whether types are detected now: switched on, and PHP able to. */
	public function isActive(): bool
	{
		return $this->isEnabled() && $this->isAvailable();
	}

	/**
	 * When the stored types were last asked to be deleted, unix seconds;
	 * null while no deletion runs.
	 */
	public function getDeleteRequestedAt(): ?int
	{
		$at = $this->appConfig->getValueInt(
			Application::APP_ID,
			ConfigLexicon::MIMETYPE_DELETE_REQUESTED_AT,
		);

		return $at > 0
			? $at
			: null;
	}

	/**
	 * Record a deletion as requested at $at, unix seconds, or none for null.
	 */
	public function setDeleteRequestedAt( ?int $at ): void
	{
		if ( $at === null )
		{
			$this->appConfig->deleteKey( Application::APP_ID, ConfigLexicon::MIMETYPE_DELETE_REQUESTED_AT );

			return;
		}

		$this->appConfig->setValueInt( Application::APP_ID, ConfigLexicon::MIMETYPE_DELETE_REQUESTED_AT, $at );
	}

	/**
	 * Where detection stands, one of the `STATE_*`: a deletion running first,
	 * since it holds detection off whatever the switch says.
	 */
	public function getState(): string
	{
		if ( $this->getDeleteRequestedAt() !== null )
		{
			return self::STATE_DELETING;
		}

		if ( ! $this->isEnabled() )
		{
			return self::STATE_DISABLED;
		}

		return $this->isAvailable()
			? self::STATE_ENABLED
			: self::STATE_UNAVAILABLE;
	}


//  other non-static methods

	/**
	 * Why detection cannot be switched on now: the `STATE_*` that refuses it,
	 * or null where nothing does.
	 *
	 * PHP unable to detect, or a deletion running, which would take the types
	 * detected meanwhile away. Whoever asked says so in their own words.
	 */
	public function refusalToEnable(): ?string
	{
		if ( $this->getDeleteRequestedAt() !== null )
		{
			return self::STATE_DELETING;
		}

		return $this->isAvailable()
			? null
			: self::STATE_UNAVAILABLE;
	}

	/**
	 * Why the types cannot be refreshed now: the `STATE_*` that refuses it,
	 * or null where nothing does. Only what is detected can be refreshed.
	 */
	public function refusalToRefresh(): ?string
	{
		$state = $this->getState();

		return $state === self::STATE_ENABLED
			? null
			: $state;
	}

	/**
	 * Why the types cannot be deleted now: the `STATE_*` that refuses it, or
	 * null where nothing does.
	 *
	 * Switched on, the types detected while the deletion ran would be taken
	 * away with the rest: switch off first. Asked again while one runs, the
	 * deletion is requested anew.
	 */
	public function refusalToDelete(): ?string
	{
		if ( $this->getDeleteRequestedAt() === null && $this->isEnabled() )
		{
			return $this->isAvailable()
				? self::STATE_ENABLED
				: self::STATE_UNAVAILABLE;
		}

		return null;
	}

	/**
	 * Switch detection on.
	 *
	 * @throws LogicException  Where {@see refusalToEnable()} refuses it: the
	 *                         caller asks first.
	 */
	public function enable(): void
	{
		$refusal = $this->refusalToEnable();

		if ( $refusal !== null )
		{
			throw new LogicException( sprintf( 'MIME type detection cannot be switched on: %s.', $refusal ) );
		}

		$this->appConfig->setValueBool( Application::APP_ID, ConfigLexicon::MIMETYPE_DETECTION, true );
	}

	/**
	 * Switch detection off. The types stored are kept; a refresh queued
	 * stops, and the files it queued leave the queue unread.
	 */
	public function disable(): void
	{
		$this->appConfig->setValueBool( Application::APP_ID, ConfigLexicon::MIMETYPE_DETECTION, false );
	}

	/**
	 * Queue the hashed files for their type: those without one, or all.
	 *
	 * @throws LogicException  Where {@see refusalToRefresh()} refuses it.
	 */
	public function requestRefresh( bool $all ): void
	{
		$refusal = $this->refusalToRefresh();

		if ( $refusal !== null )
		{
			throw new LogicException( sprintf( 'The MIME types cannot be refreshed: detection is %s.', $refusal ) );
		}

		MimeTypeRefresh::queue( $this->jobList, $all );
	}

	/**
	 * Request the deletion of the stored types, now, and queue it.
	 *
	 * @throws LogicException  Where {@see refusalToDelete()} refuses it.
	 */
	public function requestDelete(): void
	{
		$refusal = $this->refusalToDelete();

		if ( $refusal !== null )
		{
			throw new LogicException( sprintf( 'The MIME types cannot be deleted: detection is %s.', $refusal ) );
		}

		$this->setDeleteRequestedAt( $this->time->getTime() );
		MimeTypeDelete::queue( $this->jobList );
	}

	/**
	 * The type of the content that begins with $head, as libmagic names it.
	 *
	 * Core's secure-type mapping is not applied: it turns every type it does
	 * not list into `application/octet-stream`, which is the answer this is
	 * here to improve on.
	 *
	 * @return string|null  Null where libmagic gives no answer, or one too
	 *                      long for `oc_mimetypes`.
	 */
	public function detect( string $head ): ?string
	{
		$type = trim( (string) $this->detector->detectString( $head ) );

		return $type === '' || strlen( $type ) > self::MAX_LENGTH
			? null
			: $type;
	}

	/** A type's id in `oc_mimetypes`, which adds the type where it is new. */
	public function idOf( string $mimeType ): int
	{
		return $this->loader->getId( $mimeType );
	}

	/** The type an id in `oc_mimetypes` stands for. */
	public function typeOf( int $id ): ?string
	{
		return $id > 0
			? $this->loader->getMimetypeById( $id )
			: null;
	}
}
