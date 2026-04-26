<?php
/**
 * WebFingerHandler — REST handler for the WebFinger discovery endpoint.
 *
 * Serves the WebFinger response at the internal MediaWiki REST path:
 *   GET /activitywiki/webfinger?resource=acct:username@domain
 *
 * This internal path is exposed publicly at the required well-known URL:
 *   GET /.well-known/webfinger?resource=acct:username@domain
 * via an Apache RewriteRule (see README.md — Installation).
 *
 * WebFinger (RFC 7033) is the discovery protocol used by all Fediverse
 * software. When a Mastodon user searches for @wikitrek@wikitrek.org,
 * Mastodon hits this endpoint first. Without a valid response here,
 * the wiki is completely invisible to the Fediverse — the actor endpoint
 * is unreachable regardless of how complete it is.
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\ActivityWiki\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ActivityWiki\Api\ActivityPubModule;
use MediaWiki\Rest\SimpleHandler;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Handles GET /activitywiki/webfinger
 *
 * Validates the "resource" query parameter, checks that it refers to
 * this wiki's actor, and returns a JSON Resource Descriptor (JRD)
 * document pointing to the actor URL.
 *
 * Error responses follow RFC 7033 §4.2:
 * - 400 Bad Request  — missing or malformed "resource" parameter
 * - 404 Not Found    — resource does not match this wiki's actor
 */
class WebFingerHandler extends SimpleHandler {

    /**
     * The ActivityPub object builder, used here only for its URL helpers
     * (getActorUrl, getActorUsername) and config access.
     *
     * @var ActivityPubModule
     */
    private ActivityPubModule $module;

    /**
     * The MediaWiki main config, kept for direct access to $wgServer.
     *
     * @var Config
     */
    private Config $config;

    /**
     * @param Config $config Injected by MediaWiki from routes.json "services"
     */
    public function __construct( Config $config ) {
        $this->config = $config;
        // Reuse ActivityPubModule for all URL building — single source of truth.
        $this->module = new ActivityPubModule( $config );
    }

