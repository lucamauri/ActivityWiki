<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\ActivityWiki;

use JobQueueGroup;
use MediaWiki\Config\Config;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * ActivityWikiStatusReporter — gathers every fact shown on
 * Special:ActivityWiki (Phase 5, Task 5.1 of the project plan).
 *
 * Matches the "thin handler, fat service" pattern already established by
 * ActivityPubModule (used by ActorHandler) and FollowManager (used by
 * InboxHandler): SpecialActivityWiki itself only renders HTML — every
 * question it needs answered ("is federation on?", "how many followers?",
 * "is anything stuck in the job queue?") is answered here, by a class with
 * no knowledge of HTML at all. This also makes each fact independently
 * testable without touching the SpecialPage machinery.
 *
 * Deliberately NOT a registered MediaWiki service (unlike KeyManager,
 * ActorFetcher, etc.) — it is manually instantiated by SpecialActivityWiki
 * from services that class already receives via extension.json's
 * "SpecialPages" entry, the same pattern Hooks::onPageSaveComplete() uses
 * for ActivityBuilder and DeliveryQueue. A plain status-gathering class
 * with no further collaborators of its own doesn't need the indirection of
 * the service container.
 *
 * @since 1.2.0 (Phase 5 — Administration)
 */
class ActivityWikiStatusReporter {

	/**
	 * The two job types registered in extension.json's "JobClasses" that
	 * carry ActivityWiki federation traffic. Kept as a single list here so
	 * that a third job type added in a future phase only needs updating in
	 * one place.
	 */
	private const JOB_TYPES = [
		'MediawikiActivityPubDelivery',
		'MediawikiActivityPubAccept',
	];

	/**
	 * How many rows getRecentActivity() returns by default. 20 is enough to
	 * span a normal editing session without the page becoming unwieldy;
	 * callers needing more can pass an explicit $limit.
	 */
	private const DEFAULT_RECENT_ACTIVITY_LIMIT = 20;

	private Config $config;
	private IConnectionProvider $dbProvider;
	private JobQueueGroup $jobQueueGroup;
	private KeyManager $keyManager;
	private WebFingerStatusChecker $webFingerStatusChecker;

	/**
	 * @param Config $config Main wiki config, read for $wgActivityWikiEnabled.
	 * @param IConnectionProvider $dbProvider Database connection provider,
	 *   for follower counts and the recent-activity log.
	 * @param JobQueueGroup $jobQueueGroup For reading pending-job counts —
	 *   read-only here; this class never pushes jobs.
	 * @param KeyManager $keyManager For the public key fingerprint.
	 * @param WebFingerStatusChecker $webFingerStatusChecker For the live
	 *   WebFinger reachability check.
	 */
	public function __construct(
		Config $config,
		IConnectionProvider $dbProvider,
		JobQueueGroup $jobQueueGroup,
		KeyManager $keyManager,
		WebFingerStatusChecker $webFingerStatusChecker
	) {
		$this->config                 = $config;
		$this->dbProvider             = $dbProvider;
		$this->jobQueueGroup          = $jobQueueGroup;
		$this->keyManager             = $keyManager;
		$this->webFingerStatusChecker = $webFingerStatusChecker;
	}

	/**
	 * Whether federation is currently switched on.
	 *
	 * Mirrors exactly the check Hooks::onPageSaveComplete() performs first
	 * — if this is false, nothing else on this page is actively happening,
	 * which is worth making obvious to the operator before they start
	 * reading follower counts and queue depths that may be stale.
	 *
	 * @return bool
	 */
	public function isFederationEnabled(): bool {
		return (bool)$this->config->get( 'ActivityWikiEnabled' );
	}

	/**
	 * The number of remote actors currently following this wiki.
	 *
	 * Uses a replica connection — an exact-to-the-second count is not
	 * needed for a status display, and the replica is the cheaper read
	 * path, matching the convention KeyManager::fetchColumn() already
	 * follows for similarly non-critical reads.
	 *
	 * @return int
	 */
	public function getFollowerCount(): int {
		$dbr = $this->dbProvider->getReplicaDatabase();

		$count = $dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'activitywiki_followers' )
			->caller( __METHOD__ )
			->fetchField();

