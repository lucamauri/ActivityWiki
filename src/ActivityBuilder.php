<?php

namespace MediaWiki\Extension\ActivityWiki;

use MediaWiki\MediaWikiServices;
use MediaWiki\Config\Config;

class ActivityBuilder {

    private Config $config;

    public function __construct() {
        $this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'main' );
    }

    /**
     * Build a Create activity for a new page
     *
     * @param \MediaWiki\Page\WikiPage $wikiPage
     * @param \MediaWiki\User\User $user
     * @param \MediaWiki\Revision\RevisionRecord $revisionRecord
     * @param string $summary Edit summary
     * @return array ActivityPub Create activity
     */
    public function createCreateActivity( $wikiPage, $user, $revisionRecord, $summary = '' ): array {
        $pageUrl = $wikiPage->getTitle()->getFullURL();
        $revisionId = $revisionRecord->getId();

        $article = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'type' => 'Article',
            'id' => $pageUrl,
            'url' => $pageUrl,
            'name' => $wikiPage->getTitle()->getPrefixedText(),
            'content' => $summary ?: 'New page created',
            'attributedTo' => $user->getUserPage()->getFullURL(),
            'published' => $this->formatTimestamp( $revisionRecord->getTimestamp() ),
        ];

        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $this->getActivityUrl( $revisionId ),
            'type' => 'Create',
            'actor' => $this->getWikiActorUrl(),
            'object' => $article,
            'published' => $this->formatTimestamp( $revisionRecord->getTimestamp() ),
            'to' => [ 'https://www.w3.org/ns/activitystreams#Public' ],
        ];

        return $activity;
    }

    /**
     * Build an Update activity for an existing page
     *
     * @param \MediaWiki\Page\WikiPage $wikiPage
     * @param \MediaWiki\User\User $user
     * @param \MediaWiki\Revision\RevisionRecord $revisionRecord
     * @param string $summary Edit summary
     * @return array ActivityPub Update activity
     */
    public function createUpdateActivity( $wikiPage, $user, $revisionRecord, $summary = '' ): array {
        $activity = $this->createCreateActivity( $wikiPage, $user, $revisionRecord, $summary );
        $activity['type'] = 'Update';
        return $activity;
    }

    /**
     * Get the wiki's base URL
     *
     * @return string Wiki base URL with trailing slash
     */
    private function getWikiUrl(): string {
        $server = $this->config->get( 'Server' );
        $scriptPath = $this->config->get( 'ScriptPath' );
        return rtrim( $server, '/' ) . $scriptPath . '/';
    }

    /**
     * Get the wiki actor URL for ActivityPub
     *
     * @return string Actor URL
     */
    private function getWikiActorUrl(): string {
        return $this->getWikiUrl() . 'rest.php/activitypub/actor';
    }

    /**
     * Get activity URL
     *
     * @param int $revisionId Revision ID
     * @return string Activity URL
     */
    private function getActivityUrl( $revisionId ): string {
        return $this->getWikiUrl() . 'activitypub/activities/' . $revisionId;
    }

    /**
     * Format timestamp to ISO 8601 for ActivityPub.
     * 
     * Converts a MediaWiki internal timestamp (yyyymmddhhmmss) to an ISO 8601
     * string as required by the ActivityPub spec (e.g. "2026-04-25T10:30:00+00:00").
     * 
     * 
     * Falls back to the current time if the provided timestamp is malformed or
     * empty, to avoid a fatal TypeError on DateTime method call failure.
     * 
     * @param string $timestamp MediaWiki timestamp in YmdHis format (e.g. "20260425103000")
     * @return string ISO 8601 formatted timestamp (e.g. "2026-04-25T10:30:00+00:00")
    */
    private function formatTimestamp( string $timestamp ): string {
        // DateTime::createFromFormat() returns false if the input does not match
        // the expected format — we must guard against this before calling ->format()
        $dateTime = \DateTime::createFromFormat( 'YmdHis', $timestamp );
        if ( $dateTime === false ) {
            // Fallback: use current time rather than crashing
            $dateTime = new \DateTime();
        }
        return $dateTime->format( \DateTime::ATOM );
    }
}
