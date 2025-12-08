# Contributing to ActivityWiki

Thank you for your interest in contributing to ActivityWiki! We welcome bug reports, feature requests, and code contributions.

## Getting Started

1. **Fork the repository** on GitHub
2. **Clone your fork** locally:
   ```bash
   git clone https://github.com/YOUR-USERNAME/mediawiki-extensions-ActivityWiki.git
   cd ActivityWiki
   ```
3. **Create a feature branch**:
   ```bash
   git checkout -b feature/your-feature-name
   ```

## Development Setup

### Prerequisites
- MediaWiki development environment
- PHP 7.2+
- Git

### Setting Up Your Development Environment

```bash
# Install MediaWiki (if not already done)
# Follow: https://www.mediawiki.org/wiki/Download

# Clone ActivityWiki into extensions
cd /path/to/mediawiki/extensions
git clone https://github.com/yourusername/mediawiki-extensions-ActivityWiki.git ActivityWiki

# Run database setup
cd /path/to/mediawiki
php maintenance/run.php update.php
```

## Code Standards

- Follow [MediaWiki Coding Conventions](https://www.mediawiki.org/wiki/Manual:Coding_conventions)
- Use PSR-12 for PHP code style
- 4-space indentation (no tabs)
- No trailing whitespace
- Use meaningful variable and function names

### Code Style Example

```php
<?php
namespace MediawikiActivityPub;

class MyClass {
    /**
     * Brief description of what this method does.
     *
     * @param string $param1 Description
     * @return bool True on success
     */
    public function myMethod( $param1 ) {
        if ( !$param1 ) {
            return false;
        }

        // Implementation
        return true;
    }
}
```

## Testing

### Running Tests

```bash
cd /path/to/mediawiki
php tests/phpunit/phpunit.php extensions/ActivityWiki/tests
```

### Writing Tests

- Create test files in `tests/phpunit/`
- Extend `MediaWikiTestCase` for MediaWiki integration tests
- Name test methods descriptively: `testSomethingDoesExpectedBehavior`

### Test Coverage

We aim for >80% code coverage. Check coverage with:

```bash
php tests/phpunit/phpunit.php --coverage-html coverage/ extensions/ActivityWiki/tests
```

## Commit Messages

Write clear, descriptive commit messages:

```
Add HTTP signature support to delivery

- Implement RFC 8017 signing
- Add signature verification tests
- Update documentation

Fixes #42
```

**Guidelines:**
- First line: short summary (50 chars max)
- Blank line
- Detailed explanation (wrap at 72 chars)
- Reference issues: `Fixes #123` or `Related to #456`

## Pull Request Process

1. **Update documentation** if you change functionality
2. **Add tests** for new features
3. **Run tests** locally and ensure they pass
4. **Push to your fork** and open a Pull Request
5. **Link related issues** in the PR description

### PR Title Format

```
[Phase] Feature: Brief description

For example:
[Phase 1] Feature: Add activity outbox pagination
[Phase 2] Fix: Correct HTTP signature header format
[Docs] Update installation instructions
```

### PR Description Template

```markdown
## Description
Brief description of changes.

## Related Issues
Fixes #123

## Changes
- List of changes
- What was added/modified

## Testing
How to test these changes.

## Checklist
- [ ] Code follows style guidelines
- [ ] Self-reviewed my own code
- [ ] Tests added/updated
- [ ] Documentation updated
```

## Reporting Bugs

### Security Issues
**Do not** open a public GitHub issue for security vulnerabilities. Email maintainers privately.

### Regular Issues

When reporting bugs, include:

1. **Description**: What did you expect vs. what happened?
2. **Steps to reproduce**:
   ```
   1. Did X
   2. Then Y
   3. Observed Z
   ```
3. **Environment**:
   - MediaWiki version
   - PHP version
   - Database (MySQL/PostgreSQL)
   - Browser (if relevant)
4. **Logs**: Relevant error logs from `debug.log`
5. **Screenshots**: If applicable

## Feature Requests

Clearly describe:
- **Use case**: Why is this needed?
- **Expected behavior**: What should it do?
- **Current behavior**: What happens now?
- **Implementation ideas** (optional): How might it be done?

## Project Phases

This project is organized into phases:

- **Phase 1**: Activity broadcasting (MVP)
- **Phase 2**: HTTP delivery & signatures
- **Phase 3**: Per-user actors
- **Phase 4**: Inbox & interactions

Contributions addressing any phase are welcome!

## Questions?

- Open a [GitHub Discussion](https://github.com/yourusername/mediawiki-extensions-ActivityWiki/discussions)
- Check existing [Issues](https://github.com/yourusername/mediawiki-extensions-ActivityWiki/issues)
- Review [Documentation](https://www.mediawiki.org/wiki/Extension:ActivityWiki)

## License

By contributing, you agree that your contributions will be licensed under the GPL-3.0-or-later license.

Thank you for contributing to ActivityWiki! 🎉
