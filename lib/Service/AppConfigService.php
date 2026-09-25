<?php

declare( strict_types=1 );

/**
 * @copyright Copyright (c) 2026 metaworx
 * @license   AGPL-3.0-or-later
 */

namespace OCA\FileChecksumSearch\Service;

use OCA\FileChecksumSearch\AppInfo\Application;
use OCA\FileChecksumSearch\Config\ConfigLexicon;
use OCP\Config\Lexicon\Entry;
use OCP\Config\ValueType;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * This app's own slice of Nextcloud's app configuration.
 *
 * Which keys the app owns is a question with one honest answer: the config
 * lexicon, which already declares every one of them and is what Nextcloud
 * validates writes against. Reading the list from there rather than repeating
 * it means a key added to the lexicon is backed up and reset without anyone
 * remembering to add it in a second place — and a key *missing* from the
 * lexicon was already a defect, since Nextcloud logs a warning for every read
 * of an undeclared key.
 *
 * Not `readonly`: the command tests double this class, and PHPUnit 10.5
 * cannot mock readonly classes (TESTING.md §6.1).
 */
class AppConfigService
{

//  constants

	/**
	 * Key prefixes that record what this instance has done.
	 *
	 * `stats_` holds each background job's last run and its counts — a
	 * heartbeat, and restoring another machine's heartbeat would put a time
	 * on the status page that nothing here ever did. `repair_done_` records
	 * which one-time repair steps have completed, and importing one is the
	 * worst case of all: the step is skipped for ever on an instance where
	 * it never ran.
	 */
	private const HISTORY_PREFIXES
		 = [
			'stats_',
			'repair_done_',
		];


//  constructor

	public function __construct(
		private readonly IAppConfig      $appConfig,
		private readonly ConfigLexicon   $lexicon,
		private readonly LoggerInterface $logger,
	) {
	}


//  other non-static methods

	/**
	 * The keys this app owns, in the order the lexicon declares them.
	 *
	 * @return list<string>
	 */
	public function ownedKeys(): array
	{
		return array_map(
			static fn(
				$entry,
			): string => $entry->getKey(),
			$this->lexicon->getAppConfigs(),
		);
	}

	/**
	 * The owned keys that may travel to another instance.
	 *
	 * **Configuration travels; history does not.** A key saying how this
	 * instance is set up belongs in a backup, and restoring it elsewhere
	 * means something. A key recording what this instance has *done* —
	 * when a job last ran, which repair steps have completed — means nothing
	 * anywhere else, and asserting it can do harm: an instance told that a
	 * one-time repair has already run will never run it, silently and
	 * permanently.
	 *
	 * The same line the backup already draws between its `config` and
	 * `status` slices, drawn once more inside the config slice itself.
	 *
	 * @return list<string>
	 */
	public function portableKeys(): array
	{
		return array_values( array_filter( $this->ownedKeys(), self::isPortable( ... ) ) );
	}


//  static methods

