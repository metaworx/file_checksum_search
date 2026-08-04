<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCP\IConfig;

/**
 * Centralized source of truth for all table/SP/trigger names.
 *
 * Reads the database table prefix from Nextcloud config once and exposes
 * typed getters for every named database object the app touches.
 */
class TableNameService
{

	public const SP_FCIAS_PARSE_FILE_HASHES        = 'fcias_parse_file_hashes';
	public const TABLE_FILE_CHECKSUM_SEARCH_HASHES = 'file_checksum_search_hashes';

	private string $prefix;

	private string $filecacheTable;

	private string $hashTable;

	private string $spName;


	public function __construct( IConfig $config )
	{

		$this->prefix         = $config->getSystemValueString( 'dbtableprefix', 'oc_' );
		$this->filecacheTable = $this->prefix . 'filecache';
		$this->hashTable      = $this->prefix . self::TABLE_FILE_CHECKSUM_SEARCH_HASHES;
		$this->spName         = $this->prefix . self::SP_FCIAS_PARSE_FILE_HASHES;
	}


	/**
	 * Raw DB table prefix (e.g. "oc_").
	 *
	 * Use this only for constructing dynamic names (e.g. temp tables used
	 * in privilege checks).  Prefer the typed getters below for stable names.
	 */
	public function getPrefix(): string
	{

		return $this->prefix;
	}


	/** Fully-qualified `*PREFIX*filecache` table name. */
	public function getFilecacheTableName(): string
	{

		return $this->filecacheTable;
	}


	/** Fully-qualified `*PREFIX*file_checksum_search_hashes` table name. */
	public function getHashTableName(): string
	{

		return $this->hashTable;
	}


	/** Fully-qualified stored‑procedure name. */
	public function getSpName(): string
	{

		return $this->spName;
	}


	/** Trigger name for a given suffix (e.g. "insert" → `*PREFIX*t_fcias_after_insert`). */
	public function getTriggerName( string $suffix ): string
	{

		return $this->prefix . 't_fcias_after_' . $suffix;
	}


	/** LIKE pattern matching all three FCIAS triggers. */
	public function getTriggerLikePattern(): string
	{

		return $this->prefix . 't_fcias_after_%';
	}

}
