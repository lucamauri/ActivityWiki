<?php

namespace MediaWiki\Extension\ActivityWiki\Rest;

use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Extension\ActivityWiki\Api\ActivityPubModule;

class FollowersHandler extends SimpleHandler {

    /**
     * Handle GET /activitypub/followers
     *
     * @return array ActivityPub Collection object
     */
    public function run() {
        return ActivityPubModule::buildFollowersObject();
    }
}
