<?php
namespace MediawikiActivityPub\Api;

use MediaWiki\Rest\SimpleHandler;

class ActivityPubModule extends SimpleHandler {
    
    /**
     * GET /api/rest_v1/activitypub/actor
     */
    public function getActor() {
        global $wgSitename;
        
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => wfExpandUrl( '/api/rest_v1/activitypub/actor' ),
            'type' => 'Service',
            'name' => $wgSitename,
            'preferredUsername' => str_replace( ' ', '', $wgSitename ),
            'inbox' => wfExpandUrl( '/api/rest_v1/activitypub/inbox' ),
            'outbox' => wfExpandUrl( '/api/rest_v1/activitypub/outbox' ),
            'followers' => wfExpandUrl( '/api/rest_v1/activitypub/followers' ),
            'summary' => 'The ' . $wgSitename . ' wiki',
        ];
    }
    
    /**
     * GET /api/rest_v1/activitypub/outbox
     */
    public function getOutbox() {
        $db = wfGetDB( DB_REPLICA );
        
        $result = $db->select(
            'activitypub_activities',
            [ 'activity_json', 'created_at' ],
            [ 'published' => 1 ],
            __METHOD__,
            [
                'LIMIT' => 10,
                'ORDER BY' => 'created_at DESC'
            ]
        );
        
        $activities = [];
        foreach ( $result as $row ) {
            $activities[] = json_decode( $row->activity_json, true );
        }
        
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => wfExpandUrl( '/api/rest_v1/activitypub/outbox' ),
            'type' => 'OrderedCollection',
            'totalItems' => count( $activities ),
            'orderedItems' => $activities,
        ];
    }
    
    /**
     * GET /api/rest_v1/activitypub/followers
     */
    public function getFollowers() {
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => wfExpandUrl( '/api/rest_v1/activitypub/followers' ),
            'type' => 'OrderedCollection',
            'totalItems' => 0,
            'orderedItems' => [],
        ];
    }
}