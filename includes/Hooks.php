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
        $config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'main' );

        // Check if ActivityPub is enabled
        if ( !$config->get( 'ActivityPubEnabled' ) ) {
            return;
        }

        // Skip bot edits
        if ( $user->isBot() ) {
            return;
        }

        // Skip minor edits if configured
        if ( $config->get( 'ActivityPubExcludeMinor' ) && $flags & EDIT_MINOR ) {
            return;
        }

        // Skip excluded namespaces
        $excludedNamespaces = $config->get( 'ActivityPubExcludedNamespaces' );
        if ( in_array( $wikiPage->getNamespace(), $excludedNamespaces ) ) {
            return;
        }

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

        // Queue the activity for delivery
        $deliveryQueue = new DeliveryQueue();
        $deliveryQueue->queueActivity( $activity );

        // Log for debugging
        wfDebugLog( 'activitypub', 'Activity queued for ' . $wikiPage->getTitle()->getPrefixedText() );
    }
}
