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
     * Format timestamp to ISO 8601 for ActivityPub
     *
     * @param string $timestamp MediaWiki timestamp (yyyymmddhhmmss)
     * @return string ISO 8601 formatted timestamp
     */
    private function formatTimestamp( $timestamp ): string {
        // Convert from MediaWiki format (yyyymmddhhmmss) to ISO 8601
        $dateTime = \DateTime::createFromFormat( 'YmdHis', $timestamp );
        return $dateTime->format( \DateTime::ATOM );
    }
}
