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

### WebFinger routing

The WebFinger protocol (RFC 7033) requires that `/.well-known/webfinger` be
served at the root of your domain. MediaWiki does not control the document root,
so two installation steps are required.

#### Why a separate entry-point file is needed

MediaWiki's REST router validates that every incoming `REQUEST_URI` starts with
the REST base path (e.g. `/wt/rest.php`). A plain Apache rewrite preserves the
original `REQUEST_URI`, causing the router to reject the request with a
`rest-prefix-mismatch` error — even though the route and handler are correctly
registered.

This is a MediaWiki-specific constraint. Other frameworks (WordPress, XWiki)
handle `.well-known` routing transparently because their routers do not validate
`REQUEST_URI`. The solution is a thin entry-point file (`webfinger.php`) that
rewrites `REQUEST_URI` before MediaWiki boots, then delegates entirely to
`rest.php`. It contains no business logic — all WebFinger handling remains in
`WebFingerHandler.php`.

> **Future improvement:** A MediaWiki core patch to accept configurable base
> path prefixes would make `webfinger.php` unnecessary. This is worth
> contributing upstream separately.

---

#### Step 1 — Copy the entry-point file

Copy `entry-points/webfinger.php` from the extension to your MediaWiki script
directory (the same directory that contains `rest.php`):

```bash
cp extensions/ActivityWiki/entry-points/webfinger.php /var/www/mw/wt/webfinger.php
```

Then open the file and update the hardcoded path on line 47 to match your
`$wgScriptPath`. For example:

```php
// If your $wgScriptPath is /wiki, change /wt/ to /wiki/
$_SERVER['REQUEST_URI'] = '/wt/rest.php/activitywiki/webfinger' ...
```

---

#### Step 2 — Add the Apache rewrite rule

Add the following rule to your VirtualHost configuration **before** the existing
MediaWiki rewrite rules. Use `%{DOCUMENT_ROOT}` so the path resolves correctly
regardless of the Apache working directory:

```apache
# ActivityWiki — WebFinger discovery endpoint
# Replace /wt/ with your $wgScriptPath
RewriteRule ^/\.well-known/webfinger$ %{DOCUMENT_ROOT}/wt/webfinger.php [QSA,L]
```

The `QSA` flag (Query String Append) ensures the `?resource=acct:...` parameter
is passed through. The `L` flag stops further rule processing.

##### Nginx equivalent

```nginx
# ActivityWiki — WebFinger discovery endpoint
# Replace /wt/ with your $wgScriptPath
location = /.well-known/webfinger {
    fastcgi_param REQUEST_URI /wt/rest.php/activitywiki/webfinger;
    fastcgi_param QUERY_STRING $query_string;
    # ... your existing fastcgi_pass settings
}
```

##### Behind a reverse proxy (Anubis, Varnish, etc.)

If your setup uses a reverse proxy in front of Apache, ensure that
`/.well-known/` paths are passed through to MediaWiki without interception.
Most proxy configurations already do this for Let's Encrypt compatibility.
Check your proxy's bot policy or passthrough rules if WebFinger returns an
unexpected response.

---

#### Verifying the setup

After reloading Apache, test with curl:

```bash
curl -s "https://yourwiki.example.org/.well-known/webfinger?resource=acct:yourhandle@yourwiki.example.org" \
  | python3 -m json.tool
```

A correct response looks like this:

```json
{
    "subject": "acct:wikitrek@wikitrek.org",
    "aliases": [
        "https://wikitrek.org/wt/rest.php/activitywiki/actor"
    ],
    "links": [
        {
            "rel": "self",
            "type": "application/activity+json",
            "href": "https://wikitrek.org/wt/rest.php/activitywiki/actor"
        },
        {
            "rel": "http://webfinger.net/rel/profile-page",
            "type": "text/html",
            "href": "https://wikitrek.org/wt/index.php"
        }
    ]
}
```

An invalid resource should return a 404 with a JSON error body:

```bash
curl -s "https://yourwiki.example.org/.well-known/webfinger?resource=acct:nobody@yourwiki.example.org"
# {"error":"Resource not found on this server."}
```

##### Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Empty response or Apache 404 | Rewrite rule not applied or in wrong VirtualHost | Verify the rule is inside the VirtualHost block that receives public HTTPS traffic |
| `rest-prefix-mismatch` JSON error | `webfinger.php` not in place or wrong path inside it | Check Step 1; verify `$wgScriptPath` matches the hardcoded path in `webfinger.php` |
| 403 from Apache | Proxy forwarding with wrong `Host` header | Check proxy passthrough config for `/.well-known/` |
| MediaWiki 404 (JSON, with `X-Request-Id`) | Route not registered | Verify `routes.json` contains the webfinger route; check extension is enabled |

#### Apache

Add the following rule to your `.htaccess` or VirtualHost configuration, **before**
the existing MediaWiki rewrite rules:

```apache
# ActivityWiki — WebFinger discovery endpoint
# Replace /wt/ with your $wgScriptPath (may be empty, e.g. just /rest.php/...)
RewriteRule ^\.well-known/webfinger$ /wt/rest.php/activitywiki/webfinger [QSA,L]
```

The `QSA` flag (Query String Append) ensures the `?resource=acct:...` parameter
is passed through to MediaWiki. The `L` flag stops processing further rules.

#### Nginx

Add the following block to your server configuration:

```nginx
# ActivityWiki — WebFinger discovery endpoint
location = /.well-known/webfinger {
    # Replace /wt/ with your $wgScriptPath
    rewrite ^ /wt/rest.php/activitywiki/webfinger last;
}
```

#### Verifying the setup

Once the rewrite rule is in place, test it with curl:

```bash
curl -s "https://yourwiki.example.org/.well-known/webfinger?resource=acct:yourhandle@yourwiki.example.org" | python3 -m json.tool
```

A correct response looks like this:

```json
{
    "subject": "acct:wikitrek@wikitrek.org",
    "aliases": [
        "https://wikitrek.org/wt/rest.php/activitywiki/actor"
    ],
    "links": [
        {
            "rel": "self",
            "type": "application/activity+json",
            "href": "https://wikitrek.org/wt/rest.php/activitywiki/actor"
        },
        {
            "rel": "http://webfinger.net/rel/profile-page",
            "type": "text/html",
            "href": "https://wikitrek.org/wt/index.php"
        }
    ]
}
```

If you get a 404 from Apache/Nginx (not from MediaWiki), the rewrite rule is not
being applied. If you get a 404 from MediaWiki, the resource parameter does not
match your configured actor username or domain.

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

### Phase 1 — Identity ✅
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