<?php
namespace MediawikiActivityPub\Jobs;

use Job;
use MediaWiki\Http\HttpRequestFactory;

class DeliveryJob extends Job {
    
    public function __construct( $params ) {
        parent::__construct( 'MediawikiActivityPubDelivery', $params );
    }
    
    public function run() {
        $activityId = $this->params['activityId'];
        
        // Get activity from DB
        $db = wfGetDB( DB_REPLICA );
        $row = $db->selectRow(
            'activitypub_activities',
            [ 'activity_json', 'activity_type' ],
            [ 'activity_id' => $activityId ]
        );
        
        if ( !$row ) {
            return false;
        }
        
        $activity = json_decode( $row->activity_json, true );
        
        // For MVP: just log that we would send this
        // Later: actually POST to follower inboxes
        
        wfDebugLog( 'activitypub', 'Queued activity: ' . json_encode( $activity ) );
        
        // Mark as sent
        $db = wfGetDB( DB_PRIMARY );
        $db->update(
            'activitypub_activities',
            [ 'published' => 1 ],
            [ 'activity_id' => $activityId ]
        );
        
        return true;
    }
}
