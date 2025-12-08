<?php
namespace MediawikiActivityPub\Api;

class ActivityPubModule {
    
    /**
     * GET /api/rest_v1/activitypub/actor
     * Returns the wiki's actor profile
     */
    public function getActorProfile() {
        global $wgSitename, $wgActivityPubActorName;
        
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => wfExpandUrl( '/' ) . 'api/activitypub/actor',
            'type' => 'Service',
            'name' => $wgActivityPubActorName ?? $wgSitename,
            'preferredUsername' => str_replace( ' ', '', $wgSitename ),
            'inbox' => wfExpandUrl( '/' ) . 'api/activitypub/inbox',
            'outbox' => wfExpandUrl( '/' ) . 'api/activitypub/outbox',
            'followers' => wfExpandUrl( '/' ) . 'api/activitypub/followers',
            'publicKey' => [
                'id' => wfExpandUrl( '/' ) . 'api/activitypub/actor#main-key',
                'owner' => wfExpandUrl( '/' ) . 'api/activitypub/actor',
                'publicKeyPem' => $this->getPublicKey(),
            ],
            'icon' => [
                'type' => 'Image',
                'url' => wfExpandUrl( '/wiki-logo.png' ),
            ],
            'summary' => 'The ' . $wgSitename . ' wiki',
        ];
    }
    
    /**
     * GET /api/rest_v1/activitypub/outbox
     * Returns paginated list of activities
     */
    public function getOutbox( $params ) {
        $limit = $params['limit'] ?? 10;
        $page = $params['page'] ?? 1;
        
        $db = wfGetDB( DB_REPLICA );
        $result = $db->select(
            'activitypub_activities',
            [ 'activity_json', 'created_at' ],
            [ 'published' => 1 ],
            __METHOD__,
            [
                'LIMIT' => $limit,
                'OFFSET' => ( $page - 1 ) * $limit,
                'ORDER BY' => 'created_at DESC'
            ]
        );
        
        $activities = [];
        foreach ( $result as $row ) {
            $activities[] = json_decode( $row->activity_json, true );
        }
        
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => wfExpandUrl( '/' ) . 'api/activitypub/outbox',
            'type' => 'OrderedCollection',
            'totalItems' => count( $activities ),
            'orderedItems' => $activities,
        ];
    }
    
    /**
     * GET /api/rest_v1/activitypub/followers
     * Empty followers list (for now—no incoming follows handled yet)
     */
    public function getFollowers() {
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => wfExpandUrl( '/' ) . 'api/activitypub/followers',
            'type' => 'OrderedCollection',
            'totalItems' => 0,
            'orderedItems' => [],
        ];
    }
}
