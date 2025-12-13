<?php

namespace MediaWiki\Extension\ActivityWiki;

use MediaWiki\Page\Hook\PageSaveCompleteHook;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\MediaWikiServices;
use MediawikiActivityPub\ActivityBuilder;
use MediawikiActivityPub\DeliveryQueue;

class Hooks implements PageSaveCompleteHook {

    /**
     * Called when a page is saved
     *
     * @param \MediaWiki\Page\WikiPage $wikiPage
     * @param \MediaWiki\User\User $user
     * @param string $summary
     * @param int $flags
     * @param \MediaWiki\Revision\RevisionRecord $revisionRecord
     * @param \MediaWiki\Storage\EditResult $editResult
     * @return bool
     */
    public function onPageSaveComplete(
        $wikiPage,
        $user,
        $summary,
        $flags,
        $revisionRecord,
        $editResult
    ): void {
    try {
        wfDebugLog( 'activitypub', 'Hook fired for: ' . $wikiPage->getTitle()->getPrefixedText() );
        
        $config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'main' );

        // Check if ActivityPub is enabled
        if ( !$config->get( 'ActivityPubEnabled' ) ) {
            wfDebugLog( 'activitypub', 'ActivityPub disabled' );
            return;
        }

        wfDebugLog( 'activitypub', 'ActivityPub enabled, continuing...' );

        // Skip bot edits
        if ( $user->isBot() ) {
            wfDebugLog( 'activitypub', 'Skipping bot edit' );
            return;
        }

        wfDebugLog( 'activitypub', 'Not a bot edit, continuing...' );

        // Skip minor edits if configured
        if ( $config->get( 'ActivityPubExcludeMinor' ) && $flags & EDIT_MINOR ) {
            wfDebugLog( 'activitypub', 'Skipping minor edit' );
            return;
        }

        // Skip excluded namespaces
        $excludedNamespaces = $config->get( 'ActivityPubExcludedNamespaces' );
        if ( in_array( $wikiPage->getNamespace(), $excludedNamespaces ) ) {
            wfDebugLog( 'activitypub', 'Skipping excluded namespace' );
            return;
        }

        wfDebugLog( 'activitypub', 'Building activity...' );

        // Build the activity
        $activityBuilder = new ActivityBuilder();

        if ( $flags & EDIT_NEW ) {
            $activity = $activityBuilder->createCreateActivity(
                $wikiPage,
                $user,
                $revisionRecord,
                $summary
            );
        } else {
            $activity = $activityBuilder->createUpdateActivity(
                $wikiPage,
                $user,
                $revisionRecord,
                $summary
            );
        }

        wfDebugLog( 'activitypub', 'Activity built: ' . json_encode( $activity ) );

        // Queue the activity for delivery
        $deliveryQueue = new DeliveryQueue();
        $deliveryQueue->queueActivity( $activity );

        wfDebugLog( 'activitypub', 'Activity queued for ' . $wikiPage->getTitle()->getPrefixedText() );
    } catch ( \Exception $e ) {
        wfDebugLog( 'activitypub', 'Error in hook: ' . $e->getMessage() . ' ' . $e->getTraceAsString() );
    }
}

}
