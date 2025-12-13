<?php

namespace MediaWiki\Extension\ActivityWiki\Rest;

use MediaWiki\Rest\SimpleHandler;

class ActorHandler extends SimpleHandler {

    /**
     * Handle GET /activitypub/actor
     *
     * @return array
     */
    public function run() {
        // TODO: Build and return a proper ActivityPub Actor object
        // For now, return a simple placeholder structure
        return [
            'type' => 'Person',
            'id' => 'https://example.org/activitypub/actor',
            'name' => 'Example Actor',
        ];
    }
}
