<?php
namespace MediaWiki\Extension\ActivityWiki;

use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Extension\ActivityWiki\Jobs\DeliveryJob;

class DeliveryQueue {
    
    public function queueActivity( $activity, $wikiPage, $user ) {
        // Save to database
        $db = wfGetDB( DB_PRIMARY );
        
        $activityId = uniqid( 'activity-' );
        
        $db->insert(
            'activitypub_activities',
            [
                'activity_id' => $activityId,
                'activity_type' => $activity['type'],
                'object_type' => $activity['object']['type'],
                'page_id' => $wikiPage->getId(),
                'page_title' => $wikiPage->getTitle()->getPrefixedText(),
                'user_id' => $user->getId(),
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