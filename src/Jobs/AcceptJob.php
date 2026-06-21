<?php

declare( strict_types=1 );

/**
 * ActivityWiki — AcceptJob
 *
 * Delivers a single pre-built ActivityPub activity (in practice, an `Accept`
 * activity in response to an incoming `Follow`) to exactly one inbox URL.
 *
 * This is deliberately NOT a generalisation of DeliveryJob. DeliveryJob owns
 * fan-out-to-all-followers and is tightly coupled to the activitywiki_activities
 * table, because outbound content activities (Create/Update/Delete on a page)
 * are things we want a durable record of. An Accept is a one-off protocol
 * handshake reply to a single actor — it has no page, no user, and nothing
 * worth persisting once delivered. Trying to force it through DeliveryJob's
 * shape would mean inserting placeholder values into columns that do not
 * apply, which is the kind of unclean fit we want to avoid.
 *
 * AcceptJob therefore carries everything it needs directly in its job
 * params — the destination inbox URL and the already-serialized activity
 * JSON — with no database lookup at all. Its only job is: sign, POST, retry
 * on failure, log the outcome. The retry strategy (re-sign on every attempt,
 * fixed delay, $wgActivityWikiDeliveryRetries attempts, always return true)
 * is intentionally identical to DeliveryJob's per-follower loop, since it is
 * solving the exact same narrow problem — "reliably deliver one signed
 * activity to one inbox" — just without the surrounding fan-out and DB
 * bookkeeping that problem doesn't need here.
 *
 * Enqueued by FollowManager::handleFollow() after a new follower is recorded.
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\ActivityWiki\Jobs;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ActivityWiki\HttpSigner;
use MediaWiki\Http\HttpRequestFactory;
use RuntimeException;

class AcceptJob extends \Job {

	// -------------------------------------------------------------------------
	// Constants
	// -------------------------------------------------------------------------

	/**
	 * Microseconds to wait between retry attempts (500 ms).
	 *
	 * Matches DeliveryJob::RETRY_DELAY_US exactly — same reasoning: a short
	 * fixed delay is sufficient for transient network blips on a single POST.
	 */
	private const RETRY_DELAY_US = 500_000;

	/**
	 * The Content-Type header value required by the ActivityPub specification
	 * for all outbound activities.
	 */
	private const CONTENT_TYPE = 'application/activity+json';

	// -------------------------------------------------------------------------
	// Injected services
	// -------------------------------------------------------------------------

	/** @var HttpSigner Generates the HTTP Signature headers for the POST. */
	private HttpSigner $httpSigner;

	/** @var HttpRequestFactory Creates MWHttpRequest instances for the HTTP POST. */
	private HttpRequestFactory $httpRequestFactory;

	/** @var Config MediaWiki main configuration, used for $wgActivityWikiDeliveryRetries. */
	private Config $config;

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	/**
	 * Construct a new AcceptJob.
	 *
	 * All services are injected by the MediaWiki service container via the
	 * JobClasses + ObjectFactory wiring in extension.json, the same pattern
	 * already used for DeliveryJob.
	 *
	 * @param array $params Job parameters. Must contain:
	 *   - 'inboxUrl' (string): the destination inbox URL to POST to.
	 *   - 'activityJson' (string): the already-serialized activity JSON body.
	 * @param HttpSigner $httpSigner HTTP Signature service.
	 * @param HttpRequestFactory $httpRequestFactory HTTP client factory.
	 * @param Config $config MediaWiki main configuration object.
	 */
	public function __construct(
		array $params,
		HttpSigner $httpSigner,
		HttpRequestFactory $httpRequestFactory,
		Config $config
	) {
		// Job name matches the key under JobClasses in extension.json.
		parent::__construct( 'MediawikiActivityPubAccept', $params );

		$this->httpSigner         = $httpSigner;
		$this->httpRequestFactory = $httpRequestFactory;
		$this->config             = $config;
	}

	// -------------------------------------------------------------------------
	// Job execution
	// -------------------------------------------------------------------------

	/**
	 * Execute the job: sign and POST the activity to the inbox URL, with retries.
	 *
	 * Always returns true, for the same reason documented on DeliveryJob::run():
	 * returning false would cause MediaWiki's job runner to re-run the entire
	 * job, which here would mean re-sending the Accept from scratch (signing
	 * is idempotent in effect — sending an extra Accept is harmless to the
	 * remote server — but there is still no benefit to relying on MediaWiki's
	 * own retry over our own bounded retry loop below).
	 *
	 * @return bool Always true.
	 */
	public function run(): bool {
		// ------------------------------------------------------------------
		// Step 1 — Guard: require both params.
		//
		// Both are mandatory; FollowManager always supplies both. If either
		// is missing this job was constructed incorrectly upstream — there
		// is nothing to send and retrying would not help.
		// ------------------------------------------------------------------
		if ( !isset( $this->params['inboxUrl'], $this->params['activityJson'] ) ) {
			wfDebugLog(
				'ActivityWiki',
				'AcceptJob: missing inboxUrl or activityJson parameter — aborting.'
			);
			return true;
		}

		$inboxUrl      = $this->params['inboxUrl'];
		$activityJson  = $this->params['activityJson'];

		// ------------------------------------------------------------------
		// Step 2 — Read the retry limit from configuration.
		//
		// Same config variable and same "at least 1 attempt" floor as
		// DeliveryJob, for consistent behaviour across both job types.
		// ------------------------------------------------------------------
		$maxAttempts = max( 1, (int)$this->config->get( 'ActivityWikiDeliveryRetries' ) );

		// ------------------------------------------------------------------
		// Step 3 — Attempt delivery, re-signing on every attempt.
		//
		// The HTTP Signature covers the Date header, which must reflect the
		// actual send time — a signature built for attempt 1 will be stale
		// (and rejected) if reused on attempt 2, so we sign fresh every time,
		// exactly as DeliveryJob does in its per-follower loop.
		// ------------------------------------------------------------------
		$delivered  = false;
		$lastStatus = null;
		$lastError  = null;

		for ( $attempt = 1; $attempt <= $maxAttempts; $attempt++ ) {
			try {
				$signedHeaders = $this->httpSigner->signRequest( $inboxUrl, $activityJson );
			} catch ( RuntimeException $e ) {
				// Key unavailable — every further attempt would fail the
				// same way, so abort immediately rather than burn the
				// remaining attempts.
				wfDebugLog(
					'ActivityWiki',
					'AcceptJob: CRITICAL — signing failed, aborting delivery to '
					. "{$inboxUrl}. Reason: " . $e->getMessage()
				);
				return true;
			}

			// CONFIRMED VIA LIVE TESTING (2026-06-20): the 'headers' options
			// array key does NOT set request headers on MWHttpRequest —
			// confirmed by reading MWHttpRequest's and GuzzleHttpRequest's
			// constructors directly; neither ever reads $options['headers'].
			// The only correct API is setHeader(), called per-header below.
			// Without this fix, every Accept activity would have been sent
			// unsigned, just like the identical bug found in DeliveryJob.php
			// and SignatureVerifier.php this same session — see
			// ActivityWiki-plan.md for the full writeup.
			$request = $this->httpRequestFactory->create(
				$inboxUrl,
				[
        'method'    => 'POST',
        // CONFIRMED VIA LIVE TESTING (2026-06-21): MWHttpRequest's
        // constructor only reads a fixed allow-list of option keys —
        // 'postData', 'proxy', 'noProxy', 'sslVerifyHost', 'caInfo',
        // 'method', 'followRedirects', 'maxRedirects', 'sslVerifyCert',
        // 'callback' (see MWHttpRequest::__construct(), $members array).
        // 'body' is NOT one of them and is silently ignored — exactly
        // the same class of bug as the earlier 'headers' issue, just on
        // a different key. The correct key for the POST payload is
        // 'postData'. Without this fix, every Accept activity was sent
        // with a completely empty body, causing the receiving server's
        // computed Digest to mismatch ours and reject the signature —
        // surfacing as a 401, even though the signature itself was
        // mathematically correct. See ActivityWiki-plan.md for the
        // full writeup.
        'postData'  => $activityJson,
    ],
    __METHOD__
);

			foreach ( $signedHeaders as $headerName => $headerValue ) {
				$request->setHeader( $headerName, $headerValue );
			}
			$request->setHeader( 'Content-Type', self::CONTENT_TYPE );

			$status   = $request->execute();
			$httpCode = $request->getStatus();

			// Any 2xx status counts as success — Mastodon typically replies
			// 202 Accepted, matching the convention DeliveryJob already uses.
			if ( $status->isOK() && $httpCode >= 200 && $httpCode < 300 ) {
				$delivered  = true;
				$lastStatus = $httpCode;
				break;
			}

			$lastStatus = $httpCode;
			$lastError  = $status->getMessage()->text();

			if ( $attempt < $maxAttempts ) {
				usleep( self::RETRY_DELAY_US );
			}
		}

		// ------------------------------------------------------------------
		// Step 4 — Log the outcome.
		//
		// There is no DB row to mark as published here (see class docblock)
		// — the debug log is the only record of this delivery attempt, which
		// is acceptable for a protocol handshake reply rather than durable
		// wiki content.
		// ------------------------------------------------------------------
		if ( $delivered ) {
			wfDebugLog(
				'ActivityWiki',
				"AcceptJob: delivered Accept to {$inboxUrl} (HTTP {$lastStatus})."
			);
		} else {
			wfDebugLog(
				'ActivityWiki',
				"AcceptJob: FAILED to deliver Accept to {$inboxUrl} after "
				. "{$maxAttempts} attempt(s). HTTP status: {$lastStatus}. "
				. "Error: {$lastError}"
			);
		}

		return true;
	}
}