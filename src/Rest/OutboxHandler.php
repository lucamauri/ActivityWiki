<?php

namespace MediaWiki\Extension\ActivityWiki\Rest;

use MediaWiki\Rest\SimpleHandler;
use MediawikiActivityPub\Api\ActivityPubModule;

class OutboxHandler extends SimpleHandler {

    /**
     * Handle GET /activitypub/outbox
     *
     * @return array ActivityPub OrderedCollection object
     */
    public function run() {
        return ActivityPubModule::buildOutboxObject();
    }
}
