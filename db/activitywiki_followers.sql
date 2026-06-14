-- activitywiki_followers — stores the list of Fediverse actors that follow
-- this wiki via the ActivityPub protocol.
--
-- A row is inserted when a Follow activity is received and accepted (Phase 4).
-- A row is removed when an Undo{Follow} activity is received (Phase 4).
-- This table is read by DeliveryJob (Phase 3) to determine the set of
-- inboxes to POST each outbound activity to.
--
-- This file is used by MediaWiki's update.php (addExtensionTable) to create
-- the table on first install.

CREATE TABLE IF NOT EXISTS /*_*/activitywiki_followers (

    -- Auto-incremented surrogate primary key.
    af_id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

    -- The follower's canonical actor URL, e.g.:
    --   https://mastodon.social/users/someone
    -- This is the globally unique identifier for the follower in the
    -- Fediverse. Must be unique — the same actor cannot follow twice.
    af_actor_url        VARCHAR(512) NOT NULL,

    -- The follower's individual inbox URL, e.g.:
    --   https://mastodon.social/users/someone/inbox
    -- DeliveryJob POSTs signed activities to this URL.
    af_inbox_url        VARCHAR(512) NOT NULL,

    -- The follower's server shared inbox URL, e.g.:
    --   https://mastodon.social/inbox
    -- Optional — not all Fediverse servers expose a shared inbox.
    -- Stored now for future delivery optimisation (Phase 3+): if present,
    -- DeliveryJob can POST once per server instead of once per follower.
    af_shared_inbox_url VARCHAR(512) NULL DEFAULT NULL,

    -- UTC timestamp when this follow was accepted, in MediaWiki's standard
    -- VARBINARY(14) format: YYYYMMDDHHmmss.
    af_followed_at      VARBINARY(14) NOT NULL

) /*$wgDBTableOptions*/;

-- Unique index on actor URL — prevents duplicate follow entries for the
-- same remote actor. Also used by Phase 4 Undo{Follow} handler to locate
-- the row to delete.
CREATE UNIQUE INDEX /*i*/activitywiki_followers_actor
    ON /*_*/activitywiki_followers (af_actor_url);

-- Index on inbox URL — used by DeliveryJob when grouping deliveries
-- by shared inbox (future optimisation).
CREATE INDEX /*i*/activitywiki_followers_inbox
    ON /*_*/activitywiki_followers (af_inbox_url);