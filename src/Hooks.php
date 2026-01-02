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
        $this->debug( '=== PageSaveComplete HOOK ENTERED ===' );
        $this->debug( 'Page: ' . $wikiPage->getTitle()->getPrefixedText() );

        try {
            $config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'main' );

            if ( !$config->get( 'ActivityPubEnabled' ) ) {
                $this->debug( 'ActivityPub disabled' );
                return true;
            }

            if ( $user->isBot() ) {
                $this->debug( 'Bot edit - skipping' );
                return true;
            }

            if ( $config->get( 'ActivityPubExcludeMinor' ) && ($flags & EDIT_MINOR) ) {
                $this->debug( 'Minor edit - skipping' );
                return true;
            }

            $excludedNamespaces = $config->get( 'ActivityPubExcludedNamespaces' );
            $namespace = $wikiPage->getNamespace();
            if ( in_array( $namespace, $excludedNamespaces ) ) {
                $this->debug( "Namespace $namespace excluded" );
                return true;
            }

            $this->debug( 'All checks passed' );

            $this->debug( 'Instantiating ActivityBuilder...' );
            $activityBuilder = new ActivityBuilder();
            $this->debug( 'ActivityBuilder instantiated successfully' );

            if ( $flags & EDIT_NEW ) {
                $this->debug( 'Creating CREATE activity...' );
                $activity = $activityBuilder->createCreateActivity(
                    $wikiPage,
                    $user,
                    $revisionRecord,
                    $summary
                );
                $this->debug( 'CREATE activity built: ' . json_encode( $activity ) );
            } else {
                $this->debug( 'Creating UPDATE activity...' );
                $activity = $activityBuilder->createUpdateActivity(
                    $wikiPage,
                    $user,
                    $revisionRecord,
                    $summary
                );
                $this->debug( 'UPDATE activity built: ' . json_encode( $activity ) );
            }

            $this->debug( 'Instantiating DeliveryQueue...' );
            $deliveryQueue = new DeliveryQueue();
            $this->debug( 'DeliveryQueue instantiated' );

            $this->debug( 'Queueing activity...' );
            $deliveryQueue->queueActivity( $activity, $wikiPage, $user );
            $this->debug( 'Activity queued successfully' );

            $this->debug( '=== HOOK COMPLETED SUCCESSFULLY ===' );
            return true;

        } catch ( \Throwable $e ) {
            wfDebugLog( 'activitypub', 'ERROR: ' . $e->getMessage() );
            wfDebugLog( 'activitypub', 'ERROR CLASS: ' . get_class( $e ) );
            wfDebugLog( 'activitypub', 'ERROR FILE: ' . $e->getFile() . ':' . $e->getLine() );
            wfDebugLog( 'activitypub', 'TRACE: ' . $e->getTraceAsString() );
            return true;
        }
    }

    /**
     * Helper method to log debug messages based on configuration
     */
    private function debug( $message ) {
        $config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'main' );
        $debugLevel = $config->get( 'ActivityPubDebugLevel' );
        
        if ( $debugLevel >= 1 ) {
            wfDebugLog( 'activitypub', $message );
        }
    }
}
