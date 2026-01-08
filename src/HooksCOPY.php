<?php

namespace MediaWiki\Extension\ActivityWiki;

use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\MediaWikiServices;

class Hooks implements PageSaveCompleteHook {

    public function onPageSaveComplete(
        $wikiPage,
        $user,
        $summary,
        $flags,
        $revisionRecord,
        $editResult
    ) {
        wfDebugLog( 'activitypub', '=== PageSaveComplete HOOK ENTERED ===' );
        wfDebugLog( 'activitypub', 'Page: ' . $wikiPage->getTitle()->getPrefixedText() );

        try {
            $config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'main' );

            if ( !$config->get( 'ActivityPubEnabled' ) ) {
                wfDebugLog( 'activitypub', 'ActivityPub disabled' );
                return true;
            }

            if ( $user->isBot() ) {
                wfDebugLog( 'activitypub', 'Bot edit - skipping' );
                return true;
            }

            if ( $config->get( 'ActivityPubExcludeMinor' ) && ($flags & EDIT_MINOR) ) {
                wfDebugLog( 'activitypub', 'Minor edit - skipping' );
                return true;
            }

            $excludedNamespaces = $config->get( 'ActivityPubExcludedNamespaces' );
            $namespace = $wikiPage->getNamespace();
            if ( in_array( $namespace, $excludedNamespaces ) ) {
                wfDebugLog( 'activitypub', "Namespace $namespace excluded" );
                return true;
            }

            wfDebugLog( 'activitypub', 'All checks passed' );

            wfDebugLog( 'activitypub', 'Instantiating ActivityBuilder...' );
            $activityBuilder = new ActivityBuilder();
            wfDebugLog( 'activitypub', 'ActivityBuilder instantiated successfully' );

            if ( $flags & EDIT_NEW ) {
                wfDebugLog( 'activitypub', 'Creating CREATE activity...' );
                $activity = $activityBuilder->createCreateActivity(
                    $wikiPage,
                    $user,
                    $revisionRecord,
                    $summary
                );
                wfDebugLog( 'activitypub', 'CREATE activity built: ' . json_encode( $activity ) );
            } else {
                wfDebugLog( 'activitypub', 'Creating UPDATE activity...' );
                $activity = $activityBuilder->createUpdateActivity(
                    $wikiPage,
                    $user,
                    $revisionRecord,
                    $summary
                );
                wfDebugLog( 'activitypub', 'UPDATE activity built: ' . json_encode( $activity ) );
            }

            wfDebugLog( 'activitypub', 'Instantiating DeliveryQueue...' );
            $deliveryQueue = new DeliveryQueue();
            wfDebugLog( 'activitypub', 'DeliveryQueue instantiated' );

            wfDebugLog( 'activitypub', 'Queueing activity...' );
            $deliveryQueue->queueActivity( $activity, $wikiPage, $user );
            wfDebugLog( 'activitypub', 'Activity queued successfully' );

            wfDebugLog( 'activitypub', '=== HOOK COMPLETED SUCCESSFULLY ===' );
            return true;

        } catch ( \Throwable $e ) {
            wfDebugLog( 'activitypub', 'ERROR: ' . $e->getMessage() );
            wfDebugLog( 'activitypub', 'ERROR CLASS: ' . get_class( $e ) );
            wfDebugLog( 'activitypub', 'ERROR FILE: ' . $e->getFile() . ':' . $e->getLine() );
            wfDebugLog( 'activitypub', 'TRACE: ' . $e->getTraceAsString() );
            return true;
        }
    }
}
