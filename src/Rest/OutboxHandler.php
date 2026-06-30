<?php
/**
 * OutboxHandler — REST handler for the ActivityPub outbox endpoint.
 *
 * Serves the wiki's ActivityPub outbox at:
 *   GET /activitywiki/outbox
 *
 * The outbox is an OrderedCollection listing activities published by this
 * actor (page creations, edits, deletions). In Phase 1 this returns an
 * empty collection. Full implementation is deferred to Phase 3
 * (Layer 3 — Publishing).
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\ActivityWiki\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ActivityWiki\Api\ActivityPubModule;
use MediaWiki\Extension\ActivityWiki\KeyManager;
use MediaWiki\Rest\SimpleHandler;

/**
 * Handles GET /activitywiki/outbox
 *
 * Returns a spec-compliant empty ActivityPub OrderedCollection.
 * Pagination and real activity content will be added in Phase 3.
 */
class OutboxHandler extends SimpleHandler {

	/**
	 * The ActivityPub object builder.
	 *
	 * @var ActivityPubModule
	 */
	private ActivityPubModule $module;

	/**
	 * @param Config $config Injected by MediaWiki from routes.json "services".
	 * @param KeyManager $keyManager Injected by MediaWiki from routes.json "services".
	 *   Required by ActivityPubModule's constructor — OutboxHandler itself never
	 *   calls any key-related method on the module (it only uses the collection
	 *   builder), but ActivityPubModule has no constructor overload that omits
	 *   KeyManager. Mirrors ActorHandler and WebFingerHandler exactly.
	 */
	public function __construct( Config $config, KeyManager $keyManager ) {
		$this->module = new ActivityPubModule( $config, $keyManager );
	}

	/**
	 * Handles the GET request and returns the outbox collection.
	 *
	 * @return \MediaWiki\Rest\Response
	 */
	public function run(): \MediaWiki\Rest\Response {
		$json = json_encode(
			$this->module->buildOutboxObject(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		if ( $json === false ) {
			return $this->getResponseFactory()->createHttpError( 500, [
				'message' => 'Failed to encode outbox object as JSON.',
			] );
		}

		$response = $this->getResponseFactory()->create();
		$response->setHeader( 'Content-Type', 'application/activity+json; charset=UTF-8' );
		$response->setHeader( 'Access-Control-Allow-Origin', '*' );
		$response->setHeader( 'Cache-Control', 'public, max-age=3600' );
		$response->getBody()->write( $json );

		return $response;
	}

	/**
	 * This endpoint takes no parameters in Phase 1.
	 * Pagination parameters (page, limit) will be added in Phase 3.
	 *
	 * @return array
	 */
	public function getParamSettings(): array {
		return [];
	}
}