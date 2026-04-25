<?php
/**
 * ActivityWiki — DeliveryJob
 *
 * Processes a single queued ActivityPub delivery: fetches the activity from
 * the database and (once the delivery engine is implemented in Layer 3) POSTs
 * it to all follower inboxes.
 *
 * This job is enqueued by DeliveryQueue::queueActivity() on every qualifying
 * page event and processed asynchronously by MediaWiki's job queue runner.
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\ActivityWiki\Jobs;

use MediaWiki\JobQueue\Job;
use Wikimedia\Rdbms\IConnectionProvider;

class DeliveryJob extends Job {

    private IConnectionProvider $dbProvider;

    /**
     * Construct a new DeliveryJob.
     *
     * @param array $params Job parameters. Must contain:
     *   - 'activityId' (string): the unique ID of the activity row to deliver.
     * @param IConnectionProvider $dbProvider Database connection provider,
     *   injected by the job queue infrastructure.
     */
    public function __construct( array $params, IConnectionProvider $dbProvider ) {
        parent::__construct( 'MediawikiActivityPubDelivery', $params );
        $this->dbProvider = $dbProvider;
    }

    /**
     * Execute the delivery job.
     *
     * Fetches the activity record from the database, decodes the JSON payload,
     * and (stub) logs it. Actual HTTP delivery to follower inboxes will be
     * implemented in Layer 3.
     *
     * @return bool True on success, false if the activity record is not found
     *   or the payload is malformed.
     */
    public function run(): bool {
        // Guard: ensure the required parameter was passed when the job was queued
        if ( !isset( $this->params['activityId'] ) ) {
            wfDebugLog( 'ActivityWiki', 'DeliveryJob: missing activityId parameter' );
            return false;
        }

        $activityId = $this->params['activityId'];

        // Fetch the activity record using the modern query builder (MW 1.41+)
        $db = $this->dbProvider->getReplicaDatabase();
        $row = $db->newSelectQueryBuilder()
            ->select( [ 'activity_json', 'activity_type' ] )
            ->from( 'activitywiki_activities' )
            ->where( [ 'activity_id' => $activityId ] )
            ->caller( __METHOD__ )
            ->fetchRow();

        if ( !$row ) {
            wfDebugLog( 'ActivityWiki', "DeliveryJob: activity not found: $activityId" );
            return false;
        }

        // Guard: json_decode() returns null on malformed input
        $activity = json_decode( $row->activity_json, true );
        if ( $activity === null ) {
            wfDebugLog( 'ActivityWiki', "DeliveryJob: malformed JSON for activity: $activityId" );
            return false;
        }

        // STUB — Layer 3 will replace this with actual HTTP delivery to follower inboxes
        wfDebugLog( 'ActivityWiki', 'DeliveryJob: would deliver activity: ' . json_encode( $activity ) );

        // Mark the activity as delivered using the modern query builder (MW 1.41+)
        $db = $this->dbProvider->getPrimaryDatabase();
        $db->newUpdateQueryBuilder()
            ->update( 'activitywiki_activities' )
            ->set( [ 'published' => 1 ] )
            ->where( [ 'activity_id' => $activityId ] )
            ->caller( __METHOD__ )
            ->execute();

        return true;
    }
}