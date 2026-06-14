<?php
/**
 * FollowersHandler — REST handler for the ActivityPub followers endpoint.
 *
 * Serves the wiki's ActivityPub followers collection at:
 *   GET /activitywiki/followers
 *
 * The followers collection is an OrderedCollection listing actors that
 * follow this wiki. In Phase 1 this returns an empty collection.
 * Full implementation is deferred to Phase 4 (Layer 4 — Receiving),
 * once the inbox and Follow handler are implemented.
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\ActivityWiki\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ActivityWiki\Api\ActivityPubModule;
use MediaWiki\Rest\SimpleHandler;

/**
 * Handles GET /activitywiki/followers
 *
 * Returns a spec-compliant empty ActivityPub OrderedCollection.
 * Real follower data will be added in Phase 4.
 */
class FollowersHandler extends SimpleHandler {

    /**
     * The ActivityPub object builder.
     *
     * @var ActivityPubModule
     */
    private ActivityPubModule $module;

    /**
     * @param Config $config Injected by MediaWiki from routes.json "services"
     */
    public function __construct( Config $config ) {
        $this->module = new ActivityPubModule( $config );
    }

    /**
     * Handles the GET request and returns the followers collection.
     *
     * @return \MediaWiki\Rest\Response
     */
    public function run(): \MediaWiki\Rest\Response {
        $json = json_encode(
            $this->module->buildFollowersObject(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );

        if ( $json === false ) {
            return $this->getResponseFactory()->createHttpError( 500, [
                'message' => 'Failed to encode followers object as JSON.',
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
     * Pagination parameters (page, limit) will be added in Phase 4.
     *
     * @return array
     */
    public function getParamSettings(): array {
        return [];
    }
}