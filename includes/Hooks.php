<?php
namespace MediawikiActivityPub;

use MediaWiki\Page\Hook\PageSaveCompleteHook;

class Hooks implements PageSaveCompleteHook {
    
    /**
     * Fires when a page is saved
     */
    public function onPageSaveComplete( 
        $wikiPage,                    // WikiPage object
        $user,                        // User object
        $summary,                     // Edit summary
        $flags,                       // flags (e.g., EDIT_NEW)
        $revisionRecord,              // RevisionRecord object
        $editResult                   // EditResult
    ) {
        global $wgActivityPubEnabled;
        
        if ( !$wgActivityPubEnabled ) {
            return;
        }
        
        // Ignore bot edits, minor edits (configurable)
        if ( $user->isBot() ) {
            return;
        }
        
        $activityBuilder = new ActivityBuilder();
        
        // Determine if this is a Create or Update
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
        
        // Queue for delivery
        $deliveryQueue = new DeliveryQueue();
        $deliveryQueue->queueActivity( $activity );
        
        return true;
    }
}
