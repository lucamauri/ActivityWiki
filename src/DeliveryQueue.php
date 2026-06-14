<?php
/**
 * ActivityWiki — DeliveryQueue
 *
 * Persists an outbound ActivityPub activity to the database and enqueues
 * an async DeliveryJob to handle the actual HTTP delivery to follower inboxes.
 *
 * Called by the hook handler immediately after a qualifying page event occurs.
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\ActivityWiki;

use MediaWiki\Extension\ActivityWiki\Jobs\DeliveryJob;
use MediaWiki\JobQueue\JobQueueGroup;
use Wikimedia\Rdbms\IConnectionProvider;

class DeliveryQueue {

    private IConnectionProvider $dbProvider;
    private JobQueueGroup $jobQueueGroup;

    /**
     * Construct a new DeliveryQueue.
     *
     * @param IConnectionProvider $dbProvider Database connection provider,
     *   used to obtain a primary DB connection for inserts.
     * @param JobQueueGroup $jobQueueGroup Job queue group used to push
     *   async delivery jobs after the activity is persisted.
     */
    public function __construct(
        IConnectionProvider $dbProvider,
        JobQueueGroup $jobQueueGroup
    ) {
        $this->dbProvider = $dbProvider;
        $this->jobQueueGroup = $jobQueueGroup;
    }

    /**
     * Persist an activity to the database and enqueue it for async delivery.
     *
     * Generates a collision-safe unique activity ID, inserts the activity
     * record, then pushes a DeliveryJob to the MediaWiki job queue.
     *
     * @param array $activity The ActivityPub activity array, as built by ActivityBuilder.
     *   Must contain at minimum 'type' and 'object' keys.
     * @param \MediaWiki\Page\WikiPage $wikiPage The wiki page that triggered the activity.
     * @param \MediaWiki\User\User $user The user whose action triggered the activity.
     * @return void
     * @throws \Exception Re-thrown if the database insert or job push fails.
     */
    public function queueActivity( array $activity, $wikiPage, $user ): void {
        wfDebugLog( 'ActivityWiki', 'DeliveryQueue::queueActivity called' );

        // Generate a collision-safe unique ID.
        // uniqid() is NOT safe under concurrent writes — two simultaneous saves
        // within the same microsecond produce identical IDs, violating the UNIQUE
        // constraint. bin2hex( random_bytes( 16 ) ) is cryptographically random
        // and collision-safe regardless of timing.
        $activityId = bin2hex( random_bytes( 16 ) );

        // Guard: json_encode() can return false on malformed input (e.g. invalid UTF-8).
        // Inserting false into the database would silently corrupt the activity record.
        $activityJson = json_encode( $activity );
        if ( $activityJson === false ) {
            wfDebugLog( 'ActivityWiki', 'DeliveryQueue: failed to encode activity JSON, aborting' );
            return;
        }

        wfDebugLog( 'ActivityWiki', 'Inserting activity into database: ' . $activityId );

        try {
            // Use the modern query builder (MW 1.41+) instead of deprecated $db->insert()
            $db = $this->dbProvider->getPrimaryDatabase();
            $db->newInsertQueryBuilder()
                ->insertInto( 'activitywiki_activities' )
                ->row( [
                    'activity_id'   => $activityId,
                    'activity_type' => $activity['type'],
                    'object_type'   => $activity['object']['type'],
                    'page_id'       => $wikiPage->getId(),
                    'page_title'    => $wikiPage->getTitle()->getPrefixedText(),
                    'user_id'       => $user->getId(),
                    'activity_json' => $activityJson,
                    'published'     => 0,
                ] )
                ->caller( __METHOD__ )
                ->execute();

            wfDebugLog( 'ActivityWiki', 'Activity inserted successfully' );

            // Push an async job to deliver this activity to follower inboxes.
            // The job receives only the activity ID — it re-fetches the full
            // record from the database when it runs, avoiding large param payloads.
            $job = new DeliveryJob( [ 'activityId' => $activityId ], $this->dbProvider );
            $this->jobQueueGroup->push( $job );

            wfDebugLog( 'ActivityWiki', 'DeliveryJob queued for activity: ' . $activityId );

        } catch ( \Exception $e ) {
            wfDebugLog( 'ActivityWiki', 'DeliveryQueue error: ' . $e->getMessage() );
            throw $e;
        }
    }
}