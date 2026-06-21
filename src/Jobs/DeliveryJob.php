<?php

declare( strict_types=1 );

/**
 * ActivityWiki — DeliveryJob
 *
 * Processes a single queued ActivityPub delivery: fetches the activity from
 * the database, reads all follower inbox URLs, and POSTs the signed activity
 * to each one, retrying on transient failures.
 *
 * This job is enqueued by DeliveryQueue::queueActivity() on every qualifying
 * page event and processed asynchronously by MediaWiki's job queue runner.
 *
 * Delivery strategy (Phase 3):
 *   - One job covers all followers ("fan-out inside the job").
 *   - Each follower is attempted independently: a failure for one inbox does
 *     NOT abort delivery to the others.
 *   - Each failed attempt is retried up to $wgActivityWikiDeliveryRetries
 *     times with a short fixed delay between attempts.
 *   - The HTTP Signature is re-generated on every attempt because the Date
 *     header it covers must reflect the actual send time; a stale signature
 *     will be rejected by the remote server.
 *   - run() always returns true. Returning false would cause MediaWiki to
 *     re-run the entire job (re-sending to all followers), which would
 *     duplicate successful deliveries. Per-follower failures are logged
 *     instead and can be surfaced by a future admin panel (Phase 5).
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\ActivityWiki\Jobs;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ActivityWiki\HttpSigner;
use MediaWiki\Http\HttpRequestFactory;
use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;

class DeliveryJob extends \Job {

	// -------------------------------------------------------------------------
	// Constants
	// -------------------------------------------------------------------------

	/**
	 * Microseconds to wait between retry attempts (500 ms).
	 *
	 * A short fixed delay is sufficient for transient network blips. Exponential
	 * backoff is a future enhancement; for now we keep this simple.
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

	/** @var IConnectionProvider Provides replica and primary DB connections. */
	private IConnectionProvider $dbProvider;

	/** @var HttpSigner Generates the HTTP Signature headers for each POST. */
	private HttpSigner $httpSigner;

	/** @var HttpRequestFactory Creates MWHttpRequest instances for HTTP POSTs. */
	private HttpRequestFactory $httpRequestFactory;

	/** @var Config MediaWiki main configuration, used for $wgActivityWikiDeliveryRetries. */
	private Config $config;

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	/**
	 * Construct a new DeliveryJob.
	 *
	 * All services are injected by the MediaWiki service container via the
	 * JobClasses + ObjectFactory wiring in extension.json. This follows the
	 * same constructor-injection pattern used throughout the modernised
	 * ActivityWiki codebase.
	 *
	 * @param array $params Job parameters. Must contain:
	 *   - 'activityId' (string): the unique ID of the activity row to deliver.
	 * @param IConnectionProvider $dbProvider Database connection provider.
	 * @param HttpSigner $httpSigner HTTP Signature service.
	 * @param HttpRequestFactory $httpRequestFactory HTTP client factory.
	 * @param Config $config MediaWiki main configuration object.
	 */
	public function __construct(
		array $params,
		IConnectionProvider $dbProvider,
		HttpSigner $httpSigner,
		HttpRequestFactory $httpRequestFactory,
		Config $config
	) {
		parent::__construct( 'MediawikiActivityPubDelivery', $params );

		$this->dbProvider         = $dbProvider;
		$this->httpSigner         = $httpSigner;
		$this->httpRequestFactory = $httpRequestFactory;
		$this->config             = $config;
	}

	// -------------------------------------------------------------------------
	// Job execution
	// -------------------------------------------------------------------------

	/**
	 * Execute the delivery job.
	 *
	 * Fetches the activity from the database, reads all follower inboxes, and
	 * POSTs the signed activity JSON to each one with retry logic.
	 *
	 * Always returns true — see class-level docblock for the rationale.
	 *
	 * @return bool Always true.
	 */
	public function run(): bool {
		// ------------------------------------------------------------------
		// Step 1 — Guard: require activityId in job params.
		// ------------------------------------------------------------------
		if ( !isset( $this->params['activityId'] ) ) {
			wfDebugLog( 'ActivityWiki', 'DeliveryJob: missing activityId parameter — aborting.' );
			return true;
		}

		$activityId = $this->params['activityId'];

		// ------------------------------------------------------------------
		// Step 2 — Fetch the activity record from the database.
		//
		// We read from a replica for the initial fetch. If the row is absent
		// (deleted between enqueue and execution) or its JSON is corrupt, we
		// log and return true — retrying would not help.
		// ------------------------------------------------------------------
		$dbReplica = $this->dbProvider->getReplicaDatabase();

		$row = $dbReplica->newSelectQueryBuilder()
			->select( [ 'activity_json', 'activity_type' ] )
			->from( 'activitywiki_activities' )
			->where( [ 'activity_id' => $activityId ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$row ) {
			wfDebugLog( 'ActivityWiki', "DeliveryJob: activity not found in DB: {$activityId}" );
			return true;
		}

		// Guard: json_decode() returns null on malformed input.
		$activityJson = $row->activity_json;
		if ( json_decode( $activityJson ) === null ) {
			wfDebugLog( 'ActivityWiki', "DeliveryJob: malformed JSON for activity {$activityId} — aborting." );
			return true;
		}

		// ------------------------------------------------------------------
		// Step 3 — Read the retry limit from configuration.
		//
		// $wgActivityWikiDeliveryRetries is the total number of *attempts*
		// (not extra retries), so a value of 3 means: try up to 3 times.
		// We ensure a minimum of 1 so the activity is always attempted at
		// least once, even if the config value is set to 0 or below.
		// ------------------------------------------------------------------
		$maxAttempts = max( 1, (int)$this->config->get( 'ActivityWikiDeliveryRetries' ) );

		// ------------------------------------------------------------------
		// Step 4 — Early signing sanity check.
		//
		// Attempt one test sign with an empty body to verify the private key
		// is present and valid before iterating over followers. If signing
		// fails here, it will fail for every follower — no point continuing.
		// ------------------------------------------------------------------
		try {
			// signRequest() throws RuntimeException if the key is missing
			// or if OpenSSL cannot load it. We catch that here so we can
			// abort the whole job with a clear critical-level log entry.
			$this->httpSigner->signRequest( 'https://example.com/probe', '' );
		} catch ( RuntimeException $e ) {
			wfDebugLog(
				'ActivityWiki',
				'DeliveryJob: CRITICAL — cannot sign requests, aborting delivery. '
				. 'Reason: ' . $e->getMessage()
			);
			return true;
		}

		// ------------------------------------------------------------------
		// Step 5 — Fetch all follower inbox URLs.
		//
		// activitywiki_followers stores one row per remote follower. The
		// `follower_inbox` column holds the full URL of the remote inbox
		// endpoint (e.g. "https://mastodon.social/users/alice/inbox").
		// ------------------------------------------------------------------
		$followerRows = $dbReplica->newSelectQueryBuilder()
			->select( [ 'af_inbox_url', 'af_actor_url' ] )
			->from( 'activitywiki_followers' )
			->caller( __METHOD__ )
			->fetchResultSet();

		// If there are no followers yet, mark as published and exit cleanly.
		if ( $followerRows->numRows() === 0 ) {
			wfDebugLog( 'ActivityWiki', "DeliveryJob: no followers — marking activity {$activityId} published." );
			$this->markPublished( $activityId );
			return true;
		}

		// ------------------------------------------------------------------
		// Step 6 — Fan-out: deliver to each follower inbox.
		//
		// For each follower we run an independent retry loop. A failure for
		// one inbox is logged and skipped; it does not affect the others.
		// ------------------------------------------------------------------
		$successCount = 0;
		$failureCount = 0;

		foreach ( $followerRows as $followerRow ) {
			$inboxUrl = $followerRow->af_inbox_url;
            $actorUrl = $followerRow->af_actor_url;
			$delivered   = false;
			$lastStatus  = null;
			$lastError   = null;

			for ( $attempt = 1; $attempt <= $maxAttempts; $attempt++ ) {
				// ------------------------------------------------------------
				// Sign the request fresh on every attempt.
				//
				// The HTTP Signature covers the Date header, which must match
				// the actual time of the POST. Re-using headers from a previous
				// attempt (even seconds earlier) risks the remote server
				// rejecting the signature as too old.
				// ------------------------------------------------------------
				try {
					$signedHeaders = $this->httpSigner->signRequest( $inboxUrl, $activityJson );
				} catch ( RuntimeException $e ) {
					// Key became unavailable mid-job — abort everything.
					wfDebugLog(
						'ActivityWiki',
						"DeliveryJob: CRITICAL — signing failed mid-delivery for activity {$activityId}. "
						. 'Reason: ' . $e->getMessage()
					);
					$this->markPublished( $activityId );
					return true;
				}

			// ------------------------------------------------------------
				// Build and send the HTTP POST via MWHttpRequest.
				//
				// MWHttpRequest is MediaWiki's HTTP client wrapper. It handles
				// timeouts, redirects, and TLS verification consistently across
				// environments. We set Content-Type as required by ActivityPub.
				//
				// CONFIRMED VIA LIVE TESTING (2026-06-20, during Phase 4 work):
				// the 'headers' key in this options array does NOT set request
				// headers. MWHttpRequest's and GuzzleHttpRequest's constructors
				// never read $options['headers'] at all — only a fixed allow-list
				// of other keys is processed. This means every header in
				// $signedHeaders (the Signature, Date, Digest, and Host headers
				// HttpSigner builds) and the Content-Type header below were
				// silently dropped on every outbound delivery since this job
				// went live in Phase 3. Fixed by calling setHeader() once per
				// header on the request object itself, below.
				//
				// SECOND BUG CONFIRMED VIA LIVE TESTING (2026-06-21, during
				// Phase 4 Accept-delivery debugging): the 'body' key in this
				// same options array is ALSO not read by MWHttpRequest's
				// constructor — the only allow-listed keys are 'postData',
				// 'proxy', 'noProxy', 'sslVerifyHost', 'caInfo', 'method',
				// 'followRedirects', 'maxRedirects', 'sslVerifyCert', 'callback'
				// (see MWHttpRequest::__construct(), $members array). 'body' is
				// not among them. This means every outbound delivery has ALSO
				// been sent with a completely empty POST body — compounding
				// with the header bug above. The receiving server's computed
				// Digest of the (empty) body it actually received would never
				// match our claimed Digest header, causing rejection even once
				// the headers themselves were correctly attached. Fixed by
				// using 'postData' instead of 'body' below. See
				// ActivityWiki-plan.md for the full writeup.
				// ------------------------------------------------------------
				$request = $this->httpRequestFactory->create(
					$inboxUrl,
					[
						'method'   => 'POST',
						'postData' => $activityJson,
					],
					__METHOD__
				);

				foreach ( $signedHeaders as $headerName => $headerValue ) {
					$request->setHeader( $headerName, $headerValue );
				}
				$request->setHeader( 'Content-Type', self::CONTENT_TYPE );

				$status = $request->execute();

				// ------------------------------------------------------------
				// Evaluate the response.
				//
				// ActivityPub servers signal acceptance with any 2xx status.
				// Mastodon typically returns 202 Accepted. We treat any HTTP
				// code in the 200–299 range as success.
				// ------------------------------------------------------------
				$httpCode = $request->getStatus();

				if ( $status->isOK() && $httpCode >= 200 && $httpCode < 300 ) {
					// Success — no need to retry this follower.
					$delivered  = true;
					$lastStatus = $httpCode;
					break;
				}

				// Record the failure details for logging after the loop.
				$lastStatus = $httpCode;
				$lastError  = $status->getMessage()->text();

				// If there are more attempts remaining, wait before retrying.
				if ( $attempt < $maxAttempts ) {
					usleep( self::RETRY_DELAY_US );
				}
			}

			// ----------------------------------------------------------------
			// Log the outcome for this follower.
			// ----------------------------------------------------------------
			if ( $delivered ) {
				$successCount++;
				wfDebugLog(
					'ActivityWiki',
					"DeliveryJob: delivered activity {$activityId} to {$inboxUrl} "
					. "(actor: {$actorUrl}, HTTP {$lastStatus})."
				);
			} else {
				$failureCount++;
				wfDebugLog(
					'ActivityWiki',
					"DeliveryJob: FAILED to deliver activity {$activityId} to {$inboxUrl} "
					. "after {$maxAttempts} attempt(s). "
					. "HTTP status: {$lastStatus}. Error: {$lastError}"
				);
			}
		}

		// ------------------------------------------------------------------
		// Step 7 — Log the overall delivery summary.
		// ------------------------------------------------------------------
		wfDebugLog(
			'ActivityWiki',
			"DeliveryJob: activity {$activityId} delivery complete — "
			. "{$successCount} succeeded, {$failureCount} failed."
		);

		// ------------------------------------------------------------------
		// Step 8 — Mark the activity as published.
		//
		// We mark published regardless of partial failures. An activity that
		// reached some followers is not "unpublished". Failed deliveries are
		// surfaced via the debug log and will be handled by a future retry
		// mechanism in Phase 5 (Administration).
		// ------------------------------------------------------------------
		$this->markPublished( $activityId );

		return true;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Mark an activity row as published in the database.
	 *
	 * Extracted to a private method so both the normal completion path and the
	 * early-abort paths (no followers, key failure mid-run) can call it without
	 * duplicating the query builder call.
	 *
	 * Uses the primary database connection because this is a write operation.
	 *
	 * @param string $activityId The unique ID of the activity to mark.
	 */
	private function markPublished( string $activityId ): void {
		$dbPrimary = $this->dbProvider->getPrimaryDatabase();

		$dbPrimary->newUpdateQueryBuilder()
			->update( 'activitywiki_activities' )
			->set( [ 'published' => 1 ] )
			->where( [ 'activity_id' => $activityId ] )
			->caller( __METHOD__ )
			->execute();
	}
}