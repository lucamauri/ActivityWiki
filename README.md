# ActivityWiki

Integrate your MediaWiki instance with the Fediverse via ActivityPub.

## Overview

ActivityWiki is a MediaWiki extension that enables wiki instances to participate in the Fediverse by implementing the [ActivityPub](https://www.w3.org/TR/activitypub/) W3C protocol. Once complete, it will allow Mastodon users and other Fediverse participants to follow your wiki and receive notifications when pages are created, edited, or deleted — directly in their Fediverse timeline.

> ⚠️ **This extension is in early development.** The database layer and hook infrastructure are in place, but HTTP delivery to follower inboxes, the inbox endpoint, and HTTP Signatures are not yet implemented. The extension does not yet federate content to the Fediverse. See the Roadmap section below for the current status of each feature.

## Requirements

- MediaWiki 1.41 or later
- PHP 8.0 or later
- MariaDB / MySQL

## Installation

### 1. Clone the extension

```bash
cd /path/to/your/mediawiki/extensions
git clone https://github.com/lucamauri/ActivityWiki.git ActivityWiki
```

### 2. Add to LocalSettings.php

```php
wfLoadExtension( 'ActivityWiki' );
```

### 3. Run database setup

```bash
php maintenance/run.php update.php
```

## Configuration

All configuration variables are optional. The defaults are shown below.

```php
// Master switch — disable to pause all federation without uninstalling
$wgActivityWikiEnabled = true;

// Display name shown on Mastodon and other Fediverse clients
// Defaults to $wgSitename if not set
$wgActivityWikiActorName = null;

// The handle for the wiki actor (the part before @domain)
// Defaults to a slugified $wgSitename if not set
$wgActivityWikiActorUsername = null;

// Short bio shown on Mastodon profile
$wgActivityWikiActorSummary = '';

// Namespaces to federate — default is main namespace only
$wgActivityWikiPublishNamespaces = [ NS_MAIN ];

// Which event types to federate
$wgActivityWikiPublishCreations  = true;
$wgActivityWikiPublishEdits      = true;
$wgActivityWikiPublishDeletions  = true;
$wgActivityWikiPublishMoves      = true;
$wgActivityWikiPublishMinorEdits = false;  // Minor edits suppressed by default

// Maximum plain-text excerpt length in characters included in activities
$wgActivityWikiExcerptLength = 500;

// Debug logging (0 = off, 1 = verbose)
$wgActivityWikiDebugLevel = 0;
```

## Repository Structure

```
ActivityWiki/
├── extension.json                  # Extension metadata and service wiring
├── composer.json                   # PHP dependencies
├── README.md                       # This file
├── i18n/                           # Localisation files
│   ├── en.json
│   ├── it.json
│   └── qqq.json
├── src/
│   ├── Hooks.php                   # PageSaveComplete hook handler
│   ├── ActivityBuilder.php         # Builds ActivityPub activity arrays
│   ├── DeliveryQueue.php           # Persists activities and enqueues jobs
│   ├── Api/
│   │   └── ActivityPubModule.php   # Actor/outbox/followers data layer
│   ├── Jobs/
│   │   └── DeliveryJob.php         # Async delivery job (stub — Layer 3)
│   └── Rest/
│       ├── ActorHandler.php        # GET /activitywiki/actor
│       ├── OutboxHandler.php       # GET /activitywiki/outbox
│       ├── FollowersHandler.php    # GET /activitywiki/followers
│       └── routes.json             # REST route definitions
├── db/
│   └── activitywiki_activities.json  # Abstract schema for MW updater
└── maintenance/
    └── (maintenance scripts — added in Layer 5)
```

## Roadmap

### Phase 0 — Audit fixes ✅
- Fix all blockers and compatibility issues found in the initial audit
- Modernise deprecated MW API calls
- Rename all config keys to `ActivityWiki*` prefix

### Phase 1 — Identity (planned)
- Complete actor object endpoint
- Implement WebFinger endpoint for Fediverse discoverability

### Phase 2 — Security (planned)
- RSA key pair generation and storage
- HTTP Signature signing on all outbound requests

### Phase 3 — Publishing (planned)
- Full event hook coverage (create, edit, delete, move)
- Actual HTTP delivery to follower inboxes
- Outbox endpoint completion

### Phase 4 — Receiving (planned)
- Inbox endpoint
- Follow / Unfollow handling
- Followers collection completion

### Phase 5 — Administration (planned)
- Special:ActivityWiki status page
- MediaWiki log integration
- Maintenance scripts

### Post-MVP
- Per-user ActivityPub actors (`$wgActivityWikiEnableUserActors`)

## Troubleshooting

### Check the actor endpoint is reachable

```bash
curl https://your-wiki.example.com/w/rest.php/activitywiki/actor
```

Should return actor JSON, not a 404.

### Check debug logs

Set `$wgActivityWikiDebugLevel = 1;` in `LocalSettings.php`, then:

```bash
tail -f /path/to/mediawiki/logs/debug.log | grep -i ActivityWiki
```

## License

GPL-2.0-or-later. See LICENSE file for details.

## References

- [ActivityPub Specification](https://www.w3.org/TR/activitypub/)
- [MediaWiki Extension Development](https://www.mediawiki.org/wiki/Manual:Developing_extensions)
- [Extension:ActivityWiki on MediaWiki.org](https://www.mediawiki.org/wiki/Extension:ActivityWiki)

---

*Created for WikiTrek and the broader MediaWiki community.*