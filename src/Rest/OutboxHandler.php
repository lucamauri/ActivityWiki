<?php
/**
 * OutboxHandler — REST handler for the ActivityPub outbox endpoint.
 *
 * Serves the wiki's ActivityPub outbox at:
 *   GET /activitywiki/outbox
 *
 * The outbox is an OrderedCollection listing activities published by this
 * actor (page creations, edits, deletions). Data is read live from the
 * activitywiki_activities table, which is written by DeliveryQueue whenever
 * a qualifying page event occurs.
 *
 * Design note — DB read ownership:
 *   The DB query lives here in the handler, not in ActivityPubModule.
 *   ActivityPubModule is a formatter/assembler: callers fetch their own
 *   data and pass it in. This is the same pattern already established by
 *   FollowersHandler / buildFollowersObject().
 *
 * Design note — pagination (deliberate simplification, Phase 5 Task 3):
 *   This returns the $wgActivityWikiOutboxLimit most recent activities in a
 *   single response, with an accurate totalItems count, but no
 *   OrderedCollectionPage cursor pagination (no first/next/prev links).
 *   Most consuming Fediverse software only reads the outbox for a total
 *   count or a handful of recent items, so this is a reasonable initial
 *   implementation. Full spec-compliant paging is a tracked fast-follow —
 *   see ActivityWiki-plan.md — not an oversight.
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
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Handles GET /activitywiki/outbox
 *
 * Reads the most recent activities from activitywiki_activities and an
 * accurate total row count, then returns a spec-compliant ActivityPub
 * OrderedCollection.
 */
class OutboxHandler extends SimpleHandler {

	/**
	 * The ActivityPub object builder. Used here only for buildOutboxObject()
	 * and the URL helpers it calls internally (getOutboxUrl(), etc.).
	 * KeyManager is passed through purely to satisfy ActivityPubModule's
	 * constructor — this handler never calls any key-related method directly.
	 *
	 * @var ActivityPubModule
	 */
	private ActivityPubModule $module;

	/**
	 * Database connection provider. Used to read activitywiki_activities on
	 * every request — we always serve live data, never a cached count.
	 *
	 * @var IConnectionProvider
	 */
	private IConnectionProvider $dbProvider;

	/**
	 * MediaWiki main configuration. Used to read
	 * $wgActivityWikiOutboxLimit, the maximum number of recent activities
	 * to include in a single response.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * @param Config $config Injected by MediaWiki from routes.json "services".
	 *   Also used directly here (not just passed through to
	 *   ActivityPubModule) to read $wgActivityWikiOutboxLimit.
	 * @param KeyManager $keyManager Injected by MediaWiki from routes.json
	 *   "services". Passed through to ActivityPubModule — this handler
	 *   never calls key methods directly.
	 * @param IConnectionProvider $dbProvider Injected by MediaWiki from
	 *   routes.json "services" ("DBLoadBalancerFactory"). Provides the
	 *   replica connection used to read activity rows.
	 */
	public function __construct(
		Config $config,
		KeyManager $keyManager,
		IConnectionProvider $dbProvider
	) {
		$this->module     = new ActivityPubModule( $config, $keyManager );
		$this->dbProvider = $dbProvider;
		$this->config     = $config;
	}

	/**
	 * Handles the GET request and returns the outbox collection.
	 *
	 * Reads the $wgActivityWikiOutboxLimit most recent rows from
	 * activitywiki_activities (ordered by created_at DESC) via a replica
	 * connection, decodes each row's activity_json, and passes the
	 * resulting array — plus a separate, accurate total row count — to
	 * ActivityPubModule::buildOutboxObject() for JSON assembly.
	 *
	 * We use a replica (read-only) connection because:
	 *   - This is a read path with no side effects.
	 *   - The outbox may be fetched frequently by remote servers refreshing
	 *     their cached copy of our actor's published activities.
	 *   - A brief replica lag (a just-published activity not yet visible)
	 *     is acceptable — remote servers will re-fetch eventually, and the
	 *     activity was already delivered directly to followers regardless
	 *     of what the outbox shows.
	 *
	 * @return \MediaWiki\Rest\Response
	 */
	public function run(): \MediaWiki\Rest\Response {
		$dbReplica = $this->dbProvider->getReplicaDatabase();

		// ------------------------------------------------------------------
		// Step 1 — Get the true total row count.
		//
		// This is deliberately a separate query from the row fetch below,
		// rather than relying on count( $activities ), since $activities is
		// limited to $wgActivityWikiOutboxLimit rows — totalItems must
		// reflect every activity ever published, not just the ones included
		// in this response.
		// ------------------------------------------------------------------
		$totalItems = (int)$dbReplica->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'activitywiki_activities' )
			->caller( __METHOD__ )
			->fetchField();

		// ------------------------------------------------------------------
		// Step 2 — Read the most recent activities, newest first.
		//
		// created_at (not the "published" column, which is really a 0/1
		// delivery-status flag rather than a timestamp — see DeliveryJob.php)
		// is the correct column to sort by. The limit is read from config so
		// the wiki operator can tune it without a code change.
		// ------------------------------------------------------------------
		$limit = max( 1, (int)$this->config->get( 'ActivityWikiOutboxLimit' ) );

		$rows = $dbReplica->newSelectQueryBuilder()
			->select( [ 'activity_json' ] )
			->from( 'activitywiki_activities' )
			->orderBy( 'created_at', 'DESC' )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet();

		// ------------------------------------------------------------------
		// Step 3 — Decode each row's activity_json into an array.
		//
		// DeliveryQueue::queueActivity() already guards against inserting
		// invalid JSON (it checks json_encode()'s return value before the
		// insert), so a malformed row here should not happen in practice.
		// We guard anyway — skipping and logging a bad row is safer than
		// letting one corrupt row break the entire outbox response.
		// ------------------------------------------------------------------
		$activities = [];
		foreach ( $rows as $row ) {
			$decoded = json_decode( $row->activity_json, true );

			if ( !is_array( $decoded ) ) {
				wfDebugLog(
					'ActivityWiki',
					'OutboxHandler: skipping a row with malformed activity_json.'
				);
				continue;
			}

			$activities[] = $decoded;
		}

		wfDebugLog(
			'ActivityWiki',
			'OutboxHandler: serving outbox collection — '
			. count( $activities ) . ' of ' . $totalItems . ' total activit(y/ies).'
		);

		// ------------------------------------------------------------------
		// Step 4 — Build and return the JSON response.
		// ------------------------------------------------------------------
		$json = json_encode(
			$this->module->buildOutboxObject( $activities, $totalItems ),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		if ( $json === false ) {
			return $this->getResponseFactory()->createHttpError( 500, [
				'message' => 'Failed to encode outbox object as JSON.',
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
	 * This endpoint takes no path or query parameters.
	 *
	 * Cursor pagination parameters (page, max_id) are deferred along with
	 * full OrderedCollectionPage support — see the class-level docblock.
	 * They would be added here and in buildOutboxObject() together.
	 *
	 * @return array
	 */
	public function getParamSettings(): array {
		return [];
	}
}