	/**
	 * Whether a key describes configuration rather than history.
	 */
	public static function isPortable( string $key ): bool
	{
		foreach ( self::HISTORY_PREFIXES as $prefix )
		{
			if ( str_starts_with( $key, $prefix ) )
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * The lexicon\'s entries, by key.
	 *
	 * @return array<string, Entry>
	 */
	public function entries(): array
	{
		$entries = [];

		foreach ( $this->lexicon->getAppConfigs() as $entry )
		{
			$entries[ $entry->getKey() ] = $entry;
		}

		return $entries;
	}

	/**
	 * Every owned key that is actually set, as strings.
	 *
	 * Each key is read through the getter its **declared type** calls for.
	 * Nextcloud refuses a read that disagrees with the lexicon — asking for
	 * an `INT` key as a string is an error, not a coercion — so a backup
	 * that read everything as a string would fail on the first interval key
	 * it met. The values are then rendered as strings because that is what a
	 * backup file can hold; {@see import()} parses them back through the
	 * same declaration.
	 *
	 * @return array<string, string>
	 */
	public function export(): array
	{
		$config = [];

		foreach ( $this->entries() as $key => $entry )
		{
			if ( ! self::isPortable( $key ) || ! $this->appConfig->hasKey( Application::APP_ID, $key ) )
			{
				continue;
			}

			$config[ $key ] = $this->read( $key, $entry->getValueType() );
		}

		return $config;
	}

	/**
	 * One key, read as the lexicon says it is stored.
	 */
	private function read(
		string    $key,
		ValueType $type,
	): string
	{
		return match ( $type )
		{
			ValueType::INT   => (string) $this->appConfig->getValueInt( Application::APP_ID, $key ),
			ValueType::FLOAT => (string) $this->appConfig->getValueFloat( Application::APP_ID, $key ),
			ValueType::BOOL  => $this->appConfig->getValueBool( Application::APP_ID, $key )
				? '1'
				: '0',
			ValueType::ARRAY => json_encode(
				$this->appConfig->getValueArray( Application::APP_ID, $key ),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
			),
			default => $this->appConfig->getValueString( Application::APP_ID, $key ),
		};
	}

	/**
	 * One key, written as the lexicon says it is stored.
	 */
	private function write(
		string    $key,
		ValueType $type,
		string    $value,
	): void
	{
		match ( $type )
		{
			ValueType::INT   => $this->appConfig->setValueInt( Application::APP_ID, $key, (int) $value ),
			ValueType::FLOAT => $this->appConfig->setValueFloat( Application::APP_ID, $key, (float) $value ),
			// Whatever a backup or a hand-edit spells "true" with.
			ValueType::BOOL => $this->appConfig->setValueBool(
				Application::APP_ID,
				$key,
				in_array(
					strtolower( $value ),
					[
						'1',
						'true',
						'yes',
						'on',
					],
					true,
				),
			),
			ValueType::ARRAY => $this->appConfig->setValueArray(
				Application::APP_ID,
				$key,
				json_decode( $value, true ) ?? [],
			),
			default => $this->appConfig->setValueString( Application::APP_ID, $key, $value ),
		};
	}

	/**
	 * Write configuration back.
	 *
	 * Keys the lexicon does not declare are refused rather than written: an
	 * import is not a way to smuggle configuration past the declaration that
	 * makes it legible, and a backup from a newer version naming keys this
	 * one does not have should say so rather than half-apply.
	 *
	 * A backup file's config object is whatever `json_decode()` made of it,
	 * so the keys may have been coerced to integers and the values may be any
	 * scalar — hence the casts, which the declared types alone do not make
	 * redundant.
	 *
	 * @param  array<array-key, scalar>  $config
	 * @param  bool                      $replace  Delete owned keys the import
	 *                                             does not mention, so the
	 *                                             result is exactly the input.
	 *
	 * @return array{written: int, skipped: list<string>}
	 */
	public function import(
		array $config,
		bool  $replace = false,
	): array
	{
		$owned       = $this->entries();
		$written     = 0;
		$skipped     = [];
		$notPortable = [];

		foreach ( $config as $key => $value )
		{
			$key = (string) $key;

			if ( ! isset( $owned[ $key ] ) )
			{
				$skipped[] = $key;

				continue;
			}

			// A key this version declares but that records what an instance
			// has done. Refused rather than written, and reported apart from
			// an unknown key: the two need different things said about them.
			if ( ! self::isPortable( $key ) )
			{
				$notPortable[] = $key;

				continue;
			}

			$this->write( $key, $owned[ $key ]->getValueType(), (string) $value );
			$written ++;
		}

		if ( $replace )
		{
			// Replacing means the result is exactly the input — of the keys
			// that could have been in it. History was never exported, so its
			// absence says nothing and must not delete anything.
			foreach ( array_diff( $this->portableKeys(), array_keys( $config ) ) as $key )
			{
				$this->deleteKey( $key );
			}
		}

		return [
			'written'      => $written,
			'skipped'      => $skipped,
			'not_portable' => $notPortable,
		];
	}

	/**
	 * Forget every owned key, returning the app to its declared defaults.
	 *
	 * Deletion rather than writing defaults: an absent key *is* the default,
	 * and the lexicon is where the default lives. Writing them out would
	 * freeze today's values into the instance and quietly diverge the day a
	 * default changed.
	 *
	 * @return int  Keys deleted.
	 */
	public function clear(): int
	{
		$deleted = 0;

		foreach ( $this->ownedKeys() as $key )
		{
			if ( ! $this->appConfig->hasKey( Application::APP_ID, $key ) )
			{
				continue;
			}

			if ( $this->deleteKey( $key ) )
			{
				$deleted ++;
			}
		}

		return $deleted;
	}

	/**
	 * One deletion, contained: a key that refuses to go must not abandon the
	 * rest of the reset half-done.
	 */
	private function deleteKey( string $key ): bool
	{
		try
		{
			$this->appConfig->deleteKey( Application::APP_ID, $key );

			return true;
		}
		catch ( Throwable $e )
		{
			$this->logger->warning(
				'FCIAS: could not delete app config key {key}; continuing.',
				[
					'app'       => Application::APP_ID,
					'key'       => $key,
					'exception' => $e,
				],
			);

			return false;
		}
	}
}
