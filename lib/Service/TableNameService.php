<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCP\IConfig;

/**
 * Centralized source of truth for database table names.
 *
 * Reads the database table prefix from Nextcloud config once at
 * construction time and exposes typed getters for the core NC tables
 * the app queries (filecache, files_metadata, etc.).
 *
 * Use the typed getters instead of passing the raw prefix around;
 * this keeps table-name construction in one place and avoids
 * hardcoded `oc_` assumptions.
 */
class TableNameService
{

	private string $prefix;

	private string $filecacheTable;


	public function __construct( IConfig $config )
	{

		$this->prefix         = $config->getSystemValueString( 'dbtableprefix', 'oc_' );
		$this->filecacheTable = $this->prefix . 'filecache';
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

}
