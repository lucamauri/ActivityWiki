<?php
/**
 * ActivityWiki — ActivityBuilder
 *
 * Builds well-formed ActivityPub activity arrays for outbound federation.
 * Activities are built from MediaWiki page save events and passed to
 * DeliveryQueue for persistence and async delivery.
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\ActivityWiki;

use WikiPage;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserIdentity;

class ActivityBuilder {

    /**
     * @var WikiActorUrls Provides this wiki's own base URL and actor URL.
     *   Previously this class read $wgServer/$wgScriptPath directly via a
     *   Config object and built those URLs itself in two private methods;
     *   that logic was duplicated in three other classes (FollowManager,
     *   SignatureVerifier, HttpSigner) and has been consolidated into
     *   WikiActorUrls — see that class's docblock for the full history.
     */
    private WikiActorUrls $wikiActorUrls;

    /**
     * Construct the ActivityBuilder.
     *
     * Note: unlike FollowManager/SignatureVerifier/HttpSigner, ActivityBuilder
     * is not registered in ServiceWiring.php — it is manually instantiated
     * inside Hooks::onPageSaveComplete(), which builds the WikiActorUrls
     * instance itself from the Config it already has and passes it in here.
     *
     * @param WikiActorUrls $wikiActorUrls Provides this wiki's own base URL
     *   and actor URL.
     */
    public function __construct( WikiActorUrls $wikiActorUrls ) {
        $this->wikiActorUrls = $wikiActorUrls;
    }

    /**
     * Build a Create activity for a newly created page.
     *
     * A Create activity signals to followers that a new Article object has
     * appeared. The activity wraps an Article object containing the page URL,
     * title, and edit summary as content.
     *
     * @param WikiPage $wikiPage The newly created page.
     * @param UserIdentity $user The user who created the page.
     * @param RevisionRecord $revisionRecord The first revision of the page.
     * @param string $summary The edit summary, used as the activity content.
     * @return array Well-formed ActivityPub Create activity array.
     */
    public function createCreateActivity(
        WikiPage $wikiPage,
        UserIdentity $user,
        RevisionRecord $revisionRecord,
        string $summary = ''
    ): array {
        // getFullURL() is called on the Title object; we obtain it from the
        // WikiPage's PageReference via getTitle() which is still the correct
        // approach in MW 1.41+ for URL generation specifically
        $pageUrl = $wikiPage->getTitle()->getFullURL();
        $revisionId = $revisionRecord->getId();
        $published = $this->formatTimestamp( $revisionRecord->getTimestamp() );

        // Use the edit summary as the human-readable content of the activity.
        // Fall back to a localised default message if the summary is empty —
        // hardcoded English strings must never appear in federated content.
        $content = $summary !== ''
            ? $summary
            : wfMessage( 'activitywiki-default-create-summary' )->plain();

        $article = [
            '@context'    => 'https://www.w3.org/ns/activitystreams',
            'type'        => 'Article',
            'id'          => $pageUrl,
            'url'         => $pageUrl,
            'name'        => $wikiPage->getTitle()->getPrefixedText(),
            'content'     => $content,
            'attributedTo' => $this->getUserActorUrl( $user ),
            'published'   => $published,
        ];

        $activity = [
            '@context'  => 'https://www.w3.org/ns/activitystreams',
            'id'        => $this->getActivityUrl( $revisionId ),
            'type'      => 'Create',
            'actor'     => $this->wikiActorUrls->getWikiActorUrl(),
            'object'    => $article,
            'published' => $published,
            'to'        => [ 'https://www.w3.org/ns/activitystreams#Public' ],
            'cc'        => [ $this->getFollowersUrl() ],
        ];

        return $activity;
    }

    /**
     * Build an Update activity for an edited page.
     *
     * An Update activity signals to followers that an existing Article object
     * has changed. Structurally identical to a Create activity with a different
     * type field — the object URL is stable and identifies the same page.
     *
     * @param WikiPage $wikiPage The edited page.
     * @param UserIdentity $user The user who performed the edit.
     * @param RevisionRecord $revisionRecord The new revision produced by the edit.
     * @param string $summary The edit summary.
     * @return array Well-formed ActivityPub Update activity array.
     */
    public function createUpdateActivity(
        WikiPage $wikiPage,
        UserIdentity $user,
        RevisionRecord $revisionRecord,
        string $summary = ''
    ): array {
        $activity = $this->createCreateActivity( $wikiPage, $user, $revisionRecord, $summary );
        $activity['type'] = 'Update';
        return $activity;
    }

    /**
     * Get the followers collection URL for this wiki actor.
     *
     * Included in the 'cc' field of outbound activities so that the activity
     * is delivered to all followers.
     *
     * @return string Followers URL, e.g. "https://wikitrek.org/w/rest.php/activitywiki/followers"
     */
    private function getFollowersUrl(): string {
        return $this->wikiActorUrls->getWikiUrl() . 'rest.php/activitywiki/followers';
    }

    /**
     * Get the actor URL for a specific wiki user.
     *
     * Used in the 'attributedTo' field of Article objects to credit the
     * user who performed the page action.
     *
     * @param UserIdentity $user The user to build the actor URL for.
     * @return string User actor URL.
     */
    private function getUserActorUrl( UserIdentity $user ): string {
        return $this->wikiActorUrls->getWikiUrl() . 'rest.php/activitywiki/users/' . urlencode( $user->getName() );
    }

    /**
     * Get the unique URL for a specific activity.
     *
     * Each activity must have a globally unique 'id' URL. We use the revision
     * ID to make it stable and reproducible — the same revision always produces
     * the same activity URL.
     *
     * @param int $revisionId The revision ID that triggered this activity.
     * @return string Activity URL, e.g. "https://wikitrek.org/w/activitywiki/activities/12345"
     */
    private function getActivityUrl( int $revisionId ): string {
        return $this->wikiActorUrls->getWikiUrl() . 'activitywiki/activities/' . $revisionId;
    }

    /**
     * Format a MediaWiki timestamp to ISO 8601 for ActivityPub.
     *
     * Converts a MediaWiki internal timestamp (YmdHis) to an ISO 8601 string
     * as required by the ActivityPub spec (e.g. "2026-04-25T10:30:00+00:00").
     * Falls back to the current time if the timestamp is malformed or empty.
     *
     * @param string $timestamp MediaWiki timestamp in YmdHis format (e.g. "20260425103000").
     * @return string ISO 8601 formatted timestamp (e.g. "2026-04-25T10:30:00+00:00").
     */
    private function formatTimestamp( string $timestamp ): string {
        $dateTime = \DateTime::createFromFormat( 'YmdHis', $timestamp );
        if ( $dateTime === false ) {
            $dateTime = new \DateTime();
        }
        return $dateTime->format( \DateTime::ATOM );
    }
}