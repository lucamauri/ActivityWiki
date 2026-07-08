<?php

declare( strict_types=1 );

/**
 * ActivityWiki — FollowManager
 *
 * Owns the business logic for incoming Follow and Undo{Follow} activities.
 * Called by InboxHandler once SignatureVerifier has confirmed a request is
 * authentic — this class never touches HTTP request/response concerns
 * itself, matching the "thin handler, fat service" pattern already
 * established by ActivityPubModule (used by ActorHandler).
 *
 * Responsibilities:
 *   - handleFollow(): record a new follower in activitywiki_followers, then
 *     build an Accept activity and enqueue it for delivery via AcceptJob.
 *   - handleUndo(): remove the matching follower row. No reply is sent —
 *     Undo{Follow} has no corresponding response activity in the spec.
 *
 * Fetching the follower's inbox URL requires an outbound GET to their actor
 * document — the same kind of fetch SignatureVerifier performs to obtain a
 * public key, but for a different field (`inbox`). That shared HTTP/JSON
 * mechanics now lives in ActorFetcher (Phase 5, Task 2); this class only
 * extracts the fields it specifically needs (`inbox`, `endpoints.sharedInbox`)
 * from whatever document ActorFetcher returns. See ActorFetcher.php's class
 * docblock for the full history of that consolidation.
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\ActivityWiki;

use JobQueueGroup;
use JobSpecification;
use Wikimedia\Rdbms\IConnectionProvider;

class FollowManager {

	/** @var IConnectionProvider Provides replica and primary DB connections. */
	private IConnectionProvider $dbProvider;

	/** @var JobQueueGroup Used to enqueue AcceptJob after recording a follower. */
	private JobQueueGroup $jobQueueGroup;

	/**
	 * @var ActorFetcher Fetches and decodes a follower's actor document, from
	 *   which this class extracts `inbox` and `endpoints.sharedInbox`.
	 *   Previously this class held HttpRequestFactory directly and performed
	 *   the HTTP GET itself in a private fetchActorDocument() method; that
	 *   mechanics moved into ActorFetcher in Phase 5, Task 2, since
	 *   SignatureVerifier needed the identical fetch for a different field
	 *   (publicKey.publicKeyPem). See ActorFetcher.php's class docblock for
	 *   the full history.
	 */
	private ActorFetcher $actorFetcher;

	/**
	 * @var WikiActorUrls Provides this wiki's own base URL and actor URL.
	 *   Used to build the Accept activity's "id" and "actor" fields — this
	 *   use is unrelated to fetching a remote actor document, so it stayed
	 *   here rather than moving into ActorFetcher. Previously this class
	 *   read $wgServer/$wgScriptPath directly via a Config object and built
	 *   those URLs itself in two private methods (getWikiUrl()/
	 *   getWikiActorUrl()), duplicated identically in ActivityBuilder,
	 *   SignatureVerifier, and HttpSigner. Consolidated into WikiActorUrls
	 *   — see that class's docblock for the full history.
	 */
	private WikiActorUrls $wikiActorUrls;

	/**
	 * @param IConnectionProvider $dbProvider Database connection provider.
	 * @param JobQueueGroup $jobQueueGroup Job queue group for enqueuing AcceptJob.
	 * @param ActorFetcher $actorFetcher Fetches and decodes a remote actor
	 *   document over HTTP.
	 * @param WikiActorUrls $wikiActorUrls Provides this wiki's own base URL
	 *   and actor URL.
	 */
	public function __construct(
		IConnectionProvider $dbProvider,
		JobQueueGroup $jobQueueGroup,
		ActorFetcher $actorFetcher,
		WikiActorUrls $wikiActorUrls
	) {
		$this->dbProvider    = $dbProvider;
		$this->jobQueueGroup = $jobQueueGroup;
		$this->actorFetcher  = $actorFetcher;
		$this->wikiActorUrls = $wikiActorUrls;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Handle an incoming Follow activity.
	 *
	 * Records the follower in activitywiki_followers (or silently does
	 * nothing if they are already recorded — see the duplicate-follow
	 * handling below), fetches their inbox URL, builds an Accept activity,
	 * and enqueues an AcceptJob to deliver it.
	 *
	 * @param array $activity The decoded Follow activity. Must contain an
	 *   'actor' field (string, the follower's actor URL). The 'id' field,
	 *   if present, is echoed back inside the Accept's 'object' field, as
	 *   required by the spec.
	 * @return void
	 */
	public function handleFollow( array $activity ): void {
		$actorUrl = $activity['actor'] ?? null;

		if ( !is_string( $actorUrl ) || $actorUrl === '' ) {
			wfDebugLog( 'ActivityWiki', 'FollowManager: Follow activity missing actor field — ignoring.' );
			return;
		}

		// ------------------------------------------------------------------
		// Step 1 — Fetch the follower's actor document to learn their inbox.
		//
		// The incoming Follow only tells us *who* is following (the actor
		// URL) — it does not include their inbox URL, which we need in
		// order to ever deliver anything to them (including this Accept,
		// and every future outbound activity via DeliveryJob). The fetch
		// itself is delegated to ActorFetcher; we only extract the fields
		// this class specifically needs from the result.
		// ------------------------------------------------------------------
		$followerActor = $this->actorFetcher->fetchActorDocument( $actorUrl );

		if ( $followerActor === null ) {
			wfDebugLog(
				'ActivityWiki',
				"FollowManager: could not fetch actor document for {$actorUrl} — cannot record follower."
			);
			return;
		}

		$inboxUrl = $followerActor['inbox'] ?? null;

		if ( !is_string( $inboxUrl ) || $inboxUrl === '' ) {
			wfDebugLog(
				'ActivityWiki',
				"FollowManager: actor document for {$actorUrl} has no inbox field — cannot record follower."
			);
			return;
		}

		// sharedInbox is optional — not every Fediverse server exposes one.
		// Stored now for the future delivery optimisation noted on the
		// af_shared_inbox_url column; not used by AcceptJob or DeliveryJob yet.
		$sharedInboxUrl = $followerActor['endpoints']['sharedInbox'] ?? null;
		if ( !is_string( $sharedInboxUrl ) || $sharedInboxUrl === '' ) {
			$sharedInboxUrl = null;
		}

		// ------------------------------------------------------------------
		// Step 2 — Record the follower.
		//
		// af_actor_url has a UNIQUE index, so a duplicate Follow from an
		// actor who already follows us would normally violate that
		// constraint. Mastodon and other servers do occasionally re-send a
		// Follow (e.g. after a server migration) for an existing follow
		// relationship, so we treat this as a benign, expected case rather
		// than an error: upsert-style behaviour via insertOnDuplicate-like
		// logic, implemented here as an explicit pre-check rather than
		// relying on a DB-level "INSERT ... ON DUPLICATE KEY UPDATE",
		// keeping the intent obvious in PHP rather than buried in SQL.
		// ------------------------------------------------------------------
		$dbPrimary = $this->dbProvider->getPrimaryDatabase();

		$existing = $dbPrimary->newSelectQueryBuilder()
			->select( [ 'af_id' ] )
			->from( 'activitywiki_followers' )
			->where( [ 'af_actor_url' => $actorUrl ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $existing ) {
			wfDebugLog(
				'ActivityWiki',
				"FollowManager: {$actorUrl} already follows — re-sending Accept without duplicate insert."
			);
		} else {
			$dbPrimary->newInsertQueryBuilder()
				->insertInto( 'activitywiki_followers' )
				->row( [
					'af_actor_url'        => $actorUrl,
					'af_inbox_url'        => $inboxUrl,
					'af_shared_inbox_url'  => $sharedInboxUrl,
					'af_followed_at'       => wfTimestampNow(),
				] )
				->caller( __METHOD__ )
				->execute();

			wfDebugLog( 'ActivityWiki', "FollowManager: recorded new follower {$actorUrl}." );
		}

		// ------------------------------------------------------------------
		// Step 3 — Build the Accept activity and enqueue delivery.
		//
		// Per the ActivityPub spec, Accept{Follow} must echo the original
		// Follow activity back inside the 'object' field — either the full
		// activity or, commonly, just its 'id'. We use the full original
		// activity array we received, which satisfies servers that expect
		// either form.
		// ------------------------------------------------------------------
		$acceptActivity = [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id'       => $this->wikiActorUrls->getWikiUrl() . 'activitywiki/activities/accept-' . bin2hex( random_bytes( 8 ) ),
			'type'     => 'Accept',
			'actor'    => $this->wikiActorUrls->getWikiActorUrl(),
			'object'   => $activity,
		];

		$acceptJson = json_encode( $acceptActivity );

		if ( $acceptJson === false ) {
			wfDebugLog(
				'ActivityWiki',
				"FollowManager: failed to encode Accept activity for {$actorUrl} — Accept not sent."
			);
			return;
		}

		$job = new JobSpecification(
			'MediawikiActivityPubAccept',
			[
				'inboxUrl'     => $inboxUrl,
				'activityJson' => $acceptJson,
			]
		);
		$this->jobQueueGroup->push( $job );

		wfDebugLog( 'ActivityWiki', "FollowManager: queued Accept for {$actorUrl} via {$inboxUrl}." );
	}

	/**
	 * Handle an incoming Undo{Follow} activity.
	 *
	 * Removes the matching row from activitywiki_followers, identified by
	 * the actor URL inside the wrapped Follow object. No reply is sent —
	 * Undo has no corresponding response activity in the ActivityPub spec.
	 *
	 * @param array $activity The decoded Undo activity. Must contain an
	 *   'object' field which is itself a Follow activity (object.actor is
	 *   the follower's actor URL — the same actor who sent the Undo).
	 * @return void
	 */
	public function handleUndo( array $activity ): void {
		$wrappedObject = $activity['object'] ?? null;

		// The wrapped object is normally the full Follow activity array.
		// Some servers send only its 'id' string instead — we cannot
		// resolve an actor URL from a bare ID without an extra fetch, and
		// since unfollowing is not security-sensitive (worst case, a stale
		// follower keeps receiving deliveries a little longer until they
		// retry), we simply log and decline to guess rather than fetching.
		if ( !is_array( $wrappedObject ) ) {
			wfDebugLog(
				'ActivityWiki',
				'FollowManager: Undo activity object is not a full Follow activity — cannot resolve actor, ignoring.'
			);
			return;
		}

		// The actor being unfollowed-from is irrelevant here (it's always
		// us); the actor who is unfollowing is the 'actor' field of the
		// wrapped Follow — i.e. the same actor who is sending this Undo.
		$actorUrl = $wrappedObject['actor'] ?? null;

		if ( !is_string( $actorUrl ) || $actorUrl === '' ) {
			wfDebugLog( 'ActivityWiki', 'FollowManager: Undo activity missing actor field — ignoring.' );
			return;
		}

		$dbPrimary = $this->dbProvider->getPrimaryDatabase();

		$dbPrimary->newDeleteQueryBuilder()
			->deleteFrom( 'activitywiki_followers' )
			->where( [ 'af_actor_url' => $actorUrl ] )
			->caller( __METHOD__ )
			->execute();

		// affectedRows() tells us whether a row actually matched — useful
		// to distinguish "removed a real follower" from "Undo for someone
		// who was never recorded" in the log, without treating the latter
		// as an error (it is a harmless no-op either way).
		if ( $dbPrimary->affectedRows() > 0 ) {
			wfDebugLog( 'ActivityWiki', "FollowManager: removed follower {$actorUrl}." );
		} else {
			wfDebugLog(
				'ActivityWiki',
				"FollowManager: Undo received for {$actorUrl}, who was not a recorded follower — no-op."
			);
		}
	}
}