<?php

namespace MediaWiki\Extension\ActivityWiki\Rest;

use MediaWiki\Rest\SimpleHandler;

class OutboxHandler extends SimpleHandler {

    /**
     * Handle GET /activitypub/outbox
     *
     * @return array
     */
    public function run() {
        // TODO: Build and return an ActivityPub OrderedCollection of activities
        return [
            'type' => 'OrderedCollection',
            'id' => 'https://example.org/activitypub/outbox',
            'totalItems' => 0,
            'orderedItems' => [],
        ];
    }
}
