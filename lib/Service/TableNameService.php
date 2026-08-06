<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCP\IConfig;

/**
 * Centralized source of truth for table names.
 *
 * Reads the database table prefix from Nextcloud config once and exposes
 * typed getters for every named database object the app touches.
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
