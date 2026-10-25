<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\ActivityWiki;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ActivityWiki\Api\ActivityPubModule;
use MediaWiki\Http\HttpRequestFactory;

/**
 * WebFingerStatusChecker — performs a live, outbound probe of this wiki's
 * own public WebFinger endpoint and reports whether Fediverse discovery is
 * actually working.
 *
 * BACKGROUND — why this needs to be a *live* check rather than a config
 * read:
 * Every other REST endpoint (actor, inbox, outbox, followers) is served
 * directly by MediaWiki's own REST router, so if the extension is enabled
 * at all, they work. WebFinger is different: RFC 7033 requires it to be
 * served from "/.well-known/webfinger" at the domain root, which is
 * outside MediaWiki's URL space entirely. Making that work requires a
 * *manual* step from the wiki operator — copying entry-points/webfinger.php
 * into place and adding a web-server rewrite rule (see README.md,
 * "WebFinger routing"). There is nothing in $wgActivityWiki* config that
 * tells us whether the operator actually did this.
 *
 * The Phase 4 session (2026-06-20) found out the hard way what happens when
 * this silently isn't working: WebFinger was broken in production for the
 * entire time Phase 1 was "deployed", and nothing surfaced it until a real
 * Mastodon instance tried to look the wiki up (see the bug writeup in
 * ActivityWiki-plan.md). The whole point of this class is to make that
 * kind of silent failure visible on Special:ActivityWiki *before* a real
 * Fediverse server finds it first.
 *
 * This class does not know or care about HTML rendering — it returns a
 * WebFingerCheckResult and leaves display entirely to the caller
 * (SpecialActivityWiki), the same separation of concerns ActorFetcher
 * already established for actor-document fetches.
 *
 * @since 1.2.0 (Phase 5 — Administration)
 */
class WebFingerStatusChecker {

	/**
	 * Matches the Accept header WebFinger clients are expected to send per
	 * RFC 7033 §10.2. We send both the spec-correct and the commonly
	 * accepted fallback media type, the same reasoning WebFingerHandler's
	 * own response documents.
	 */
	private const ACCEPT_HEADER = 'application/jrd+json, application/json';

	/** Matches ActorFetcher's log channel so all federation diagnostics land in one place. */
	private const LOG_CHANNEL = 'ActivityWiki';

	/** @var HttpRequestFactory Used to perform the outbound probe GET. */
	private HttpRequestFactory $httpRequestFactory;

	/**
	 * @var Config The main wiki config, used to read $wgServer (to build
	 *   the public probe URL, mirroring exactly what WebFingerHandler
	 *   itself checks incoming requests against).
	 */
	private Config $config;

	/**
	 * @var ActivityPubModule Reused only for getActorUsername() — the same
	 *   username-resolution logic (explicit config override, else
	 *   slugified $wgSitename) that WebFingerHandler uses to decide what
	 *   "resource" value a request must match. Built internally rather
	 *   than injected because this class owns exactly how it's built —
	 *   mirrors WebFingerHandler's own constructor, which builds its
	 *   ActivityPubModule from a Config + KeyManager pair for the same
	 *   reason (ActivityPubModule has no constructor overload that omits
	 *   KeyManager, even though neither WebFingerHandler nor this class
	 *   ever calls a key-related method on it).
	 */
	private ActivityPubModule $module;

	/**
	 * @param HttpRequestFactory $httpRequestFactory MediaWiki's HTTP client factory.
	 * @param Config $config The main wiki config.
	 * @param KeyManager $keyManager Required to construct ActivityPubModule
	 *   (see the $module property docblock above) — not otherwise used by
	 *   this class.
	 */
	public function __construct( HttpRequestFactory $httpRequestFactory, Config $config, KeyManager $keyManager ) {
		$this->httpRequestFactory = $httpRequestFactory;
		$this->config             = $config;
		$this->module             = new ActivityPubModule( $config, $keyManager );
	}

