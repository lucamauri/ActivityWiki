<?php

declare( strict_types=1 );

/**
 * ActivityWiki — Service Wiring
 *
 * This file registers all ActivityWiki services with the MediaWiki service
 * container (MediaWikiServices). It is loaded via the "ServiceWiringFiles"
 * entry in extension.json.
 *
 * Each entry in the returned array is a factory function that receives the
 * MediaWikiServices instance and returns a fully constructed service object.
 * Services declared here are lazy — they are only instantiated on first use.
 *
 * Naming convention: service names are prefixed with "ActivityWiki." to avoid
 * collisions with core services and other extensions.
 *
 * @file
 * @package MediaWiki\Extension\ActivityWiki
 */

use MediaWiki\Extension\ActivityWiki\HttpSigner;
use MediaWiki\Extension\ActivityWiki\KeyManager;
use MediaWiki\MediaWikiServices;

return [

	/**
	 * ActivityWiki.KeyManager
	 *
	 * Manages the RSA key pair used for HTTP Signature signing.
	 * Depends on:
	 *   - MainConfig  — to read $wgActivityWikiKeySize
	 *   - DBLoadBalancerFactory — provides IConnectionProvider for DB access
	 *
	 * @return KeyManager
	 */
	'ActivityWiki.KeyManager' => static function ( MediaWikiServices $services ): KeyManager {
		return new KeyManager(
			// MainConfig gives us access to all $wg* configuration variables,
			// including $wgActivityWikiKeySize.
			$services->getMainConfig(),

			// getConnectionProvider() is the modern (MW 1.41+) replacement for
			// the deprecated wfGetDB() and LoadBalancer patterns. It gives us
			// both replica (read) and primary (write) database connections.
			$services->getDBLoadBalancerFactory()
		);
	},
	
	'ActivityWiki.HttpSigner' => static function ( MediaWikiServices $services ): HttpSigner {
		return new HttpSigner(
			// KeyManager provides getPrivateKeyPem() for signing.
			$services->get( 'ActivityWiki.KeyManager' ),
			// MainConfig provides Server and ScriptPath for building keyId.
			$services->getMainConfig()
		);
	},
];