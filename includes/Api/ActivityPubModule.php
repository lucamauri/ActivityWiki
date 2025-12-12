namespace MediawikiActivityPub\Api;

use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\Response;

class ActivityPubModule extends SimpleHandler {


    /**
     * GET /api/rest_v1/activitypub/actor
     */
    public function getActor(): Response {
        $config = $this->getConfig();
        $wgServer = $config->get( 'Server' );
        $wgSitename = $config->get( 'Sitename' );

        $data = [
    public function getActor(): Response {
        $config = $this->getConfig();
        $wgServer = $config->get( 'Server' );
        $wgSitename = $config->get( 'Sitename' );

        $data = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $wgServer . '/api/rest_v1/activitypub/actor',
            'id' => $wgServer . '/api/rest_v1/activitypub/actor',
            'type' => 'Service',
            'name' => $wgSitename,
            'preferredUsername' => str_replace( ' ', '', $wgSitename ),
            'inbox' => $wgServer . '/api/rest_v1/activitypub/inbox',
            'outbox' => $wgServer . '/api/rest_v1/activitypub/outbox',
            'followers' => $wgServer . '/api/rest_v1/activitypub/followers',
            'inbox' => $wgServer . '/api/rest_v1/activitypub/inbox',
            'outbox' => $wgServer . '/api/rest_v1/activitypub/outbox',
            'followers' => $wgServer . '/api/rest_v1/activitypub/followers',
            'summary' => 'The ' . $wgSitename . ' wiki',
        ];

        return $this->getResponseFactory()->createJson( $data );

        return $this->getResponseFactory()->createJson( $data );
    }


    /**
     * GET /api/rest_v1/activitypub/outbox
     */
    public function getOutbox(): Response {
        $config = $this->getConfig();
        $wgServer = $config->get( 'Server' );

        $data = [
    public function getOutbox(): Response {
        $config = $this->getConfig();
        $wgServer = $config->get( 'Server' );

        $data = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $wgServer . '/api/rest_v1/activitypub/outbox',
            'id' => $wgServer . '/api/rest_v1/activitypub/outbox',
            'type' => 'OrderedCollection',
            'totalItems' => 0,
            'orderedItems' => [],
            'totalItems' => 0,
            'orderedItems' => [],
        ];

        return $this->getResponseFactory()->createJson( $data );

        return $this->getResponseFactory()->createJson( $data );
    }


    /**
     * GET /api/rest_v1/activitypub/followers
     */
    public function getFollowers(): Response {
        $config = $this->getConfig();
        $wgServer = $config->get( 'Server' );

        $data = [
    public function getFollowers(): Response {
        $config = $this->getConfig();
        $wgServer = $config->get( 'Server' );

        $data = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $wgServer . '/api/rest_v1/activitypub/followers',
            'id' => $wgServer . '/api/rest_v1/activitypub/followers',
            'type' => 'OrderedCollection',
            'totalItems' => 0,
            'orderedItems' => [],
        ];

        return $this->getResponseFactory()->createJson( $data );

        return $this->getResponseFactory()->createJson( $data );
    }
}