	/**
	 * Probe this wiki's public WebFinger endpoint and report the result.
	 *
	 * @return WebFingerCheckResult
	 */
	public function check(): WebFingerCheckResult {
		$expectedAcct = $this->buildExpectedAcct();
		$probedUrl    = $this->buildProbeUrl( $expectedAcct );

		$request = $this->httpRequestFactory->create(
			$probedUrl,
			[
				'method'    => 'GET',
				// Identify ourselves the same way ActorFetcher does for
				// outbound actor fetches — some servers behave differently
				// for requests with no recognizable User-Agent. Less
				// critical here since we are only ever talking to our own
				// web server, but there is no reason to send an anonymous
				// request when a self-identifying one costs nothing.
				'userAgent' => 'ActivityWiki-StatusCheck/1.0',
				// Keep this check snappy — Special:ActivityWiki should never
				// hang waiting on the operator's own web server. 5 seconds
				// is generous for a same-host loopback-style request.
				'timeout'   => 5,
			],
			__METHOD__
		);
		$request->setHeader( 'Accept', self::ACCEPT_HEADER );

		$status = $request->execute();

		// getStatus() returns the HTTP status code MediaWiki actually
		// received, if any — distinct from $status->isOK(), which also
		// fails on pure network errors (DNS, connection refused, timeout)
		// where there was never an HTTP status to begin with. We want both
		// pieces of information: isOK() to decide the state, getStatus()
		// to show the operator exactly what came back when something did.
		$httpStatusCode = $request->getStatus();
		$httpStatusCode = $httpStatusCode > 0 ? $httpStatusCode : null;

		if ( !$status->isOK() ) {
			wfDebugLog(
				self::LOG_CHANNEL,
				"WebFingerStatusChecker: probe of {$probedUrl} failed outright (no usable HTTP response)."
			);
			return new WebFingerCheckResult(
				WebFingerCheckResult::STATE_UNREACHABLE,
				$probedUrl,
				$httpStatusCode,
				$httpStatusCode === null
					? 'Could not connect at all (DNS failure, connection refused, or timeout). ' .
						'The entry-point file and web-server rewrite rule from README.md\'s ' .
						'"WebFinger routing" section are probably not set up yet.'
					: "Server responded with HTTP {$httpStatusCode}, not a successful WebFinger response. " .
						'Check that the rewrite rule points at entry-points/webfinger.php and that ' .
						'$wgScriptPath inside that file matches your installation.'
			);
		}

		$body = json_decode( $request->getContent(), true );

		if ( !is_array( $body ) || !isset( $body['subject'] ) || !is_string( $body['subject'] ) ) {
			wfDebugLog(
				self::LOG_CHANNEL,
				"WebFingerStatusChecker: probe of {$probedUrl} returned HTTP 200 but no valid WebFinger JRD body."
			);
			return new WebFingerCheckResult(
				WebFingerCheckResult::STATE_MISCONFIGURED,
				$probedUrl,
				$httpStatusCode,
				'Got an HTTP 200 response, but the body is not a valid WebFinger JRD document. ' .
					'The rewrite rule is probably pointing somewhere other than entry-points/webfinger.php ' .
					'(e.g. at a generic MediaWiki 404, or an unrelated page).'
			);
		}

		if ( strcasecmp( $body['subject'], $expectedAcct ) !== 0 ) {
			wfDebugLog(
				self::LOG_CHANNEL,
				"WebFingerStatusChecker: probe of {$probedUrl} returned subject \"{$body['subject']}\", " .
					"expected \"{$expectedAcct}\"."
			);
			return new WebFingerCheckResult(
				WebFingerCheckResult::STATE_MISCONFIGURED,
				$probedUrl,
				$httpStatusCode,
				"Got a valid-looking WebFinger response, but for the wrong actor (\"{$body['subject']}\" " .
					"instead of \"{$expectedAcct}\"). Double-check \$wgScriptPath inside " .
					'entry-points/webfinger.php — a mismatched script path can route the request to the ' .
					'wrong wiki on a multi-wiki installation.'
			);
		}

		wfDebugLog( self::LOG_CHANNEL, "WebFingerStatusChecker: probe of {$probedUrl} succeeded." );

		return new WebFingerCheckResult(
			WebFingerCheckResult::STATE_OK,
			$probedUrl,
			$httpStatusCode,
			'WebFinger discovery is reachable and correctly configured.'
		);
	}

	/**
	 * Builds the "acct:username@domain" string this wiki expects to see
	 * echoed back in a successful WebFinger response's "subject" field.
	 *
	 * Deliberately uses the exact same two inputs — $wgServer's host and
	 * ActivityPubModule::getActorUsername() — that WebFingerHandler itself
	 * validates incoming requests against, so this check can never produce
	 * a false negative by drifting out of sync with the real validation
	 * logic.
	 *
	 * @return string e.g. "acct:wikitrek@wikitrek.org"
	 */
	private function buildExpectedAcct(): string {
		$domain = parse_url( $this->config->get( 'Server' ), PHP_URL_HOST ) ?? '';
		return 'acct:' . $this->module->getActorUsername() . '@' . $domain;
	}

	/**
	 * Builds the full public, well-known WebFinger URL to probe.
	 *
	 * This is deliberately the *public* "/.well-known/webfinger" URL — not
	 * the internal "rest.php/activitywiki/webfinger" path WebFingerHandler
	 * is registered at — because the whole point of this check is to
	 * verify the complete chain an outside Fediverse server would actually
	 * use: web-server rewrite rule, entry-points/webfinger.php, and the
	 * REST route, all together. Probing the internal REST path directly
	 * would always succeed and tell the operator nothing about whether
	 * their manual setup step actually worked.
	 *
	 * @param string $expectedAcct The "acct:username@domain" string to request.
	 * @return string e.g.
	 *   "https://wikitrek.org/.well-known/webfinger?resource=acct%3Awikitrek%40wikitrek.org"
	 */
	private function buildProbeUrl( string $expectedAcct ): string {
		// Reuse $wgServer's own scheme (http/https) rather than hardcoding
		// one, so this works unmodified on a dev install that legitimately
		// runs over plain HTTP.
		$server = $this->config->get( 'Server' );
		$scheme = parse_url( $server, PHP_URL_SCHEME ) ?? 'https';
		$host   = parse_url( $server, PHP_URL_HOST ) ?? '';

		return $scheme . '://' . $host . '/.well-known/webfinger?' .
			http_build_query( [ 'resource' => $expectedAcct ] );
	}
}