		// fetchField() returns a numeric string (or false if the query
		// somehow returned no row, which COUNT(*) never does) — cast
		// explicitly rather than trusting the DB driver's PHP type.
		return (int)$count;
	}

	/**
	 * Pending-job counts for each ActivityWiki job type.
	 *
	 * "Pending" here means the sum of two distinct JobQueue states:
	 *   - ready:   jobs sitting in the queue, not yet claimed by a runner
	 *              (JobQueue::getSize()).
	 *   - delayed: jobs scheduled for a later retry after a failed delivery
	 *              attempt (JobQueue::getDelayedCount()) — relevant here
	 *              because $wgActivityWikiDeliveryRetries means a failed
	 *              delivery doesn't disappear, it reschedules.
	 * Both are reported separately, not just summed, so an operator can
	 * tell "a backlog is building up" (ready climbing) apart from "lots of
	 * followers are currently unreachable and we're backing off retries"
	 * (delayed climbing) — the two point at different problems.
	 *
	 * Deliberately omits JobQueue::getAcquiredCount() (jobs currently being
	 * run right now) — on a status page refreshed by a human, that number
	 * is almost always 0 or 1 and adds noise rather than signal; it is also
	 * expected to fluctuate within the ~1 minute cache window the JobQueue
	 * class itself documents for these counts, so treat every number here
	 * as "approximately current", not live-to-the-second.
	 *
	 * @return array<string,array{ready:int,delayed:int,pending:int}> Keyed
	 *   by job type name (e.g. "MediawikiActivityPubDelivery").
	 */
	public function getJobQueueDepths(): array {
		$depths = [];

		foreach ( self::JOB_TYPES as $jobType ) {
			$queue  = $this->jobQueueGroup->get( $jobType );
			$ready  = $queue->getSize();
			$delayed = $queue->getDelayedCount();

			$depths[ $jobType ] = [
				'ready'   => $ready,
				'delayed' => $delayed,
				'pending' => $ready + $delayed,
			];
		}

		return $depths;
	}

	/**
	 * The most recent federated activities, newest first.
	 *
	 * Reads activitywiki_activities directly rather than going through
	 * OutboxHandler/ActivityPubModule::buildOutboxObject() — those exist to
	 * produce a public, spec-shaped ActivityPub collection for Fediverse
	 * clients; this is an internal operator view and returns plain row
	 * data, letting the caller (SpecialActivityWiki) decide how to render
	 * each field (e.g. resolving user_id to a display name, formatting
	 * created_at for the wiki's local timezone).
	 *
	 * Note on the "published" column (see ActivityWiki-plan.md's audit
	 * notes): despite its name, this is a 0/1 delivery-status flag, NOT a
	 * timestamp. It is returned here as a plain bool so callers cannot
	 * accidentally treat it as a date.
	 *
	 * @param int $limit Maximum rows to return. Defaults to
	 *   self::DEFAULT_RECENT_ACTIVITY_LIMIT.
	 * @return list<array{
	 *   activityType: string,
	 *   objectType: string,
	 *   pageTitle: ?string,
	 *   userId: ?int,
	 *   createdAt: string,
	 *   delivered: bool
	 * }>
	 */
	public function getRecentActivity( int $limit = self::DEFAULT_RECENT_ACTIVITY_LIMIT ): array {
		$dbr = $this->dbProvider->getReplicaDatabase();

		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'activity_type', 'object_type', 'page_title', 'user_id', 'created_at', 'published' ] )
			->from( 'activitywiki_activities' )
			->orderBy( 'created_at', 'DESC' )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet();

		$activity = [];
		foreach ( $rows as $row ) {
			$activity[] = [
				'activityType' => $row->activity_type,
				'objectType'   => $row->object_type,
				'pageTitle'    => $row->page_title,
				'userId'       => $row->user_id !== null ? (int)$row->user_id : null,
				'createdAt'    => $row->created_at,
				// Cast explicitly — the "boolean" schema type in
				// db/activitywiki_activities.json is stored as a plain
				// tinyint by the underlying DB driver and comes back as a
				// numeric string ("0"/"1"), not a native PHP bool.
				'delivered'    => (bool)$row->published,
			];
		}

		return $activity;
	}

	/**
	 * A display-friendly SHA-256 fingerprint of the wiki's current RSA
	 * public key, or null if no key pair has been generated yet (e.g. a
	 * fresh install before update.php has run — see KeyManager's docblock
	 * on generateAndStoreKeyPairIfAbsent()).
	 *
	 * Matches the plan's "so operators can verify the key is correct"
	 * purpose (ActivityWiki-plan.md, Task 5.1): the fingerprint is computed
	 * the same way a SHA-256 SSH/TLS key fingerprint is — hash of the raw
	 * DER bytes, not the PEM text — so an operator can independently verify
	 * it against the same key file with, e.g.:
	 *
	 *   openssl rsa -pubin -in actor_public_key.pem -outform DER | sha256sum
	 *
	 * @return string|null Colon-separated uppercase hex, e.g.
	 *   "AB:12:...:CD", or null if no key exists yet.
	 */
	public function getPublicKeyFingerprint(): ?string {
		$pem = $this->keyManager->getPublicKeyPem();

		if ( $pem === null || $pem === '' ) {
			return null;
		}

		return $this->fingerprintFromPem( $pem );
	}

	/**
	 * Runs the live WebFinger reachability probe.
	 *
	 * Thin pass-through — kept as a method on this class (rather than
	 * having SpecialActivityWiki call WebFingerStatusChecker directly) so
	 * that everything the status page needs comes from exactly one
	 * collaborator, matching how the page reads every other fact through
	 * this reporter.
	 *
	 * @return WebFingerCheckResult
	 */
	public function getWebFingerCheck(): WebFingerCheckResult {
		return $this->webFingerStatusChecker->check();
	}

	/**
	 * Converts a PEM-encoded public key into a colon-separated, uppercase
	 * SHA-256 fingerprint of its underlying DER bytes.
	 *
	 * @param string $pem A full "-----BEGIN PUBLIC KEY-----...-----END
	 *   PUBLIC KEY-----" PEM block, as stored by KeyManager.
	 * @return string e.g. "A1:B2:C3:...:FF"
	 */
	private function fingerprintFromPem( string $pem ): string {
		// Strip the PEM header/footer lines and all whitespace/newlines,
		// leaving only the base64 body, then decode it back to the raw DER
		// bytes the fingerprint is actually computed over — fingerprinting
		// the PEM *text* (including headers) would not match the value an
		// operator computes independently with the openssl one-liner above.
		$base64 = preg_replace( '/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $pem );
		$der    = base64_decode( (string)$base64, true );

		// base64_decode() with strict mode returns false on malformed
		// input. This should never happen for a PEM we generated and
		// stored ourselves, but a corrupted DB row is not impossible —
		// fail loudly with an obviously-not-a-real-fingerprint string
		// rather than silently hashing an empty value.
		if ( $der === false ) {
			wfDebugLog( 'ActivityWiki', 'ActivityWikiStatusReporter: stored public key PEM is not valid base64.' );
			return '(invalid stored key — see debug log)';
		}

		$hash = hash( 'sha256', $der );

		// Insert a ":" between every pair of hex characters and uppercase
		// the result — the conventional display format for key
		// fingerprints (matches `openssl ... | sha256sum`-style tooling
		// once that output is similarly reformatted, and is the format
		// most operators will recognise from SSH/TLS contexts).
		$pairs = str_split( $hash, 2 );
		return strtoupper( implode( ':', $pairs ) );
	}
}