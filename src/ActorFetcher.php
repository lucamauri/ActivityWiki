<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\ActivityWiki;

use MediaWiki\Http\HttpRequestFactory;

/**
 * ActorFetcher — fetches and decodes a remote ActivityPub actor document.
 *
 * Both SignatureVerifier (to obtain a sender's public key, during inbound
 * signature verification) and FollowManager (to obtain a follower's inbox
 * URL, when handling an incoming Follow) previously each performed their
 * own, independent HTTP GET of a remote actor document — same request
 * shape, same headers, same JSON decoding, only the field extracted at the
 * end differed. This class consolidates that shared mechanics into one
 * place (Phase 5, Task 2 of the project plan).
 *
 * Deliberately, this class does NOT know or care which field a caller is
 * after. It fetches the actor document and hands back the full decoded
 * JSON body as an associative array, leaving field extraction — publicKey,
 * inbox, sharedInbox, or anything else — entirely to the caller. This
 * keeps it a general-purpose "get me this actor's document" service, so
 * future callers (e.g. the actor-caching layer noted in
 * ActivityWiki-plan.md, section 3) can reuse it without this class needing
 * to change.
 *
 * @since 1.0.2
 */
class ActorFetcher {

	/**
	 * The Accept header value used when fetching a remote actor document.
	 *
	 * This must match the media type Mastodon and other Fediverse software
	 * expect to be asked for; without it, some servers (notably Mastodon)
	 * would otherwise serve an HTML profile page instead of the JSON
	 * actor document.
	 */
	private const ACTOR_MEDIA_TYPE = 'application/activity+json';

	/**
	 * The MediaWiki debug log channel used by this class.
	 *
	 * Must match the channel name configured in LocalSettings.php exactly —
	 * see the project's "log channel" lesson learned in earlier phases.
	 */
	private const LOG_CHANNEL = 'ActivityWiki';

	/**
	 * @var HttpRequestFactory Used to perform the outbound HTTP GET
	 *   against the remote actor URL.
	 */
	private HttpRequestFactory $httpRequestFactory;

	/**
	 * @var WikiActorUrls Provides this wiki's own base URL and actor URL,
	 *   used to build the self-identifying User-Agent header sent on
	 *   every outbound actor-document fetch. Without an explicit,
	 *   self-identifying User-Agent, Mastodon redirects the request to
	 *   an HTML profile page instead of serving the JSON actor document
	 *   — confirmed via live testing during Phase 4 (see
	 *   ActivityWiki-plan.md, section 4.5).
	 */
	private WikiActorUrls $wikiActorUrls;

	/**
	 * @param HttpRequestFactory $httpRequestFactory MediaWiki's HTTP client factory.
	 * @param WikiActorUrls $wikiActorUrls Provides this wiki's own base URL
	 *   and actor URL.
	 */
	public function __construct( HttpRequestFactory $httpRequestFactory, WikiActorUrls $wikiActorUrls ) {
		$this->httpRequestFactory = $httpRequestFactory;
		$this->wikiActorUrls      = $wikiActorUrls;
	}

	/**
	 * Fetch and decode a remote actor document.
	 *
	 * @param string $actorUrl The actor URL to fetch, e.g.
	 *   "https://mastodon.social/users/alice". Must NOT include a
	 *   fragment (e.g. a trailing "#main-key") — callers with a keyId-style
	 *   URL are responsible for stripping the fragment themselves before
	 *   calling this method, since fragment handling is specific to the
	 *   HTTP Signature scheme, not to fetching actor documents in general.
	 * @return array<string,mixed>|null The decoded actor JSON as an
	 *   associative array, or null on any failure (network error, non-OK
	 *   HTTP response, or a body that is not valid JSON).
	 */
	public function fetchActorDocument( string $actorUrl ): ?array {
		$request = $this->httpRequestFactory->create(
			$actorUrl,
			[
				'method'    => 'GET',
				// CONFIRMED VIA LIVE TESTING (2026-06-20): MediaWiki's
				// HttpRequestFactory defaults to a generic User-Agent of
				// "MediaWiki/<version>" with no identifying information.
				// Mastodon treats requests with an unrecognized User-Agent
				// as likely coming from a browser and responds with a 301
				// redirect to the human-readable HTML profile page
				// instead of serving the ActivityPub JSON actor document
				// — even though the Accept header correctly requests
				// application/activity+json. Setting an explicit,
				// self-identifying User-Agent (as virtually all federated
				// ActivityPub software does) avoids the redirect entirely.
				'userAgent' => $this->buildUserAgent(),
			],
			__METHOD__
		);

		// The 'headers' key inside the options array above is NOT read by
		// MWHttpRequest/GuzzleHttpRequest — it is silently ignored. The
		// only correct way to set a request header is this instance
		// method, called after create() and before execute().
		$request->setHeader( 'Accept', self::ACTOR_MEDIA_TYPE );

		$status = $request->execute();

		if ( !$status->isOK() ) {
			wfDebugLog( self::LOG_CHANNEL, "ActorFetcher: GET {$actorUrl} did not return an OK status." );
			return null;
		}

		$actorJson = json_decode( $request->getContent(), true );

		if ( !is_array( $actorJson ) ) {
			wfDebugLog( self::LOG_CHANNEL, "ActorFetcher: response body from {$actorUrl} is not valid JSON." );
			return null;
		}

		return $actorJson;
	}

	/**
	 * Build the User-Agent header value sent on outbound actor-document
	 * fetches.
	 *
	 * Identifies this wiki's actor URL so that remote servers treat the
	 * request as coming from federated software rather than an anonymous
	 * browser — see the docblock on fetchActorDocument() above for why
	 * this matters.
	 *
	 * @return string e.g. "ActivityWiki/1.0 (+https://example.org/w/rest.php/activitywiki/actor)"
	 */
	private function buildUserAgent(): string {
		return 'ActivityWiki/1.0 (+' . $this->wikiActorUrls->getWikiActorUrl() . ')';
	}
}