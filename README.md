# ActivityWiki

Integrate your MediaWiki instance with the Fediverse via ActivityPub.

## Overview

ActivityWiki is a MediaWiki extension that enables wiki instances to participate in the Fediverse by implementing the [ActivityPub](https://www.w3.org/TR/activitypub/) W3C protocol. It allows Mastodon users and other Fediverse participants to follow your wiki and receive notifications when pages are created, edited, or deleted — directly in their Fediverse timeline.

> ℹ️ **Current status: Phase 4 (Receiving) in progress.** Identity (actor + WebFinger), Security (HTTP Signatures), and Publishing (outbound delivery to followers) are complete and working. The inbox endpoint (handling incoming `Follow`/`Undo` requests) has been built and is being tested. See the Roadmap section below for full details.

## Requirements

- MediaWiki 1.41 or later
- PHP 8.0 or later
- MariaDB / MySQL
- Apache or Nginx with the ability to add a rewrite rule (see [WebFinger routing](#webfinger-routing) below — this is required, not optional)

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

### 4. Set up WebFinger routing

**This step is required.** Without it, the wiki is completely invisible to
Fediverse search, even though every other endpoint works correctly. See
[WebFinger routing](#webfinger-routing) below for full instructions.

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

// Absolute URL to the wiki logo used as the actor avatar
// Defaults to $wgFavicon if not set
$wgActivityWikiActorIcon = null;

// RSA key size in bits for HTTP Signature key pair generation
// Minimum 2048, recommended 4096
$wgActivityWikiKeySize = 2048;

// Namespaces to federate — default is main namespace only
$wgActivityWikiPublishNamespaces = [ NS_MAIN ];

// Which event types to federate
$wgActivityWikiPublishCreations   = true;
$wgActivityWikiPublishEdits       = true;
$wgActivityWikiPublishDeletions   = true;
$wgActivityWikiPublishMoves       = true;
$wgActivityWikiPublishMinorEdits  = false; // Minor edits suppressed by default
$wgActivityWikiPublishProtections = false;

// Maximum plain-text excerpt length in characters included in activities
$wgActivityWikiExcerptLength = 500;

// Number of retry attempts for failed HTTP deliveries (outbound activities
// and Accept replies alike)
$wgActivityWikiDeliveryRetries = 3;

// Enable per-user ActivityPub actors. Post-MVP feature — do not enable
// in production; not yet implemented.
$wgActivityWikiEnableUserActors = false;

// Debug logging (0 = off, 1 = verbose)
$wgActivityWikiDebugLevel = 0;
```

To see ActivityWiki's debug log output, add a dedicated log channel in
`LocalSettings.php`:

```php
$wgDebugLogGroups['ActivityWiki'] = '/path/to/your/logs/ActivityWiki.log';
```

## WebFinger routing

The WebFinger protocol (RFC 7033) requires that requests be served from a
fixed, well-known path at the **root of your domain**:

```
https://yourdomain.org/.well-known/webfinger?resource=acct:user@yourdomain.org
```

This path is outside MediaWiki's normal URL space, and MediaWiki cannot
serve it on its own. Setting this up correctly requires two things: a small
entry-point script, and a web-server rewrite rule pointing to it. Both are
described below.

> Throughout this section, replace `/path/to/your/wiki/` with wherever your
> MediaWiki installation actually lives (the directory containing
> `rest.php`), and replace `/your-script-path/` with your own
> `$wgScriptPath` (e.g. `/w`, `/wiki`, or empty if MediaWiki is installed at
> your domain root). These vary per installation — there is no universal
> default.

### Why a separate entry-point file is needed

MediaWiki's REST router validates that every incoming `REQUEST_URI` starts
with the REST base path (e.g. `/your-script-path/rest.php`). A plain
web-server-level rewrite from `/.well-known/webfinger` straight to the REST
path is **not enough on its own** — the rewrite happens after PHP has
already read `$_SERVER['REQUEST_URI']`, so MediaWiki's router still sees the
original, unrewritten URI and rejects the request with a
`rest-prefix-mismatch` error, even though the route and handler
(`WebFingerHandler`) are correctly registered.

The fix is `entry-points/webfinger.php`, included with this extension. It
corrects `$_SERVER['REQUEST_URI']` itself *before* MediaWiki boots, then
hands off entirely to `rest.php`. It contains no business logic of its own —
all actual WebFinger handling still happens in `WebFingerHandler.php`.

> **Future improvement:** A MediaWiki core patch to accept configurable REST
> base path validation would make this entry-point file unnecessary. Worth
> contributing upstream separately.

### Step 1 — Copy the entry-point file and set your script path

Copy `entry-points/webfinger.php` from the extension to the same directory
as `rest.php`:

```bash
cp extensions/ActivityWiki/entry-points/webfinger.php /path/to/your/wiki/webfinger.php
```

Open the copied file and confirm the rewritten path matches your
`$wgScriptPath`:

```php
$_SERVER['REQUEST_URI'] = '/your-script-path/rest.php/activitywiki/webfinger'
    . ( /* ...query string handling... */ );
```

Replace `/your-script-path/` with your actual `$wgScriptPath` (for example,
if `$wgScriptPath = '/w'`, this line should read
`'/w/rest.php/activitywiki/webfinger'`).

### Step 2 — Add the web-server rewrite rule

#### Apache

Add the following rule inside the `<VirtualHost>` block that serves your
wiki, **before** any existing MediaWiki rewrite rules:

```apache
# ActivityWiki — WebFinger discovery endpoint
RewriteRule ^\.well-known/webfinger$ %{DOCUMENT_ROOT}/webfinger.php [QSA,L]
```

> ⚠️ **Do not anchor the pattern with a leading `/`.** Inside a
> `<VirtualHost>` block (as opposed to an `.htaccess` file), Apache's
> `RewriteRule` matches the URL path **without** a leading slash. A pattern
> written as `^/\.well-known/webfinger$` will never match — Apache will
> silently fall through to its normal file-serving logic and return a
> generic Apache 404 page, with nothing logged anywhere that points to the
> actual cause. If `%{DOCUMENT_ROOT}/webfinger.php` is not where you copied
> the file in Step 1 (for example, if your `DocumentRoot` is a parent
> directory of your wiki installation rather than the installation
> directory itself), adjust the target path accordingly.

The `QSA` flag (Query String Append) passes the `?resource=...` parameter
through. The `L` flag stops further rule processing for this request.

#### Nginx

Add the following to your `server` block:

```nginx
# ActivityWiki — WebFinger discovery endpoint
location = /.well-known/webfinger {
    fastcgi_param REQUEST_URI /your-script-path/rest.php/activitywiki/webfinger;
    fastcgi_param QUERY_STRING $query_string;
    # ... your existing fastcgi_pass and other fastcgi_param settings
}
```

Replace `/your-script-path/` with your `$wgScriptPath`, matching Step 1.
This approach rewrites `REQUEST_URI` directly via FastCGI parameters, so the
`entry-points/webfinger.php` file is not needed for the Nginx path.

#### Behind a reverse proxy (Anubis, Varnish, Cloudflare, etc.)

If your setup uses a reverse proxy in front of your web server, make sure
`/.well-known/` paths are passed through without being blocked or
challenged. Most proxy configurations already allow this for Let's Encrypt
compatibility, but bot-detection layers (e.g. Anubis) can sometimes
intercept paths that look unusual. If WebFinger works when tested directly
against your web server but not from the public internet, check your
proxy's passthrough or allow-list rules for `/.well-known/`.

### Step 3 — Reload your web server

```bash
sudo systemctl reload apache2   # or: sudo systemctl reload nginx
```

### Step 4 — Verify

Request the well-known URL directly:

```bash
curl -v "https://yourdomain.org/.well-known/webfinger?resource=acct:yourusername@yourdomain.org"
```

A **correct** response is `200 OK`, with `Content-Type:
application/jrd+json`, and a JSON body shaped like:

```json
{
    "subject": "acct:yourusername@yourdomain.org",
    "aliases": [
        "https://yourdomain.org/your-script-path/rest.php/activitywiki/actor"
    ],
    "links": [
        {
            "rel": "self",
            "type": "application/activity+json",
            "href": "https://yourdomain.org/your-script-path/rest.php/activitywiki/actor"
        },
        {
            "rel": "http://webfinger.net/rel/profile-page",
            "type": "text/html",
            "href": "https://yourdomain.org/your-script-path/index.php"
        }
    ]
}
```

An unrecognized resource correctly returns a 404 with a small JSON error
body (e.g. `{"error":"Resource not found on this server."}`) — that's
expected and fine.

#### Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `404 Not Found`, `Content-Type: text/html`, Apache/Nginx server signature in the body | Rewrite rule isn't matching — most commonly the leading-slash mistake described above | Check the `RewriteRule`/`location` pattern; confirm it's in the `<VirtualHost>` block that actually serves your public HTTPS traffic |
| `500`, JSON body mentioning `rest-prefix-mismatch` | `entry-points/webfinger.php` missing, in the wrong location, or its hardcoded script path doesn't match `$wgScriptPath` | Re-check Step 1 |
| `500`, JSON body mentioning `ArgumentCountError` or similar | `routes.json`'s `webfinger` route is missing a required service, or extension files are out of date | Confirm you're running the current version of the extension; check `routes.json` lists all services `WebFingerHandler`'s constructor requires |
| Works when curled directly against the server but not from the public internet | A reverse proxy is intercepting or challenging the request | Check your proxy's passthrough rules for `/.well-known/` |
| `404` with a small JSON body (not HTML) | This is MediaWiki's own "resource not found" response | Check that the `resource` parameter's username and domain match your actual `$wgActivityWikiActorUsername` and `$wgServer` |

## Repository Structure

```
ActivityWiki/
├── extension.json              # Extension metadata, config, and service/job wiring
├── composer.json                # PHP dependencies
├── README.md                    # This file
├── entry-points/
│   └── webfinger.php            # Required manual install step — see WebFinger routing
├── i18n/                         # Localisation files
├── src/
│   ├── ServiceWiring.php        # Registers all ActivityWiki services
│   ├── Hooks.php                 # Page save/edit/delete event hooks
│   ├── ActivityBuilder.php       # Builds outbound ActivityPub activity arrays
│   ├── DeliveryQueue.php         # Persists outbound activities, enqueues delivery jobs
│   ├── KeyManager.php            # RSA key pair generation and storage
│   ├── HttpSigner.php            # Signs outbound HTTP requests
│   ├── SignatureVerifier.php     # Verifies incoming HTTP Signatures
│   ├── FollowManager.php         # Follow/Undo business logic for the inbox
│   ├── Api/
│   │   └── ActivityPubModule.php # Shared actor/URL-building helpers
│   ├── Jobs/
│   │   ├── DeliveryJob.php       # Async fan-out delivery to all followers
│   │   └── AcceptJob.php         # Async single-recipient delivery (Accept replies)
│   └── Rest/
│       ├── ActorHandler.php      # GET  /activitywiki/actor
│       ├── WebFingerHandler.php  # GET  /activitywiki/webfinger
│       ├── OutboxHandler.php     # GET  /activitywiki/outbox
│       ├── FollowersHandler.php  # GET  /activitywiki/followers
│       ├── InboxHandler.php      # POST /activitywiki/inbox
│       └── routes.json           # REST route definitions
├── db/                            # Database schema files
└── maintenance/                   # Maintenance scripts (Phase 5)
```

## Roadmap

### Phase 0 — Audit fixes ✅ Complete
- Fixed all blockers and compatibility issues found in the initial audit
- Modernised deprecated MW API calls
- Renamed all config keys to the `ActivityWiki*` prefix

### Phase 1 — Identity ✅ Complete
- Actor object endpoint
- WebFinger endpoint for Fediverse discoverability

### Phase 2 — Security ✅ Complete
- RSA key pair generation and storage
- HTTP Signature signing on all outbound requests

### Phase 3 — Publishing ✅ Complete
- Full event hook coverage (create, edit, delete, move)
- Async HTTP delivery to follower inboxes, with per-follower retry
- Outbox endpoint completion

### Phase 4 — Receiving ⏳ In progress
- Inbox endpoint
- `Follow` / `Undo{Follow}` handling
- Followers collection completion (still serving a stub)

### Phase 5 — Administration (planned)
- `Special:ActivityWiki` status page
- MediaWiki log integration
- Maintenance scripts

### Post-MVP (deferred, not scoped)
- Per-user ActivityPub actors (`$wgActivityWikiEnableUserActors`)
- Extended inbox activity handling (`Like`, `Announce`, reply mentions)

## Troubleshooting

### Check the actor endpoint is reachable

```bash
curl -H "Accept: application/activity+json" "https://yourdomain.org/your-script-path/rest.php/activitywiki/actor"
```

Should return actor JSON, not a 404.

### Check WebFinger is reachable at the public well-known path

```bash
curl -v "https://yourdomain.org/.well-known/webfinger?resource=acct:yourusername@yourdomain.org"
```

See the [WebFinger routing](#webfinger-routing) troubleshooting table above
if this doesn't return a correct JSON response.

### Check debug logs

Set `$wgActivityWikiDebugLevel = 1;` and configure a log channel (see
Configuration above), then:

```bash
tail -f /path/to/your/logs/ActivityWiki.log
```

## License

GPL-2.0-or-later. See LICENSE file for details.

## References

- [ActivityPub Specification](https://www.w3.org/TR/activitypub/)
- [WebFinger (RFC 7033)](https://www.rfc-editor.org/rfc/rfc7033)
- [MediaWiki Extension Development](https://www.mediawiki.org/wiki/Manual:Developing_extensions)

---

In the spirit of IDIC 🖖🏻 forged at [WikiTrek](https://wikitrek.org), shared freely with the Galaxy