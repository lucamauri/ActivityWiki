<?php
namespace MediawikiActivityPub;

use MediaWiki\JobQueue\JobQueueGroup;

class DeliveryQueue {
    
    public function queueActivity( $activity ) {
        // Save to database
        $db = wfGetDB( DB_PRIMARY );
        
        $activityId = uniqid( 'activity-' );
        
        $db->insert(
            'activitypub_activities',
            [
                'activity_id' => $activityId,
                'activity_type' => $activity['type'],
                'activity_json' => json_encode( $activity ),
                'published' => 0,
            ]
        );
        
        // Queue a job for async delivery
        $job = new DeliveryJob( [
            'activityId' => $activityId,
        ] );
        
        JobQueueGroup::singleton()->push( $job );
    }
}
