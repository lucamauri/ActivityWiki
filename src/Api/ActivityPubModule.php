<?php

namespace MediaWiki\Extension\ActivityWiki\Api;

use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;

/**
 * Utility class for building ActivityPub responses
 * 
 * This class provides static helper methods used by REST handlers
 * to construct ActivityPub Actor, Outbox, and Followers objects.
 */
class ActivityPubModule {

    /**
     * Build an ActivityPub Actor object for the wiki
     *
     * @return array ActivityPub Actor JSON structure
     */
    public static function buildActorObject(): array {
        $config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'main' );
        
        $mainPageUrl = self::getWikiUrl( $config );
        $actorName = $config->get( 'ActivityPubActorName' ) ?? 'Wiki';
        $actorIcon = $config->get( 'ActivityPubActorIcon' ) ?? '/logo.png';
        $wikiUrl = self::getWikiUrl( $config );
        
        return [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1'
            ],
            'type' => 'Person',
            'id' => $wikiUrl . '/activitypub/actor',
            'name' => $actorName,
            'preferredUsername' => strtolower( str_replace( ' ', '', $actorName ) ),
            'summary' => 'MediaWiki instance',
            'url' => $mainPageUrl,
            'icon' => [
                'type' => 'Image',
                'url' => $wikiUrl . $actorIcon
            ],
            'inbox' => $wikiUrl . '/activitypub/inbox',
            'outbox' => $wikiUrl . '/activitypub/outbox',
            'followers' => $wikiUrl . '/activitypub/followers',
            'following' => $wikiUrl . '/activitypub/following',
            'publicKey' => [
                'id' => $wikiUrl . '/activitypub/actor#main-key',
                'owner' => $wikiUrl . '/activitypub/actor',
                'publicKeyPem' => self::getPublicKeyPem()
            ]
        ];
    }

    /**
     * Build an ActivityPub Outbox OrderedCollection
     *
     * @param int $limit Number of recent activities to include
     * @return array ActivityPub OrderedCollection JSON structure
     */
    public static function buildOutboxObject( $limit = 20 ): array {
        $config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'main' );
        $wikiUrl = self::getWikiUrl( $config );
        
        // TODO: Fetch recent activities from activity_log table
        $activities = [];
        
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'type' => 'OrderedCollection',
            'id' => $wikiUrl . '/activitypub/outbox',
            'totalItems' => 0,
            'orderedItems' => $activities
        ];
    }

    /**
     * Build an ActivityPub Followers Collection
     *
     * @return array ActivityPub Collection JSON structure
     */
    public static function buildFollowersObject(): array {
        $config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'main' );
        $wikiUrl = self::getWikiUrl( $config );
        
        // TODO: Fetch followers from database
        $followers = [];
        
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'type' => 'Collection',
            'id' => $wikiUrl . '/activitypub/followers',
            'totalItems' => 0,
            'items' => $followers
        ];
    }

    /**
     * Get the wiki base URL
     *
     * @param Config $config MediaWiki config
     * @return string Base URL of the wiki
     */
    private static function getWikiUrl( Config $config ): string {
        $scriptPath = $config->get( 'ScriptPath' );
        $server = $config->get( 'Server' );
        
        return rtrim( $server, '/' ) . $scriptPath;
    }

    /**
     * Get the public key PEM for actor signing
     *
     * @return string Public key in PEM format, or placeholder
     */
    private static function getPublicKeyPem(): string {
        // TODO: Implement proper key management
        return '-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----';
    }
}
