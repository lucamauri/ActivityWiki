<?php

namespace MediaWiki\Extension\ActivityWiki\Rest;

use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Extension\ActivityWiki\Api\ActivityPubModule;

class ActorHandler extends SimpleHandler {

    /**
     * Handle GET /activitypub/actor
     *
     * @return array ActivityPub Actor object
     */
    public function run() {
        return ActivityPubModule::buildActorObject();
    }
}
