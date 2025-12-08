# ActivityWiki

Integrate your MediaWiki instance with the Fediverse via ActivityPub.

## Overview

ActivityWiki is a MediaWiki extension that enables wiki instances to participate in the Fediverse by implementing the ActivityPub protocol. This allows:

- **Share wiki activity**: Article creation and modifications are broadcast to the Fediverse
- **User attribution**: Track which user made edits and attribute contributions accurately
- **Discoverability**: Make your wiki discoverable to Mastodon, Pixelfed, and other ActivityPub-compatible platforms
- **Federation**: Connect your wiki with other federated services

## Features

### Current (Phase 1)
- ✅ Wiki actor profile (ActivityPub compatible)
- ✅ Activity creation for page edits (Create/Update)
- ✅ REST API endpoints for ActivityPub discovery
- ✅ Activity logging and storage
- ✅ Configuration management

### Planned (Phase 2+)
- 🔄 HTTP signature delivery to follower inboxes
- 🔄 Receive Follow/Unfollow requests
- 🔄 Per-user ActivityPub actor profiles
- 🔄 Inbox endpoint for incoming activities

## Requirements

- MediaWiki 1.35 or later
- PHP 7.2 or later
- Database: MySQL/MariaDB or PostgreSQL
- HTTP access to external Fediverse servers (for Phase 2+)

## Installation

### 1. Clone the extension

```bash
cd /path/to/your/mediawiki/extensions
git clone https://github.com/lucamauri/ActivityWiki.git ActivityWiki
cd ActivityWiki
```

### 2. Add to LocalSettings.php

```php
// Enable ActivityWiki extension
wfLoadExtension( 'ActivityWiki' );

// Configuration (optional)
$wgActivityPubEnabled = true;
$wgActivityPubActorName = 'MyWiki';  // Display name in Fediverse
$wgActivityPubEnableUserActors = false;  // Per-user actors (Phase 3)
```

### 3. Run database setup

```bash
php maintenance/run.php update.php
```

This creates the necessary database tables.

### 4. Verify installation

Visit: `https://your-wiki.example.com/api/rest_v1/activitypub/actor`

You should see a JSON ActivityPub actor profile.

## Configuration

### Basic Settings (LocalSettings.php)

```php
// Enable/disable the extension
$wgActivityPubEnabled = true;

// How the wiki appears in the Fediverse
$wgActivityPubActorName = 'My Wiki';

// Optional: Enable per-user ActivityPub actors
$wgActivityPubEnableUserActors = false;

// Optional: Exclude certain namespaces from federation
$wgActivityPubExcludedNamespaces = [ NS_TEMPLATE, NS_CATEGORY ];

// Optional: Exclude bot edits from ActivityPub feed
$wgActivityPubExcludeBots = true;

// Optional: Exclude minor edits
$wgActivityPubExcludeMinor = false;
```

## API Endpoints

Once installed, the following ActivityPub endpoints become available:

### Actor Profile
```
GET /api/rest_v1/activitypub/actor
```
Returns the wiki's ActivityPub actor profile (Service type).

**Response:**
```json
{
  "@context": "https://www.w3.org/ns/activitystreams",
  "id": "https://your-wiki.example.com/api/rest_v1/activitypub/actor",
  "type": "Service",
  "name": "My Wiki",
  "preferredUsername": "mywiki",
  "inbox": "https://your-wiki.example.com/api/rest_v1/activitypub/inbox",
  "outbox": "https://your-wiki.example.com/api/rest_v1/activitypub/outbox",
  "followers": "https://your-wiki.example.com/api/rest_v1/activitypub/followers",
  "publicKey": { ... },
  "summary": "The My Wiki wiki"
}
```

### Activity Outbox
```
GET /api/rest_v1/activitypub/outbox?limit=10&page=1
```
Returns paginated list of activities (Create/Update).

### Followers
```
GET /api/rest_v1/activitypub/followers
```
Returns list of Fediverse accounts following your wiki.

## Usage

### Following Your Wiki

1. Open your Fediverse client (Mastodon, Pixelfed, etc.)
2. Search for: `@yourwikiname@your-wiki.example.com`
3. Click Follow
4. When users edit articles, activities appear in your Fediverse feed

### Example Activity

When a user edits an article, an Activity is created:

