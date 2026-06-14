-- activitywiki_keys — stores the RSA key pair used by ActivityWiki to sign
-- outbound HTTP requests via HTTP Signatures (RFC 7235 / ActivityPub §B.1).
--
-- Only one active row is expected at any time. Key rotation replaces
-- this row via the maintenance/GenerateKeys.php script.
--
-- This file is used by MediaWiki's update.php (addExtensionTable) to create
-- the table on first install. The abstract JSON schema (activitywiki_keys.json)
-- is kept alongside for documentation and future tooling compatibility.

CREATE TABLE IF NOT EXISTS /*_*/activitywiki_keys (
    -- Auto-incremented surrogate primary key. Only one row will exist in
    -- practice, but a PK is required by MediaWiki schema conventions.
    key_id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

    -- PEM-encoded RSA public key. Exposed publicly via the ActivityPub
    -- actor object so that remote servers can verify HTTP Signatures.
    public_key_pem    BLOB         NOT NULL,

    -- PEM-encoded RSA private key. Never exposed publicly. Used internally
    -- by HttpSigner to sign outbound HTTP POST requests to follower inboxes.
    private_key_pem   BLOB         NOT NULL,

    -- RSA key size in bits at time of generation. Recorded for auditing
    -- (e.g. to flag keys below the recommended 4096-bit threshold).
    key_size          INT UNSIGNED NOT NULL,

    -- UTC timestamp when this key pair was generated. Used for auditing
    -- and to surface key age in the Special:ActivityWiki status page.
    generated_at      VARBINARY(14) NOT NULL
) /*$wgDBTableOptions*/;