    /**
     * Handles the GET request and returns the WebFinger JRD document.
     *
     * The flow is:
     * 1. Read the "resource" query parameter.
     * 2. Validate it is a well-formed acct: URI.
     * 3. Check that the username and domain match this wiki's actor.
     * 4. Return the JRD pointing to the actor URL.
     *
     * @return \MediaWiki\Rest\Response
     */
    public function run(): \MediaWiki\Rest\Response {
        // ------------------------------------------------------------------
        // Step 1: read the "resource" query parameter.
        //
        // RFC 7033 §4.1 requires the client to send:
        //   ?resource=acct:username@domain
        // We retrieve it from the validated params (declared in getParamSettings).
        // ------------------------------------------------------------------
        $resource = $this->getValidatedParams()['resource'] ?? '';

        if ( $resource === '' ) {
            return $this->errorResponse( 400, 'Missing required query parameter: resource' );
        }

        // ------------------------------------------------------------------
        // Step 2: validate the resource is an acct: URI.
        //
        // The only resource type we support is "acct:" (RFC 7565).
        // Other schemes (https:, mailto:) are valid WebFinger resources in
        // general, but ActivityPub actor discovery always uses acct:.
        // ------------------------------------------------------------------
        if ( strpos( $resource, 'acct:' ) !== 0 ) {
            return $this->errorResponse( 400, 'Unsupported resource type. Expected acct: URI.' );
        }

        // Strip the "acct:" prefix to get "username@domain".
        $acct = substr( $resource, strlen( 'acct:' ) );

        // Split on "@" to separate username from domain.
        // We expect exactly one "@" — anything else is malformed.
        $parts = explode( '@', $acct, 2 );
        if ( count( $parts ) !== 2 || $parts[0] === '' || $parts[1] === '' ) {
            return $this->errorResponse( 400, 'Malformed acct: URI. Expected acct:username@domain.' );
        }

        [ $requestedUsername, $requestedDomain ] = $parts;

        // ------------------------------------------------------------------
        // Step 3: check the username and domain match this wiki's actor.
        //
        // We derive the expected values from config — the same values used
        // to build the actor object — so they are always in sync.
        // ------------------------------------------------------------------

        // Expected domain: the host portion of $wgServer.
        // parse_url returns false on completely invalid URLs, but $wgServer
        // is always set by MediaWiki, so we guard with ?? '' for static analysis.
        $expectedDomain = parse_url( $this->config->get( 'Server' ), PHP_URL_HOST ) ?? '';

        // Expected username: derived by ActivityPubModule from
        // $wgActivityWikiActorUsername (or normalised from $wgSitename).
        $expectedUsername = $this->module->getActorUsername();

        // Domain comparison is case-insensitive (RFC 7033 §4.2).
        $domainMatches   = strcasecmp( $requestedDomain, $expectedDomain ) === 0;

        // Username comparison is case-insensitive for robustness —
        // some clients send the handle in different cases.
        $usernameMatches = strcasecmp( $requestedUsername, $expectedUsername ) === 0;

        if ( !$domainMatches || !$usernameMatches ) {
            // RFC 7033 §4.2: return 404 when the resource is not found.
            return $this->errorResponse( 404, 'Resource not found on this server.' );
        }

        // ------------------------------------------------------------------
        // Step 4: build and return the JRD (JSON Resource Descriptor).
        //
        // The JRD is the WebFinger response format defined in RFC 7033.
        // It tells the requesting Fediverse server:
        //   "The actor you are looking for is at this URL."
        // ------------------------------------------------------------------
        $actorUrl = $this->module->getActorUrl();

        $jrd = [
            // "subject" mirrors back the canonical acct: URI for this actor.
            // Fediverse clients use this to confirm they reached the right server.
            'subject' => 'acct:' . $expectedUsername . '@' . $expectedDomain,

            // "aliases" lists alternative URLs that identify the same actor.
            // Including the actor URL here helps clients that look for it.
            'aliases' => [
                $actorUrl,
            ],

            // "links" is the core of the JRD — an array of typed relations.
            // We include two links, both required for ActivityPub discovery:
            'links' => [
                [
                    // rel: identifies what this link is for.
                    // "self" with type application/activity+json tells Mastodon
                    // "fetch THIS URL to get the actor object".
                    'rel'  => 'self',
                    'type' => 'application/activity+json',
                    'href' => $actorUrl,
                ],
                [
                    // rel: OStatus profile page — included for broad compatibility.
                    // Older GnuSocial and some other implementations look for this.
                    // Points to the wiki's main page as a human-readable profile.
                    'rel'  => 'http://webfinger.net/rel/profile-page',
                    'type' => 'text/html',
                    'href' => $this->module->getWikiBaseUrl() . '/index.php',
                ],
            ],
        ];

        // Encode to JSON. Same flags as ActorHandler for consistency.
        $json = json_encode( $jrd, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

        if ( $json === false ) {
            return $this->errorResponse( 500, 'Failed to encode WebFinger response as JSON.' );
        }

        // Build the HTTP response with the correct WebFinger media type.
        // RFC 7033 §10.2 defines "application/jrd+json" as the media type
        // for WebFinger responses. Some implementations also accept
        // "application/json", but the spec-correct value is "application/jrd+json".
        $response = $this->getResponseFactory()->create();
        $response->setHeader( 'Content-Type', 'application/jrd+json; charset=UTF-8' );

        // CORS header: required so that browser-based Fediverse clients
        // (e.g. web frontends for Mastodon) can fetch this endpoint.
        $response->setHeader( 'Access-Control-Allow-Origin', '*' );

        // Cache-Control: the WebFinger JRD changes only when config changes.
        // 1 hour matches the actor endpoint cache duration for consistency.
        $response->setHeader( 'Cache-Control', 'public, max-age=3600' );

        $response->getBody()->write( $json );

        return $response;
    }

    /**
     * Declares the query parameters accepted by this endpoint.
     *
     * "resource" is the only parameter defined by RFC 7033.
     * It is marked as not required here so that MediaWiki passes it through
     * without triggering a generic framework validation error. We validate
     * its presence manually in run() to return a spec-compliant 400 response
     * with a meaningful message instead of MediaWiki's default error format.
     *
     * @return array
     */
    public function getParamSettings(): array {
        return [
            'resource' => [
                self::PARAM_SOURCE => 'query',
                ParamValidator::PARAM_TYPE     => 'string',
                ParamValidator::PARAM_REQUIRED => false,
                ParamValidator::PARAM_DEFAULT  => '',
            ],
        ];
    }

    /**
     * Builds a JSON error response with the given HTTP status code.
     *
     * We use a custom method rather than getResponseFactory()->createHttpError()
     * because WebFinger error responses must carry Content-Type: application/json
     * (not the default MediaWiki error content type). This keeps error responses
     * consistent and machine-readable for Fediverse clients that inspect the body.
     *
     * @param int    $status  HTTP status code (400, 404, 500, …)
     * @param string $message Human-readable error description
     * @return \MediaWiki\Rest\Response
     */
    private function errorResponse( int $status, string $message ): \MediaWiki\Rest\Response {
        $body = json_encode( [ 'error' => $message ], JSON_UNESCAPED_SLASHES );
        $response = $this->getResponseFactory()->create();
        $response->setStatus( $status );
        $response->setHeader( 'Content-Type', 'application/json; charset=UTF-8' );
        $response->getBody()->write( $body !== false ? $body : '{"error":"Internal error"}' );
        return $response;
    }
}