```json
{
  "@context": "https://www.w3.org/ns/activitystreams",
  "type": "Create",
  "actor": "https://your-wiki.example.com/api/rest_v1/activitypub/actor",
  "object": {
    "type": "Article",
    "name": "Example Article",
    "url": "https://your-wiki.example.com/wiki/Example_Article",
    "content": "Article content...",
    "attributedTo": "https://your-wiki.example.com/api/rest_v1/activitypub/actor"
  },
  "published": "2025-12-08T22:17:00Z"
}
```

## Development

### Repository Structure

```
ActivityWiki/
├── extension.json           # Extension metadata
├── README.md               # This file
├── includes/
│   ├── Hooks.php           # MediaWiki hook handlers
│   ├── ActivityBuilder.php  # Build ActivityPub JSON
│   ├── DeliveryQueue.php    # Queue activities
│   ├── Api/
│   │   └── ActivityPubModule.php  # REST endpoints
│   └── Jobs/
│       └── DeliveryJob.php  # Async delivery job
├── db/
│   └── tables.sql          # Database schema
└── tests/
    └── phpunit/            # Unit tests
```

### Running Tests

```bash
cd /path/to/mediawiki
php tests/phpunit/phpunit.php extensions/ActivityWiki/tests
```

### Code Style

This extension follows MediaWiki coding standards:
- PSR-12 for PHP
- 4-space indentation
- No trailing whitespace

### Contributing

We welcome contributions! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Commit changes (`git commit -m 'Add my feature'`)
4. Push to the branch (`git push origin feature/my-feature`)
5. Open a Pull Request

Please ensure:
- Code follows MediaWiki standards
- Tests pass
- Commits are descriptive

See [CONTRIBUTING.md](CONTRIBUTING.md) for detailed guidelines.

## Roadmap

### Phase 1: Activity Broadcasting (Current)
- [x] Hook into page saves
- [x] Build ActivityPub activities
- [x] Store activities in database
- [x] Expose REST API endpoints
- [ ] Testing on real wiki

### Phase 2: HTTP Delivery
- [ ] HTTP signature implementation
- [ ] POST activities to follower inboxes
- [ ] Retry logic for failed deliveries
- [ ] Request queuing and rate limiting

### Phase 3: Per-User Actors
- [ ] User profile endpoints
- [ ] Per-user activity attribution
- [ ] User preferences for federation

### Phase 4: Inbox & Interactions
- [ ] POST /inbox endpoint
- [ ] Handle Follow/Unfollow requests
- [ ] Track followers
- [ ] (Future: Handle replies, likes, etc.)

## Security Considerations

- **HTTP Signatures**: Phase 2 will implement RFC 8017 to sign outgoing requests
- **Content Sanitization**: Page content is sanitized before inclusion in activities
- **Rate Limiting**: Configuration options prevent spamming followers
- **Private Key Storage**: Private keys stored securely in LocalSettings.php
- **Access Control**: Only public wiki content is federated

## Troubleshooting

### Activities not appearing in the Fediverse

1. Verify the extension is enabled:
   ```bash
   curl https://your-wiki.example.com/api/rest_v1/activitypub/actor
   ```
   Should return actor JSON, not 404.

2. Check MediaWiki error logs:
   ```bash
   tail -f /path/to/mediawiki/logs/debug.log | grep -i activitypub
   ```

3. Verify REST API is enabled in LocalSettings.php:
   ```php
   $wgEnableRestAPI = true;
   ```

### "No public key found"

1. Generate key pair (will be automatic in v0.2)
2. Verify keys are in database

## License

GPL-3.0-or-later

This extension is licensed under the GNU General Public License v3.0 or later. See LICENSE file for details.

## Support

- **Issues**: Report bugs on [GitHub Issues](https://github.com/lucamauri/ActivityWiki/issues)
- **Discussions**: Ask questions on [GitHub Discussions](https://github.com/lucamauri/ActivityWiki/discussions)
- **Documentation**: Full docs at [mediawiki.org](https://www.mediawiki.org/wiki/Extension:ActivityWiki)

## References

- [ActivityPub Specification](https://www.w3.org/TR/activitypub/)
- [MediaWiki Extension Development](https://www.mediawiki.org/wiki/Manual:Developing_extensions)
- [Fediverse Overview](https://en.wikipedia.org/wiki/Fediverse)

## Acknowledgments

Inspired by XWiki's ActivityPub implementation and the need for federation in wiki communities.

---

**Created for WikiTrek and the broader MediaWiki community.**
