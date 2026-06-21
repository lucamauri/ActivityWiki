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

use MediaWiki\Extension\ActivityWiki\FollowManager;
use MediaWiki\Extension\ActivityWiki\HttpSigner;
use MediaWiki\Extension\ActivityWiki\KeyManager;
use MediaWiki\Extension\ActivityWiki\SignatureVerifier;
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

	/**
	 * ActivityWiki.SignatureVerifier
	 *
	 * Verifies inbound HTTP Signatures on requests arriving at the wiki's
	 * inbox endpoint (Phase 4). Mirror image of ActivityWiki.HttpSigner.
	 * Depends on:
	 *   - HttpRequestFactory — to fetch the sender's actor document and
	 *     obtain their public key, the same service DeliveryJob already
	 *     uses for outbound POSTs.
	 *   - MainConfig — to build this wiki's own actor URL, used in the
	 *     self-identifying User-Agent header sent on that fetch (confirmed
	 *     necessary via live testing — see ActivityWiki-plan.md, section 4.5).
	 *
	 * @return SignatureVerifier
	 */
	'ActivityWiki.SignatureVerifier' => static function ( MediaWikiServices $services ): SignatureVerifier {
		return new SignatureVerifier(
			// getHttpRequestFactory() is the standard MediaWiki service
			// accessor — the same factory DeliveryJob receives via its
			// JobClasses "services" list in extension.json.
			$services->getHttpRequestFactory(),
			$services->getMainConfig()
		);
	},

	/**
	 * ActivityWiki.FollowManager
	 *
	 * Owns Follow/Undo business logic for the inbox endpoint (Phase 4):
	 * recording/removing rows in activitywiki_followers, and enqueuing the
	 * Accept reply via AcceptJob.
	 * Depends on:
	 *   - DBLoadBalancerFactory — provides IConnectionProvider for DB access,
	 *     same pass-through pattern already used by ActivityWiki.KeyManager.
	 *   - JobQueueGroup — to enqueue the MediawikiActivityPubAccept job.
	 *   - HttpRequestFactory — to fetch a follower's actor document and
	 *     learn their inbox URL.
	 *   - MainConfig — to build this wiki's own actor URL for the Accept's
	 *     "actor" field.
	 *
	 * @return FollowManager
	 */
	'ActivityWiki.FollowManager' => static function ( MediaWikiServices $services ): FollowManager {
		return new FollowManager(
			$services->getDBLoadBalancerFactory(),
			$services->getJobQueueGroup(),
			$services->getHttpRequestFactory(),
			$services->getMainConfig()
		);
	},
];