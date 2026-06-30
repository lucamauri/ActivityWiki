<?php
/**
 * FollowersHandler — REST handler for the ActivityPub followers endpoint.
 *
 * Serves the wiki's ActivityPub followers collection at:
 *   GET /activitywiki/followers
 *
 * The followers collection is an OrderedCollection listing the actor URLs
 * of every remote actor currently following this wiki. The data is read
 * live from the activitywiki_followers table, which is written by
 * FollowManager whenever a Follow or Undo{Follow} activity arrives at
 * the inbox.
 *
 * Design note — DB read ownership:
 *   The DB query lives here in the handler, not in ActivityPubModule.
 *   ActivityPubModule is a formatter/assembler: callers fetch their own
 *   data and pass it in. This keeps ActivityPubModule free of DB
 *   dependencies, and establishes the pattern that buildOutboxObject()
 *   will follow when the outbox gets real data in Phase 3.
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
 * Handles GET /activitywiki/followers
 *
 * Reads all follower actor URLs from activitywiki_followers and returns
 * a spec-compliant ActivityPub OrderedCollection.
 */
class FollowersHandler extends SimpleHandler {

	/**
	 * The ActivityPub object builder. Used here only for buildFollowersObject()
	 * and the URL helpers it calls internally (getFollowersUrl(), etc.).
	 * KeyManager is passed through purely to satisfy ActivityPubModule's
	 * constructor — this handler never calls any key-related method directly.
	 *
	 * @var ActivityPubModule
	 */
	private ActivityPubModule $module;

	/**
	 * Database connection provider. Used to read activitywiki_followers on
	 * every request — we always serve live data, never a cached count.
	 *
	 * @var IConnectionProvider
	 */
	private IConnectionProvider $dbProvider;

	/**
	 * @param Config $config Injected by MediaWiki from routes.json "services".
	 * @param KeyManager $keyManager Injected by MediaWiki from routes.json "services".
	 *   Passed through to ActivityPubModule — this handler never calls key
	 *   methods directly.
	 * @param IConnectionProvider $dbProvider Injected by MediaWiki from
	 *   routes.json "services" ("DBLoadBalancerFactory"). Provides the replica
	 *   connection used to read follower rows.
	 */
	public function __construct(
		Config $config,
		KeyManager $keyManager,
		IConnectionProvider $dbProvider
	) {
		$this->module     = new ActivityPubModule( $config, $keyManager );
		$this->dbProvider = $dbProvider;
	}

	/**
	 * Handles the GET request and returns the followers collection.
	 *
	 * Reads all rows from activitywiki_followers via a replica connection,
	 * extracts the af_actor_url column, and passes the resulting array to
	 * ActivityPubModule::buildFollowersObject() for JSON assembly.
	 *
	 * We use a replica (read-only) connection because:
	 *   - This is a read path with no side effects.
	 *   - The followers collection may be fetched frequently by remote
	 *     servers refreshing their cached copy of our actor document.
	 *   - A brief replica lag (a follower just added not yet visible) is
	 *     acceptable — remote servers will re-fetch eventually.
	 *
	 * @return \MediaWiki\Rest\Response
	 */
	public function run(): \MediaWiki\Rest\Response {
		// ------------------------------------------------------------------
		// Step 1 — Read all follower actor URLs from the database.
		//
		// We only need af_actor_url for the orderedItems list. The spec
		// does not require (or recommend) including inbox URLs or timestamps
		// in the followers collection — only the actor URL of each follower.
		// ------------------------------------------------------------------
		$dbReplica = $this->dbProvider->getReplicaDatabase();

		$rows = $dbReplica->newSelectQueryBuilder()
			->select( [ 'af_actor_url' ] )
			->from( 'activitywiki_followers' )
			->orderBy( 'af_followed_at', 'ASC' )
			->caller( __METHOD__ )
			->fetchResultSet();

		// ------------------------------------------------------------------
		// Step 2 — Extract actor URLs into a plain string array.
		//
		// ActivityPubModule::buildFollowersObject() expects a flat array of
		// URL strings — the simplest possible input for a simple formatter.
		// ------------------------------------------------------------------
		$actorUrls = [];
		foreach ( $rows as $row ) {
			$actorUrls[] = $row->af_actor_url;
		}

		wfDebugLog(
			'ActivityWiki',
			'FollowersHandler: serving followers collection — '
			. count( $actorUrls ) . ' follower(s).'
		);

		// ------------------------------------------------------------------
		// Step 3 — Build and return the JSON response.
		// ------------------------------------------------------------------
		$json = json_encode(
			$this->module->buildFollowersObject( $actorUrls ),
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
	 * This endpoint takes no path or query parameters.
	 *
	 * Pagination parameters (page, limit) are deferred — the followers
	 * collection is served as a single flat list for now. This is acceptable
	 * for the scale of a single wiki instance. Pagination would be added
	 * here and in buildFollowersObject() together when needed.
	 *
	 * @return array
	 */
	public function getParamSettings(): array {
		return [];
	}
}