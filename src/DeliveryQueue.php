<?php
namespace MediaWiki\Extension\ActivityWiki;

use MediaWiki\Extension\ActivityWiki\Jobs\DeliveryJob;

class DeliveryQueue {

    public function queueActivity( $activity, $wikiPage, $user ) {
        wfDebugLog( 'activitypub', 'DeliveryQueue::queueActivity called' );

        // Save to database
        $db = wfGetDB( DB_PRIMARY );

        $activityId = uniqid( 'activity-' );

        wfDebugLog( 'activitypub', 'Inserting activity into database: ' . $activityId );

        try {
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

            wfDebugLog( 'activitypub', 'Activity inserted successfully' );

            // Queue a job for async delivery
            $job = new DeliveryJob( [
                'activityId' => $activityId,
            ] );

            \MediaWiki\MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );
            wfDebugLog( 'activitypub', 'Job queued for delivery' );

        } catch ( \Exception $e ) {
            wfDebugLog( 'activitypub', 'Error in queueActivity: ' . $e->getMessage() );
            throw $e;
        }
    }
}
