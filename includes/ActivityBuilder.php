<?php
namespace MediawikiActivityPub;

class ActivityBuilder {
    
    /**
     * Build an ActivityPub Create activity for a new page
     */
    public function createCreateActivity( $wikiPage, $user, $revisionRecord ) {
        $wikiUrl = wfExpandUrl( '/' );
        $pageUrl = $wikiPage->getTitle()->getFullURL();
        
        $article = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'type' => 'Article',
            'id' => $pageUrl,
            'url' => $pageUrl,
            'name' => $wikiPage->getTitle()->getText(),
            'content' => $wikiPage->getContent()->getText(),
            'attributedTo' => $this->getUserActorUrl( $user ),
            'published' => $revisionRecord->getTimestamp(),
        ];
        
        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $wikiUrl . 'api/activitypub/activities/' . uniqid(),
            'type' => 'Create',
            'actor' => $this->getWikiActorUrl(),
            'object' => $article,
            'published' => $revisionRecord->getTimestamp(),
            'to' => [ $this->getFollowersUrl() ],
        ];
        
        return $activity;
    }
    
    /**
     * Build an ActivityPub Update activity for an edited page
     */
    public function createUpdateActivity( $wikiPage, $user, $revisionRecord ) {
        $activity = $this->createCreateActivity( $wikiPage, $user, $revisionRecord );
        $activity['type'] = 'Update';
        return $activity;
    }
    
    private function getWikiActorUrl() {
        global $wgActivityPubActorName;
        return wfExpandUrl( '/' ) . 'api/activitypub/actor';
    }
    
    private function getUserActorUrl( $user ) {
        global $wgActivityPubEnableUserActors;
        if ( !$wgActivityPubEnableUserActors ) {
            return $this->getWikiActorUrl();
        }
        return wfExpandUrl( '/' ) . 'api/activitypub/users/' . urlencode( $user->getName() );
    }
    
    private function getFollowersUrl() {
        return wfExpandUrl( '/' ) . 'api/activitypub/followers';
    }
}
