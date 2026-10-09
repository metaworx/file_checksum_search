<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use finfo;
use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Config\ConfigLexicon;
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

	/** The width of `oc_mimetypes.mimetype`. */
	private const MAX_LENGTH = 255;


//  constructor

	public function __construct(
		private readonly IAppConfig        $appConfig,
		private readonly IMimeTypeDetector $detector,
		private readonly IMimeTypeLoader   $loader,
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


//  other non-static methods

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
