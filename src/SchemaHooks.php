<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\ActivityWiki;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\MediaWikiServices;

/**
 * SchemaHooks — handles database schema updates for ActivityWiki.
 *
 * This class is intentionally separate from the main Hooks class because
 * LoadExtensionSchemaUpdates runs in a special context (the update.php
 * maintenance script) where not all services are available. Keeping schema
 * update logic in its own class avoids accidentally pulling in dependencies
 * that are unsafe at update time.
 *
 * Responsibilities:
 *  1. Register the activitywiki_keys table with the MediaWiki schema updater.
 *  2. After the table exists, auto-generate a key pair on first install
 *     (i.e., when the table is empty). This implements the "hybrid" key
 *     generation approach confirmed in design decisions:
 *       - First install  → keys are generated automatically.
 *       - Key rotation   → must be done manually via maintenance/GenerateKeys.php.
 *
 * @package MediaWiki\Extension\ActivityWiki
 */
class SchemaHooks {

	/**
	 * Handler for the LoadExtensionSchemaUpdates hook.
	 *
	 * Called by MediaWiki's update.php script. Registers any schema changes
	 * the extension needs to apply (new tables, new fields, etc.).
	 *
	 * After registering the table, we also queue a callback via
	 * addExtensionUpdate() that generates the RSA key pair if none exists yet.
	 * The callback runs after the table has been created, so it is safe to
	 * read from and write to activitywiki_keys at that point.
	 *
	 * @param DatabaseUpdater $updater The MediaWiki database updater instance.
	 * @return void
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ): void {
		$dbType = $updater->getDB()->getType();

		// addExtensionTable() registers the table creation. MediaWiki will
		// apply the JSON schema to generate the appropriate SQL for the current
		// DBMS (MySQL/MariaDB, PostgreSQL, or SQLite) and create the table if
		// it does not already exist. If the table exists, this is a no-op.
		$updater->addExtensionTable(
			'activitywiki_keys',
			// The second argument is the path to the abstract schema JSON file.
			// __DIR__ resolves to src/, so we navigate up one level to the
			// extension root and then into db/.
			dirname( __DIR__ ) . '/db/activitywiki_keys.json'
		);

		// addExtensionUpdate() queues a callback to run after all schema
		// changes have been applied. The format is:
		//   [ callable, ...extra_args ]
		// The DatabaseUpdater instance is always passed as the first argument
		// to the callback automatically.
		//
		// We use this to generate the RSA key pair on first install. The
		// callback is a static method on this class so it is serializable
		// (anonymous functions are not allowed here by the MW API).
		$updater->addExtensionUpdate( [
			[ self::class, 'generateInitialKeyPair' ],
		] );
	}

	/**
	 * Callback for addExtensionUpdate() — generates the RSA key pair if none exists.
	 *
	 * This method is called by DatabaseUpdater::runUpdates() after all schema
	 * changes have been applied. It is safe to query activitywiki_keys here.
	 *
	 * Behaviour:
	 *  - If a key pair already exists in the database → does nothing (idempotent).
	 *  - If no key pair exists → generates one and stores it.
	 *  - On failure → outputs an error message and continues (non-fatal), so
	 *    that a transient OpenSSL issue does not leave update.php broken.
	 *
	 * Key rotation is explicitly NOT done here — that requires the operator to
	 * run maintenance/GenerateKeys.php with a manual confirmation step.
	 *
	 * @param DatabaseUpdater $updater Passed automatically by runUpdates().
	 * @return void
	 */
	public static function generateInitialKeyPair( DatabaseUpdater $updater ): void {
		// Obtain the KeyManager service from the global service container.
		// We use MediaWikiServices::getInstance() here rather than constructor
		// injection because this is a static callback — it has no object context.
		// This is the accepted pattern for static hook handlers in MediaWiki.
		/** @var KeyManager $keyManager */
		$keyManager = MediaWikiServices::getInstance()
			->getService( 'ActivityWiki.KeyManager' );

		// hasKey() queries the replica DB. If a row already exists, we skip
		// generation entirely — this makes the callback idempotent and safe
		// to run on upgrades where the key was already generated on install.
		if ( $keyManager->hasKey() ) {
			$updater->output( "...ActivityWiki RSA key pair already exists, skipping.\n" );
			return;
		}

		$updater->output( "Generating ActivityWiki RSA key pair..." );

		try {
			$keyManager->generateAndStoreKeyPair();
			$updater->output( "done.\n" );
		} catch ( \RuntimeException $e ) {
			// Output the error but do not re-throw. A missing key pair means
			// HTTP Signatures will not work, but the wiki itself will still
			// function. The operator can run maintenance/GenerateKeys.php to
			// retry after fixing any OpenSSL configuration issue.
			$updater->output( "FAILED.\n" );
			$updater->output(
				"WARNING: ActivityWiki could not generate an RSA key pair.\n" .
				"Error: " . $e->getMessage() . "\n" .
				"Run maintenance/GenerateKeys.php to retry after fixing the issue.\n"
			);
		}
	}

}