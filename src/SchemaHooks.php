<?php

declare(strict_types=1);

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
class SchemaHooks
{

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
	public static function onLoadExtensionSchemaUpdates(DatabaseUpdater $updater): void
	{
		$updater->addExtensionTable(
			'activitywiki_keys',
			dirname( __DIR__ ) . '/db/activitywiki_keys.sql'
		);

		$updater->addExtensionTable(
        	'activitywiki_followers',
        	dirname( __DIR__ ) . '/db/activitywiki_followers.sql'
    	);

		$updater->addExtensionUpdate([
    	    [self::class, 'generateInitialKeyPair'],
		]);
	}

	/**
	 * Callback for addExtensionUpdate() — generates the RSA key pair on first install.
	 *
	 * This method is called by DatabaseUpdater::runUpdates() after all schema
	 * changes have been applied.
	 *
	 * Delegates to KeyManager::generateAndStoreKeyPairIfAbsent(), which is
	 * specifically designed for this context:
	 *  - Uses the primary DB connection (not replica) for both the existence
	 *    check and the insert, avoiding visibility issues with a just-created table.
	 *  - Does a plain INSERT with no DELETE and no atomic section (savepoint),
	 *    so a failure cannot poison the database connection for subsequent
	 *    update.php steps (e.g. Semantic MediaWiki's table checks).
	 *  - Is idempotent — safe to run on every upgrade, not just first install.
	 *
	 * @param DatabaseUpdater $updater Passed automatically by runUpdates().
	 * @return void
	 */
	public static function generateInitialKeyPair(DatabaseUpdater $updater): void
	{
		/** @var KeyManager $keyManager */
		$keyManager = MediaWikiServices::getInstance()
			->getService('ActivityWiki.KeyManager');

		$updater->output("Checking ActivityWiki RSA key pair...");

		try {
			$generated = $keyManager->generateAndStoreKeyPairIfAbsent();

			if ($generated) {
				$updater->output("generated.\n");
			} else {
				$updater->output("already exists, skipping.\n");
			}
		} catch (\RuntimeException $e) {
			$updater->output("FAILED.\n");
			$updater->output(
				"WARNING: ActivityWiki could not generate an RSA key pair.\n" .
					"Error: " . $e->getMessage() . "\n" .
					"Run maintenance/GenerateKeys.php to retry after fixing the issue.\n"
			);
		}
	}
}
