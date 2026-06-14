<?php
/**
 * ActivityPubModule — builds the JSON objects that represent this wiki
 * as an ActivityPub actor on the Fediverse.
 *
 * This class is responsible for assembling well-formed data structures.
 * It does NOT handle HTTP routing — that is done by the REST handlers
 * in src/Rest/.
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\ActivityWiki\Api;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ActivityWiki\KeyManager;

/**
 * Builds ActivityPub-compliant JSON objects for the wiki actor,
 * outbox, and followers collection.
 *
 * Instantiated once per request by the REST handlers, which inject
 * the MediaWiki Config service and the KeyManager service via the
 * constructor.
 */
class ActivityPubModule {

	/**
	 * The MediaWiki main configuration object.
	 * Injected via constructor to avoid repeated service-locator calls.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * The key management service.
	 * Provides access to the wiki's RSA public key for inclusion in the
	 * actor object. Injected via constructor for testability.
	 *
	 * @var KeyManager
	 */
	private KeyManager $keyManager;

	/**
	 * @param Config $config The MediaWiki main config (injected by REST handler)
	 * @param KeyManager $keyManager The key manager service (injected by REST handler)
	 */
	public function __construct( Config $config, KeyManager $keyManager ) {
		$this->config = $config;
		$this->keyManager = $keyManager;
	}

	// -------------------------------------------------------------------------
	// Public URL helpers
	// These are used both internally and by the REST handlers to build
	// self-referencing URLs consistently.
	// -------------------------------------------------------------------------

	/**
	 * Returns the base URL of the wiki (e.g. "https://wikitrek.org/wt").
	 * Combines $wgServer and $wgScriptPath.
	 *
	 * @return string
	 */
	public function getWikiBaseUrl(): string {
		// $wgServer includes scheme and host: "https://wikitrek.org"
		// $wgScriptPath is the subdirectory: "/wt" (may be empty string "")
		return $this->config->get( 'Server' )
			. $this->config->get( 'ScriptPath' );
	}

	/**
	 * Returns the canonical URL for the wiki's actor object.
	 * This is the stable identity URL used as the actor "id" in ActivityPub.
	 *
	 * Example: "https://wikitrek.org/wt/rest.php/activitywiki/actor"
	 *
	 * @return string
	 */
	public function getActorUrl(): string {
		return $this->getWikiBaseUrl() . '/rest.php/activitywiki/actor';
	}

	/**
	 * Returns the URL of the wiki's ActivityPub inbox.
	 * Incoming activities (Follow, Undo, etc.) are POST-ed here.
	 *
	 * Example: "https://wikitrek.org/wt/rest.php/activitywiki/inbox"
	 *
	 * @return string
	 */
	public function getInboxUrl(): string {
		return $this->getWikiBaseUrl() . '/rest.php/activitywiki/inbox';
	}

	/**
	 * Returns the URL of the wiki's ActivityPub outbox.
	 * Lists past activities published by this actor.
	 *
	 * Example: "https://wikitrek.org/wt/rest.php/activitywiki/outbox"
	 *
	 * @return string
	 */
	public function getOutboxUrl(): string {
		return $this->getWikiBaseUrl() . '/rest.php/activitywiki/outbox';
	}

	/**
	 * Returns the URL of the wiki's followers collection.
	 *
	 * Example: "https://wikitrek.org/wt/rest.php/activitywiki/followers"
	 *
	 * @return string
	 */
	public function getFollowersUrl(): string {
		return $this->getWikiBaseUrl() . '/rest.php/activitywiki/followers';
	}

	/**
	 * Returns the URL of the wiki's following collection.
	 *
	 * The ActivityPub spec (§4.1) lists "following" as a recommended field
	 * for all Actor types. This stub returns an empty OrderedCollection.
	 * Full implementation is deferred to Phase 4 (Layer 4 — Receiving).
	 *
	 * Example: "https://wikitrek.org/wt/rest.php/activitywiki/following"
	 *
	 * @return string
	 */
	public function getFollowingUrl(): string {
		return $this->getWikiBaseUrl() . '/rest.php/activitywiki/following';
	}

