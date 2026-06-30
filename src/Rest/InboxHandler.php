<?php
/**
 * InboxHandler — REST handler for the ActivityPub inbox endpoint.
 *
 * Accepts incoming activities at:
 *   POST /activitywiki/inbox
 *
 * This is the wiki's single point of contact for everything the Fediverse
 * sends *to* the wiki — as opposed to DeliveryJob, which handles everything
 * the wiki sends *out*. In practice this means social/protocol activities:
 * a Mastodon user following or unfollowing the wiki. It does not receive
 * wiki content of any kind.
 *
 * Per the scope agreed for Phase 4 (see ActivityWiki-plan.md, Layer 6 note),
 * only `Follow` and `Undo{Follow}` are actually handled. Every other incoming
 * activity type (Like, Announce, Create-as-reply, etc.) is acknowledged with
 * a bare 202 Accepted and silently dropped — this is required behaviour, not
 * a gap: replying with an error for an activity type we simply choose not to
 * act on would make remote servers believe delivery is broken and retry
 * indefinitely.
 *
 * This handler is deliberately thin. It owns only HTTP concerns — reading
 * the request, deciding the response code — and delegates everything else:
 *   - Signature verification → SignatureVerifier
 *   - Follow/Undo business logic (DB writes, Accept activity) → FollowManager
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\ActivityWiki\Rest;

use MediaWiki\Extension\ActivityWiki\FollowManager;
use MediaWiki\Extension\ActivityWiki\SignatureVerifier;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;

/**
 * Handles POST /activitywiki/inbox
 *
 * Verifies the HTTP Signature on every incoming request before looking at
 * its content. Unsigned or invalidly-signed requests are rejected with 401
 * before any JSON parsing or business logic runs.
 */
class InboxHandler extends SimpleHandler {

	/** @var SignatureVerifier Verifies the HTTP Signature on the incoming request. */
	private SignatureVerifier $signatureVerifier;

	/** @var FollowManager Owns Follow/Undo business logic. */
	private FollowManager $followManager;

	/**
	 * @param SignatureVerifier $signatureVerifier Injected by MediaWiki from
	 *   routes.json "services".
	 * @param FollowManager $followManager Injected by MediaWiki from
	 *   routes.json "services".
	 */
	public function __construct( SignatureVerifier $signatureVerifier, FollowManager $followManager ) {
		$this->signatureVerifier = $signatureVerifier;
		$this->followManager     = $followManager;
	}

