<?php

namespace MediaWiki\Extension\ActivityWiki\Rest;

use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Extension\ActivityWiki\Api\ActivityPubModule;

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