	/**
	 * Returns the actor's handle username (the part before the @ in
	 * @username@domain). Derived from $wgActivityWikiActorUsername if
	 * set, otherwise normalised from $wgSitename by lowercasing and
	 * stripping spaces.
	 *
	 * Example: "wikitrek" (for @wikitrek@wikitrek.org)
	 *
	 * @return string
	 */
	public function getActorUsername(): string {
		// If the operator has explicitly set a username, use it as-is.
		$explicit = $this->config->get( 'ActivityWikiActorUsername' );
		if ( $explicit !== '' ) {
			return $explicit;
		}

		// Otherwise derive a safe handle from the site name:
		// lowercase, spaces become hyphens, keep only alphanumeric and hyphens.
		// Cast to string: preg_replace() can return null on internal regex error.
		$sitename = $this->config->get( 'Sitename' );
		return (string)preg_replace(
			'/[^a-z0-9\-]/',
			'',
			strtolower( str_replace( ' ', '-', $sitename ) )
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Builds the ActivityPub Image object for the actor's icon (avatar).
	 *
	 * Uses $wgActivityWikiActorIcon if configured. Falls back to $wgFavicon,
	 * normalising it to an absolute URL if it is a relative path.
	 *
	 * Extracted from buildActorObject() as a private method for readability
	 * and testability (the inline IIFE pattern is unusual in MediaWiki code).
	 *
	 * @return array An ActivityPub Image object with "type" and "url" fields.
	 */
	private function buildIconObject(): array {
		$iconUrl = $this->config->get( 'ActivityWikiActorIcon' );

		if ( $iconUrl === '' ) {
			// No custom icon configured — fall back to the wiki's favicon.
			// $wgFavicon may be a relative path (e.g. "/favicon.ico") or
			// an absolute URL. Normalise to always be absolute.
			$favicon = $this->config->get( 'Favicon' );
			if ( strpos( $favicon, 'http' ) === 0 ) {
				// Already an absolute URL — use as-is.
				$iconUrl = $favicon;
			} else {
				// Relative path — prepend the server's scheme and host.
				$iconUrl = $this->config->get( 'Server' ) . $favicon;
			}
		}

		return [
			'type' => 'Image',
			'url'  => $iconUrl,
		];
	}

	// -------------------------------------------------------------------------
	// Actor object builder
	// -------------------------------------------------------------------------

	/**
	 * Builds the complete ActivityPub Actor object for this wiki.
	 *
	 * The actor represents the wiki as a whole on the Fediverse. Fediverse
	 * software (Mastodon, etc.) fetches this document to learn the wiki's
	 * display name, inbox URL, public key, and other identity information.
	 *
	 * The "publicKey.publicKeyPem" field is read live from the KeyManager
	 * service, which retrieves the PEM from the activitywiki_keys table.
	 * If no key has been generated yet (e.g. on a fresh install before
	 * update.php has run), the field falls back to an empty string, which
	 * is valid JSON but will cause Mastodon to reject incoming activities
	 * until a key is generated via maintenance/GenerateKeys.php or by
	 * running update.php.
	 *
	 * @return array The actor object as a PHP array, ready for json_encode()
	 */
	public function buildActorObject(): array {
		$actorUrl = $this->getActorUrl();

		// Display name shown on Mastodon profile cards.
		// Falls back to $wgSitename if not explicitly configured.
		$actorName = $this->config->get( 'ActivityWikiActorName' );
		if ( $actorName === '' ) {
			$actorName = $this->config->get( 'Sitename' );
		}

		// Short bio shown under the display name on Mastodon.
		// Empty string is valid — the operator may not want a summary.
		$summary = $this->config->get( 'ActivityWikiActorSummary' );

		// Read the public key PEM from the database via KeyManager.
		// Falls back to empty string if no key has been generated yet.
		// An empty publicKeyPem is valid JSON but will cause Mastodon to
		// reject our signed requests — run update.php or GenerateKeys.php
		// to ensure a key is always present after installation.
		$publicKeyPem = $this->keyManager->getPublicKeyPem() ?? '';

		return [
			// JSON-LD context — required by the ActivityPub spec.
			'@context' => [
				'https://www.w3.org/ns/activitystreams',
				'https://w3id.org/security/v1',
			],

			// The canonical URL of this actor — its permanent identity.
			'id'   => $actorUrl,

			// "Application" is the correct type for a non-human actor
			// such as a bot or a service. A wiki is not a Person.
			'type' => 'Application',

			// preferredUsername is used by Mastodon to construct the
			// @username@domain handle shown in the UI.
			'preferredUsername' => $this->getActorUsername(),

			// Human-readable display name.
			'name' => $actorName,

			// Short bio / description, shown on profile cards.
			// HTML is technically allowed by ActivityPub but plain text
			// is safer and more widely supported.
			'summary' => $summary,

			// The wiki's main page URL — shown as the profile link.
			'url' => $this->getWikiBaseUrl() . '/index.php',

			// Inbox: where other Fediverse servers POST activities to us.
			// (Follow requests, Undo, etc.)
			'inbox' => $this->getInboxUrl(),

			// Outbox: where our published activities can be read.
			'outbox' => $this->getOutboxUrl(),

			// Followers: the collection of actors following this wiki.
			'followers' => $this->getFollowersUrl(),

			// Following: the collection of actors this wiki follows.
			// Recommended by ActivityPub spec §4.1. Stub for now —
			// @todo Phase 4: populate with real following data if needed.
			'following' => $this->getFollowingUrl(),

			// Actor icon / avatar shown on Mastodon profile cards.
			// Falls back to $wgFavicon if no custom icon is configured.
			'icon' => $this->buildIconObject(),

			// Public key used to verify HTTP Signatures on our outgoing
			// activities. The PEM is read live from the activitywiki_keys
			// table via KeyManager. Empty string fallback is safe but means
			// Mastodon will reject our activities until a key is generated.
			'publicKey' => [
				'id'           => $actorUrl . '#main-key',
				'owner'        => $actorUrl,
				'publicKeyPem' => $publicKeyPem,
			],
		];
	}

	// -------------------------------------------------------------------------
	// Outbox, followers, and following stubs
	// These return spec-compliant empty collections.
	// They will be fully implemented in Layer 3 and Layer 4 respectively.
	// -------------------------------------------------------------------------

	/**
	 * Builds an empty ActivityPub OrderedCollection for the outbox.
	 *
	 * The outbox lists activities this actor has published (page creations,
	 * edits, deletions). Pagination and real content will be added in
	 * Phase 3 (Layer 3 — Publishing).
	 *
	 * @return array
	 */
	public function buildOutboxObject(): array {
		return [
			'@context'     => 'https://www.w3.org/ns/activitystreams',
			'id'           => $this->getOutboxUrl(),
			'type'         => 'OrderedCollection',
			// @todo Phase 3: replace with real count from activitywiki_activities table.
			'totalItems'   => 0,
			'orderedItems' => [],
		];
	}

	/**
	 * Builds an empty ActivityPub OrderedCollection for the followers list.
	 *
	 * The followers collection lists actors that follow this wiki.
	 * Real content will be added in Phase 4 (Layer 4 — Receiving),
	 * once the inbox and Follow handler are implemented.
	 *
	 * @return array
	 */
	public function buildFollowersObject(): array {
		return [
			'@context'     => 'https://www.w3.org/ns/activitystreams',
			'id'           => $this->getFollowersUrl(),
			'type'         => 'OrderedCollection',
			// @todo Phase 4: replace with real count from activitywiki_followers table.
			'totalItems'   => 0,
			'orderedItems' => [],
		];
	}

	/**
	 * Builds an empty ActivityPub OrderedCollection for the following list.
	 *
	 * Wiki-level following is not a planned feature (wikis do not follow
	 * other actors). This stub satisfies the ActivityPub spec §4.1 requirement
	 * to expose a "following" URL without exposing any data.
	 *
	 * @return array
	 */
	public function buildFollowingObject(): array {
		return [
			'@context'     => 'https://www.w3.org/ns/activitystreams',
			'id'           => $this->getFollowingUrl(),
			'type'         => 'OrderedCollection',
			'totalItems'   => 0,
			'orderedItems' => [],
		];
	}
}