	/**
	 * Handles the POST request.
	 *
	 * @return Response
	 */
	public function run(): Response {
		$request = $this->getRequest();

		// ------------------------------------------------------------------
		// Step 1 — Gather the raw request data SignatureVerifier needs.
		//
		// getHeaders() returns array<string, array<string>> — each header
		// name maps to an array of values, since a header is legally
		// allowed to repeat. Signature, Date, Host, and Digest are always
		// single-valued in practice for every ActivityPub implementation we
		// need to interoperate with, so we flatten to the first value only
		// rather than joining repeats with ", " — simpler, and correct for
		// every header SignatureVerifier actually inspects.
		// ------------------------------------------------------------------
		$method = $request->getMethod();
		$path   = $request->getUri()->getPath();
		$body   = $request->getBody()->getContents();

		// Diagnostic only: records the raw body length so a real test run
		// can confirm directly whether the body arrived intact, rather than
		// inferring it indirectly from whether verify() below succeeds or
		// fails. This was added after Phase 4 testing surfaced uncertainty
		// about whether MediaWiki's REST framework might consume the body
		// stream during its own content-type/JSON handling before run() get
		// a chance to read it — see ActivityWiki-plan.md, section 4.1.
		// Safe to remove once that uncertainty is resolved by a real test.
		wfDebugLog( 'ActivityWiki', 'InboxHandler: received request, body length = ' . strlen( $body ) );

		$headers = [];
		foreach ( $request->getHeaders() as $name => $values ) {
			$headers[ $name ] = $values[0] ?? '';
		}

		// ------------------------------------------------------------------
		// Step 2 — Verify the HTTP Signature.
		//
		// This must happen before we look at the body's content at all.
		// An unsigned or invalidly-signed request is rejected outright —
		// we do not parse JSON, do not look at activity type, and do not
		// touch the database for a request we cannot authenticate.
		// ------------------------------------------------------------------
		if ( !$this->signatureVerifier->verify( $method, $path, $headers, $body ) ) {
			wfDebugLog( 'ActivityWiki', 'InboxHandler: rejected request — signature verification failed.' );

			return $this->getResponseFactory()->createHttpError( 401, [
				'message' => 'Invalid or missing HTTP Signature.',
			] );
		}

		// ------------------------------------------------------------------
		// Step 3 — Decode the activity JSON.
		//
		// A request can pass signature verification (it really did come
		// from the actor it claims to) and still carry a malformed body —
		// these are independent checks. A malformed body is a client error,
		// not an authentication failure, so it gets 400 rather than 401.
		// ------------------------------------------------------------------
		$activity = json_decode( $body, true );

		if ( !is_array( $activity ) ) {
			wfDebugLog( 'ActivityWiki', 'InboxHandler: rejected request — body is not valid JSON.' );

			return $this->getResponseFactory()->createHttpError( 400, [
				'message' => 'Request body is not valid JSON.',
			] );
		}

		// ------------------------------------------------------------------
		// Step 4 — Dispatch on activity type.
		//
		// Only Follow and Undo{Follow} are handled, per the scope agreed
		// for Phase 4. Everything else falls through to the final 202 below
		// without any action being taken — see class docblock for why this
		// is correct behaviour rather than a missing feature.
		// ------------------------------------------------------------------
		$type = $activity['type'] ?? null;

		if ( $type === 'Follow' ) {
			$this->followManager->handleFollow( $activity );
		} elseif ( $type === 'Undo' && ( $activity['object']['type'] ?? null ) === 'Follow' ) {
			$this->followManager->handleUndo( $activity );
		} else {
			// Diagnostic only: captures who sent an unsupported activity type and
    // what object it referenced. Added to investigate a recurring stream
    // of unexplained "Delete" activities hitting the inbox — safe to
    // remove once the source is identified. Does not affect behaviour:
    // we still take no action and still return 202 below.
    $actor = $activity['actor'] ?? 'unknown';
    $objectId = is_array( $activity['object'] ?? null )
        ? ( $activity['object']['id'] ?? 'unknown' )
        : ( $activity['object'] ?? 'unknown' );

    wfDebugLog(
        'ActivityWiki',
        "InboxHandler: received unsupported activity type \"{$type}\" from actor "
        . "\"{$actor}\" (object: \"{$objectId}\") — acknowledged, no action taken."
    );
		}

		// ------------------------------------------------------------------
		// Step 5 — Acknowledge.
		//
		// A bare 202 Accepted with an empty body, for every handled and
		// unhandled-but-recognised-as-valid case alike. This matches what
		// Mastodon itself returns from its own inbox endpoint, and the
		// ActivityPub spec does not require any particular response body.
		//
		// ResponseFactory::createNoContent() is NOT used here — it is
		// hardcoded to status 204 and takes no status argument, so it
		// cannot produce a 202. We build a bare Response via create()
		// instead (the same approach ActorHandler uses for its own
		// custom-header response) and set the status explicitly.
		// ------------------------------------------------------------------
		$response = $this->getResponseFactory()->create();
		$response->setStatus( 202 );

		return $response;
	}

	/**
	 * Declares that this endpoint takes no path or query parameters.
	 *
	 * The activity is sent entirely in the request body — there are no
	 * path variables or query parameters on this endpoint.
	 *
	 * @return array
	 */
	public function getParamSettings(): array {
		return [];
	}

	/**
	 * Declares the Content-Type values this endpoint will accept.
	 *
	 * REQUIRED — without this override, MediaWiki's REST framework rejects
	 * every incoming request with a 415 Unsupported Media Type before run()
	 * is ever called, because the base Handler class's default supported
	 * type list only includes "application/json", not the ActivityPub
	 * media type "application/activity+json" that Mastodon and other
	 * Fediverse software actually send. This was confirmed against a real
	 * request during Phase 4 testing (see ActivityWiki-plan.md, section
	 * 4.1) — every real Follow activity was being rejected outright.
	 *
	 * @return string[]
	 */
	public function getSupportedRequestTypes(): array {
		return [ 'application/activity+json', 'application/ld+json', 'application/json' ];
	}
}