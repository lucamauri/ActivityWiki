<?php
namespace MediawikiActivityPub;

use MediaWiki\Page\Hook\PageSaveCompleteHook;

class Hooks implements PageSaveCompleteHook {
    
    /**
     * Called when a page is saved
     */
    public function onPageSaveComplete( 
        $wikiPage,
        $user,
        $summary,
        $flags,
        $revisionRecord,
        $editResult
    ) {
        global $wgActivityPubEnabled;
        
        if ( !$wgActivityPubEnabled ) {
            return;
        }
        
        // Skip bot edits
        if ( $user->isBot() ) {
            return;
        }
        
        // Build the activity
        $activityBuilder = new ActivityBuilder();
        
        if ( $flags & EDIT_NEW ) {
            $activity = $activityBuilder->createCreateActivity(
                $wikiPage,
                $user,
                $revisionRecord
            );
        } else {
            $activity = $activityBuilder->createUpdateActivity(
                $wikiPage,
                $user,
                $revisionRecord
            );
        }
        
        // Queue the activity
        $deliveryQueue = new DeliveryQueue();
        $deliveryQueue->queueActivity( $activity );
        
        // Log for debugging
        wfDebugLog( 'activitypub', 'Activity queued: ' . $activity['id'] );
        
        return true;
    }
}
