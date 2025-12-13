<?php

namespace MediaWiki\Extension\ActivityWiki\Rest;

use MediaWiki\Rest\SimpleHandler;

class FollowersHandler extends SimpleHandler {

    /**
     * Handle GET /activitypub/followers
     *
     * @return array
     */
    public function run() {
        // TODO: Build and return an ActivityPub Collection of followers
        return [
            'type' => 'Collection',
            'id' => 'https://example.org/activitypub/followers',
            'totalItems' => 0,
            'items' => [],
        ];
    }
}
