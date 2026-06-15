<?php
/**
 * ActivityWiki — Hooks
 *
 * Handles MediaWiki hook callbacks that trigger ActivityPub federation.
 * Currently implements PageSaveCompleteHook to federate page creations
 * and edits as ActivityPub Create/Update activities.
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\ActivityWiki;

use MediaWiki\Config\Config;
use JobQueueGroup;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use Wikimedia\Rdbms\IConnectionProvider;

class Hooks implements PageSaveCompleteHook {

    private Config $config;
    private IConnectionProvider $dbProvider;
    private JobQueueGroup $jobQueueGroup;

    /**
     * Construct the Hooks handler.
     *
     * All dependencies are injected by MediaWiki's service container via
     * the HookHandlers entry in extension.json — never instantiated manually.
     *
     * @param Config $config The main wiki configuration object, used to read
     *   ActivityWiki settings such as enabled state, debug level, and filters.
     * @param IConnectionProvider $dbProvider Database connection provider,
     *   passed through to DeliveryQueue for activity persistence.
     * @param JobQueueGroup $jobQueueGroup Job queue group, passed through to
     *   DeliveryQueue for async delivery job dispatch.
     */
    public function __construct(
        Config $config,
        IConnectionProvider $dbProvider,
        JobQueueGroup $jobQueueGroup
    ) {
        $this->config = $config;
        $this->dbProvider = $dbProvider;
        $this->jobQueueGroup = $jobQueueGroup;
    }

    /**
     * Handle the PageSaveComplete hook.
     *
     * Fired after every successful page save. Applies a series of filters
     * (extension enabled, bot edits, minor edits, namespace exclusions) and,
     * if all pass, builds an ActivityPub activity and queues it for delivery.
     *
     * @param \MediaWiki\Page\WikiPage $wikiPage The page that was saved.
     * @param \MediaWiki\User\UserIdentity $user The user who performed the save.
     * @param string $summary The edit summary.
     * @param int $flags Bitmask of EDIT_* flags (e.g. EDIT_NEW, EDIT_MINOR).
     * @param \MediaWiki\Revision\RevisionRecord $revisionRecord The new revision.
     * @param \MediaWiki\Storage\EditResult $editResult Result object for the edit.
     * @return bool Always true — we never want to abort the hook chain.
     */
    public function onPageSaveComplete(
        $wikiPage,
        $user,
        $summary,
        $flags,
        $revisionRecord,
        $editResult
    ): bool {
        $this->debug( '=== PageSaveComplete HOOK ENTERED ===' );
        $this->debug( 'Page: ' . $wikiPage->getTitle()->getPrefixedText() );

        try {
            // Check 1: extension must be enabled
            if ( !$this->config->get( 'ActivityWikiEnabled' ) ) {
                $this->debug( 'ActivityWiki disabled, skipping' );
                return true;
            }

            // Check 2: ignore bot edits — bots generate noise, not meaningful content
            if ( $user->isBot() ) {
                $this->debug( 'Bot edit — skipping' );
                return true;
            }

            // Check 3: optionally suppress minor edits
            if ( $this->config->get( 'ActivityWikiPublishMinorEdits' ) === false
                && ( $flags & EDIT_MINOR )
            ) {
                $this->debug( 'Minor edit — skipping (ActivityWikiPublishMinorEdits is false)' );
                return true;
            }

            // Check 4: namespace filter — only federate configured namespaces
            $allowedNamespaces = $this->config->get( 'ActivityWikiPublishNamespaces' );
            $namespace = $wikiPage->getNamespace();
            if ( !in_array( $namespace, $allowedNamespaces, true ) ) {
                $this->debug( "Namespace $namespace not in ActivityWikiPublishNamespaces — skipping" );
                return true;
            }

            $this->debug( 'All checks passed — building activity' );

            // Build the appropriate activity type based on whether this is a
            // new page (EDIT_NEW flag set) or an edit to an existing page
            $activityBuilder = new ActivityBuilder( $this->config );
            if ( $flags & EDIT_NEW ) {
                $this->debug( 'Building Create activity...' );
                $activity = $activityBuilder->createCreateActivity(
                    $wikiPage, $user, $revisionRecord, $summary
                );
            } else {
                $this->debug( 'Building Update activity...' );
                $activity = $activityBuilder->createUpdateActivity(
                    $wikiPage, $user, $revisionRecord, $summary
                );
            }

            $this->debug( 'Activity built: ' . json_encode( $activity ) );

            // Persist the activity and enqueue the delivery job.
            // DeliveryQueue receives its dependencies from our own constructor —
            // we do NOT call new DeliveryQueue() twice (was a bug in the original).
            $deliveryQueue = new DeliveryQueue( $this->dbProvider, $this->jobQueueGroup );
            $deliveryQueue->queueActivity( $activity, $wikiPage, $user );

            $this->debug( '=== HOOK COMPLETED SUCCESSFULLY ===' );

        } catch ( \Throwable $e ) {
            // Log all unexpected errors but never abort the hook chain —
            // a federation failure must never prevent a page from being saved
            wfDebugLog( 'ActivityWiki', 'ERROR: ' . $e->getMessage() );
            wfDebugLog( 'ActivityWiki', 'ERROR CLASS: ' . get_class( $e ) );
            wfDebugLog( 'ActivityWiki', 'ERROR FILE: ' . $e->getFile() . ':' . $e->getFile() );
            wfDebugLog( 'ActivityWiki', 'TRACE: ' . $e->getTraceAsString() );
        }

        return true;
    }

    /**
     * Log a debug message if the configured debug level is >= 1.
     *
     * Uses the Config instance cached in the constructor — config is NOT
     * re-instantiated on every call (was a performance bug in the original).
     *
     * @param string $message The message to log.
     * @return void
     */
    private function debug( string $message ): void {
        if ( $this->config->get( 'ActivityWikiDebugLevel' ) >= 1 ) {
            wfDebugLog( 'ActivityWiki', $message );
        }
    }
}