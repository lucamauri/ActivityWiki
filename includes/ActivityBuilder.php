<?php
namespace MediawikiActivityPub;

class ActivityBuilder {
    
    /**
     * Build a Create activity for a new page
     */
    public function createCreateActivity( $wikiPage, $user, $revisionRecord ) {
        $pageUrl = $wikiPage->getTitle()->getFullURL();
        
        $article = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'type' => 'Article',
            'id' => $pageUrl,
            'url' => $pageUrl,
            'name' => $wikiPage->getTitle()->getText(),
            'published' => $revisionRecord->getTimestamp(),
        ];
        
        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $this->getWikiUrl() . 'api/rest_v1/activitypub/activities/' . uniqid(),
            'type' => 'Create',
            'actor' => $this->getWikiActorUrl(),
            'object' => $article,
            'published' => $revisionRecord->getTimestamp(),
            'to' => [ 'https://www.w3.org/ns/activitystreams#Public' ],
        ];
        
        return $activity;
    }
    
    /**
     * Build an Update activity
     */
    public function createUpdateActivity( $wikiPage, $user, $revisionRecord ) {
        $activity = $this->createCreateActivity( $wikiPage, $user, $revisionRecord );
        $activity['type'] = 'Update';
        return $activity;
    }
    
    /**
     * Get the wiki's base URL
     */
    private function getWikiUrl() {
        global $wgServer, $wgScriptPath;
        return $wgServer . $wgScriptPath . '/';
    }
    
    /**
     * Get the actor URL
     */
    private function getWikiActorUrl() {
        return $this->getWikiUrl() . 'api/rest_v1/activitypub/actor';
    }
}