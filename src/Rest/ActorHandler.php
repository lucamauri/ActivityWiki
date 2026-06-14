<?php
/**
 * ActorHandler — REST handler for the ActivityPub actor endpoint.
 *
 * Serves the wiki's ActivityPub Actor object at:
 *   GET /activitywiki/actor
 *
 * This is the wiki's identity document on the Fediverse. Mastodon and other
 * Fediverse software fetch this URL (after discovering it via WebFinger) to
 * learn the wiki's display name, inbox, outbox, and public key.
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
 * Handles GET /activitywiki/actor
 *
 * Returns a JSON-LD document describing this wiki as an ActivityPub
 * Application actor. No authentication is required — the actor document
 * is always public, as required by the ActivityPub specification.
 */
class ActorHandler extends SimpleHandler {

	/**
	 * The ActivityPub object builder.
	 * Receives Config and KeyManager via constructor injection and
	 * delegates all JSON assembly to ActivityPubModule, keeping this
	 * handler thin — its only responsibility is HTTP concerns.
	 *
	 * @var ActivityPubModule
	 */
	private ActivityPubModule $module;

	/**
	 * @param Config $config Injected by MediaWiki from routes.json "services"
	 * @param KeyManager $keyManager Injected by MediaWiki from routes.json "services"
	 */
	public function __construct( Config $config, KeyManager $keyManager ) {
		// Instantiate the module once, passing both injected services.
		// All URL building and JSON assembly is delegated to ActivityPubModule.
		$this->module = new ActivityPubModule( $config, $keyManager );
	}

	/**
	 * Handles the GET request and returns the actor JSON document.
	 *
	 * Response format: application/activity+json (the ActivityPub media type).
	 * This is what Mastodon and other Fediverse clients expect when they
	 * send an Accept header of "application/activity+json".
	 *
	 * @return \MediaWiki\Rest\Response
	 */
	public function run(): \MediaWiki\Rest\Response {
		// Build the actor data array via the module.
		$actorData = $this->module->buildActorObject();

		// Encode to JSON. JSON_PRETTY_PRINT makes the response human-readable
		// when inspected directly in a browser or with curl, at negligible cost.
		// JSON_UNESCAPED_SLASHES keeps URLs clean (no \/ in output).
		$json = json_encode( $actorData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		// json_encode() returns false only if the data contains non-UTF-8
		// sequences or resource types, neither of which can occur here.
		// We guard anyway to satisfy static analysis.
		if ( $json === false ) {
			return $this->getResponseFactory()->createHttpError( 500, [
				'message' => 'Failed to encode actor object as JSON.',
			] );
		}

		// Build the HTTP response manually so we can set the correct
		// Content-Type header. ActivityPub requires "application/activity+json".
		// MediaWiki's default REST response uses "application/json", which
		// some stricter Fediverse implementations will reject.
		$response = $this->getResponseFactory()->create();
		$response->setHeader( 'Content-Type', 'application/activity+json; charset=UTF-8' );

		// CORS header: allows any Fediverse server to fetch this document
		// from client-side JavaScript. Required for broad interoperability.
		$response->setHeader( 'Access-Control-Allow-Origin', '*' );

		// Cache-Control: the actor document changes only when config changes.
		// 1 hour is a reasonable balance between freshness and reduced load.
		// Fediverse servers are expected to re-fetch periodically regardless.
		$response->setHeader( 'Cache-Control', 'public, max-age=3600' );

		$response->getBody()->write( $json );

		return $response;
	}

	/**
	 * Declares that this endpoint takes no parameters.
	 *
	 * The actor URL is fixed — there are no path variables or query parameters
	 * on this endpoint. (WebFinger has a "resource" query param, but that
	 * is handled by WebFingerHandler, not here.)
	 *
	 * @return array
	 */
	public function getParamSettings(): array {
		return [];
	}
}