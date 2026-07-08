<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\ActivityWiki;

/**
 * SignatureVerifier — verifies inbound ActivityPub HTTP Signatures.
 *
 * Every activity the wiki receives at its inbox (e.g. a `Follow` request from
 * a Mastodon user) must carry a cryptographic HTTP Signature, exactly the same
 * kind this wiki itself produces for outbound deliveries via HttpSigner. This
 * class checks that signature, proving the request really came from the actor
 * it claims to come from and was not altered in transit.
 *
 * This is the mirror image of HttpSigner: where HttpSigner signs with our own
 * private key, SignatureVerifier verifies with the *sender's* public key,
 * which it obtains by following the `keyId` URL named in the Signature
 * header. The HTTP fetch and JSON decoding needed to do that is delegated to
 * ActorFetcher (see Phase 5, Task 2) — this class is only responsible for
 * stripping the `#fragment` off the keyId to get a plain actor URL, and for
 * pulling `publicKey.publicKeyPem` out of whatever document ActorFetcher
 * returns. Previously this class performed the HTTP GET itself, duplicating
 * near-identical logic that also lived in FollowManager; see ActorFetcher.php's
 * docblock for the full history of that consolidation.
 *
 * The verification scheme implemented here is "draft-cavage-http-signatures-12"
 * (October 2019) — the same draft HttpSigner produces, and the version Mastodon
 * and the majority of the Fediverse actually use. We deliberately accept only
 * the `rsa-sha256` algorithm; anything else is rejected outright rather than
 * attempted, since accepting an unexpected algorithm string and feeding it to
 * openssl_verify() with the wrong digest method would be a silent correctness
 * bug, not a security improvement.
 *
 * Verification proceeds in five fail-fast steps:
 *   1. Parse the Signature header (reject anything but rsa-sha256 immediately)
 *   2. Verify the Digest header against the actual body (cheap, no network call)
 *   3. Fetch the sender's public key from their actor URL (one network call,
 *      via ActorFetcher)
 *   4. Reconstruct the exact signing string the sender signed
 *   5. Run openssl_verify() and require an exact match (return value === 1)
 *
 * Any failure at any step results in `verify()` returning false. Per the
 * design agreed for this extension, SignatureVerifier never throws — the
 * caller (InboxHandler) only needs a yes/no answer to decide whether to
 * return a 401. Specific failure reasons are written to the debug log so an
 * operator can diagnose a misbehaving sender without InboxHandler needing to
 * know the internals of verification.
 *
 * Actor key caching (the `activitywiki_actor_cache` table mentioned in the
 * project plan) is intentionally NOT implemented here yet. Every call to
 * verify() currently performs a live HTTP fetch (via ActorFetcher) of the
 * sender's actor document. Caching is deferred to a fast-follow once this
 * class is proven working end-to-end on wikitrek.org — see
 * ActivityWiki-plan.md, section 4.5.
 *
 * @since 1.0.0
 */
class SignatureVerifier {

	/**
	 * The only algorithm identifier we accept in the Signature header.
	 *
	 * This must match the constant of the same name in HttpSigner. Any
	 * incoming request claiming a different algorithm (e.g. "hs2019", the
	 * RFC 9421 identifier) is rejected before any cryptographic operation
	 * is attempted.
	 */
	private const ALGORITHM = 'rsa-sha256';

	/**
	 * The MediaWiki debug log channel used by this class.
	 *
	 * Must match the channel name configured in LocalSettings.php exactly —
	 * see the project's "log channel" lesson learned in earlier phases.
	 */
	private const LOG_CHANNEL = 'ActivityWiki';

	/**
	 * @var ActorFetcher Fetches and decodes the sender's actor document,
	 *   from which this class extracts publicKey.publicKeyPem. Previously
	 *   this class held HttpRequestFactory and WikiActorUrls directly and
	 *   performed the HTTP GET itself; that mechanics moved into
	 *   ActorFetcher in Phase 5, Task 2, since FollowManager needed the
	 *   identical fetch for a different field (inbox URL). See
	 *   ActorFetcher.php's class docblock for the full history.
	 */
	private ActorFetcher $actorFetcher;

	/**
	 * @param ActorFetcher $actorFetcher Fetches and decodes a remote actor
	 *   document over HTTP.
	 */
	public function __construct( ActorFetcher $actorFetcher ) {
		$this->actorFetcher = $actorFetcher;
	}

	/**
	 * Verify the HTTP Signature on an incoming ActivityPub request.
	 *
	 * @param string $method The HTTP method of the incoming request, e.g. "POST".
	 *   Used (lower-cased) to reconstruct the (request-target) pseudo-header.
	 * @param string $path The path component of the incoming request's URL,
	 *   e.g. "/wt/rest.php/activitywiki/inbox". Must be exactly what the sender
	 *   used when building their own (request-target) line — including any
	 *   query string, if present.
	 * @param array<string,string> $headers Associative array of the incoming
	 *   request's HTTP headers. Keys may be in any case; lookups inside this
	 *   method are case-insensitive, since HTTP header casing is not guaranteed
	 *   to survive transport unchanged.
	 * @param string $body The raw request body exactly as received, with no
	 *   re-encoding. Used to recompute the SHA-256 digest for comparison
	 *   against the sender's Digest header.
	 * @return bool True if the signature is valid and the body is untampered;
	 *   false for any failure (missing header, unsupported algorithm, digest
	 *   mismatch, unreachable sender, malformed key, invalid signature, etc.).
	 */
	public function verify( string $method, string $path, array $headers, string $body ): bool {
		// ------------------------------------------------------------------
		// Step 1 — Parse the Signature header.
		//
		// Locate it case-insensitively, then extract the four named fields.
		// If the header is absent, malformed, missing a required field, or
		// names an algorithm we do not support, we fail immediately — there
		// is nothing further to check.
		// ------------------------------------------------------------------
		$signatureHeaderValue = $this->getHeaderCaseInsensitive( $headers, 'Signature' );

		if ( $signatureHeaderValue === null ) {
			$this->logFailure( 'missing Signature header' );
			return false;
		}

		$parsedSignature = $this->parseSignatureHeader( $signatureHeaderValue );

		if ( $parsedSignature === null ) {
			$this->logFailure( 'malformed Signature header' );
			return false;
		}

		[ 'keyId' => $keyId, 'algorithm' => $algorithm, 'headers' => $signedHeaderNames, 'signature' => $signatureB64 ] =
			$parsedSignature;

		if ( $algorithm !== self::ALGORITHM ) {
			$this->logFailure( "unsupported algorithm \"{$algorithm}\" — only \"" . self::ALGORITHM . '" is accepted' );
			return false;
		}

		// ------------------------------------------------------------------
		// Step 2 — Verify the Digest header against the actual body.
		//
		// This is independent of, and cheaper than, the cryptographic check
		// below, so we do it first: no point making an outbound HTTP request
		// to fetch a public key if the body has already been tampered with
		// or the digest is simply wrong.
		// ------------------------------------------------------------------
		$digestHeaderValue = $this->getHeaderCaseInsensitive( $headers, 'Digest' );

		if ( $digestHeaderValue === null ) {
			$this->logFailure( 'missing Digest header' );
			return false;
		}

		if ( !$this->verifyDigest( $digestHeaderValue, $body ) ) {
			$this->logFailure( 'Digest header does not match request body' );
			return false;
		}

		// ------------------------------------------------------------------
		// Step 3 — Fetch the sender's public key.
		//
		// keyId is a URL with a fragment, e.g.
		// "https://mastodon.social/users/alice#main-key". We strip the
		// fragment to get the actor URL, then ask ActorFetcher to fetch and
		// decode that actor document, then pull
		// publicKey.publicKeyPem out of the returned JSON ourselves —
		// ActorFetcher only fetches and decodes; it has no opinion about
		// which field a caller wants.
		// ------------------------------------------------------------------
		$publicKeyPem = $this->fetchPublicKey( $keyId );

		if ( $publicKeyPem === null ) {
			$this->logFailure( "could not obtain public key from keyId \"{$keyId}\"" );
			return false;
		}

		// ------------------------------------------------------------------
		// Step 4 — Reconstruct the signing string.
		//
		// $signedHeaderNames is the space-separated list from the Signature
		// header's "headers" field, e.g. "(request-target) host date digest".
		// We must rebuild the exact string the sender signed, in the exact
		// order they declared — using the headers actually present on this
		// incoming request, not headers we might expect.
		// ------------------------------------------------------------------
		$signingString = $this->buildSigningString( $method, $path, $signedHeaderNames, $headers );

		if ( $signingString === null ) {
			$this->logFailure( 'could not reconstruct signing string — a declared header was not present on the request' );
			return false;
		}

		// ------------------------------------------------------------------
		// Step 5 — Verify the signature.
		//
		// openssl_verify() returns 1 (valid), 0 (invalid), or -1 (error, e.g.
		// malformed key material). Only an exact 1 counts as success; both
		// 0 and -1 are treated identically as a verification failure.
		// ------------------------------------------------------------------
		$rawSignature = base64_decode( $signatureB64, true );

		if ( $rawSignature === false ) {
			$this->logFailure( 'signature field is not valid base64' );
			return false;
		}

		$result = openssl_verify( $signingString, $rawSignature, $publicKeyPem, OPENSSL_ALGO_SHA256 );

		if ( $result !== 1 ) {
			$this->logFailure( "openssl_verify() returned {$result} (expected 1)" );
			return false;
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Look up a header value by name, ignoring case.
	 *
	 * HTTP header names are case-insensitive per RFC 7230, but PHP arrays are
	 * not, so callers receiving headers from different sources (REST router,
	 * test fixtures, etc.) cannot rely on a single consistent casing. This
	 * does a linear scan rather than assuming any particular casing convention.
	 *
	 * @param array<string,string> $headers The headers array to search.
	 * @param string $name The header name to find, in any case.
	 * @return string|null The header value, or null if not present.
	 */
	private function getHeaderCaseInsensitive( array $headers, string $name ): ?string {
		$needle = strtolower( $name );

		foreach ( $headers as $headerName => $headerValue ) {
			if ( strtolower( (string)$headerName ) === $needle ) {
				return (string)$headerValue;
			}
		}

		return null;
	}

	/**
	 * Parse a Signature header value into its four named fields.
	 *
	 * Expected format (draft-cavage-http-signatures-12):
	 *   keyId="...",algorithm="...",headers="...",signature="..."
	 *
	 * @param string $headerValue The raw Signature header value.
	 * @return array{keyId:string,algorithm:string,headers:string,signature:string}|null
	 *   The parsed fields, or null if any required field is missing.
	 */
	private function parseSignatureHeader( string $headerValue ): ?array {
		// Matches one or more comma-separated key="value" pairs. The value
		// itself is assumed not to contain a literal double-quote, which
		// holds for every field we expect here (URLs, algorithm names,
		// space-separated header lists, and base64 signatures never contain
		// double quotes).
		preg_match_all( '/(\w+)="([^"]*)"/', $headerValue, $matches, PREG_SET_ORDER );

		$fields = [];
		foreach ( $matches as $match ) {
			$fields[ $match[1] ] = $match[2];
		}

		foreach ( [ 'keyId', 'algorithm', 'headers', 'signature' ] as $requiredField ) {
			if ( !isset( $fields[ $requiredField ] ) || $fields[ $requiredField ] === '' ) {
				return null;
			}
		}

		return [
			'keyId'      => $fields['keyId'],
			'algorithm'  => $fields['algorithm'],
			'headers'    => $fields['headers'],
			'signature'  => $fields['signature'],
		];
	}

	/**
	 * Reconstruct the exact signing string the sender signed.
	 *
	 * @param string $method The HTTP method of the incoming request.
	 * @param string $path The path (and query string, if any) of the
	 *   incoming request's URL.
	 * @param string $signedHeaderNames Space-separated list of header names
	 *   from the Signature header's "headers" field, e.g.
	 *   "(request-target) host date digest".
	 * @param array<string,string> $headers The incoming request's headers,
	 *   used to look up the value for every name in $signedHeaderNames other
	 *   than the (request-target) pseudo-header.
	 * @return string|null The reconstructed signing string, or null if any
	 *   declared header name has no corresponding value in $headers.
	 */
	private function buildSigningString(
		string $method,
		string $path,
		string $signedHeaderNames,
		array $headers
	): ?string {
		$lines = [];

		foreach ( explode( ' ', trim( $signedHeaderNames ) ) as $headerName ) {
			if ( $headerName === '(request-target)' ) {
				$lines[] = '(request-target): ' . strtolower( $method ) . ' ' . $path;
				continue;
			}

			$value = $this->getHeaderCaseInsensitive( $headers, $headerName );

			if ( $value === null ) {
				// The sender signed a header we do not have — we cannot
				// reconstruct the string they actually signed.
				return null;
			}

			$lines[] = strtolower( $headerName ) . ': ' . $value;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Verify that a Digest header matches the actual request body.
	 *
	 * @param string $digestHeaderValue The raw Digest header, e.g.
	 *   "SHA-256=base64hash...".
	 * @param string $body The raw request body to hash and compare.
	 * @return bool True if the digest matches; false otherwise (including if
	 *   the header is not in the expected "SHA-256=" format).
	 */
	private function verifyDigest( string $digestHeaderValue, string $body ): bool {
		if ( !str_starts_with( $digestHeaderValue, 'SHA-256=' ) ) {
			return false;
		}

		$claimedDigest = substr( $digestHeaderValue, strlen( 'SHA-256=' ) );
		$actualDigest  = base64_encode( hash( 'sha256', $body, true ) );

		// hash_equals() performs a constant-time comparison, which is the
		// correct choice for comparing any value derived from attacker-
		// controlled input against a locally computed reference, even
		// though a digest mismatch here is not itself a secret.
		return hash_equals( $actualDigest, $claimedDigest );
	}

	/**
	 * Fetch the sender's public key PEM from their actor document.
	 *
	 * @param string $keyId The keyId URL from the Signature header, e.g.
	 *   "https://mastodon.social/users/alice#main-key". The fragment is
	 *   stripped to obtain the actor URL to fetch — fragment handling is
	 *   specific to the HTTP Signature scheme, so it stays here rather than
	 *   in ActorFetcher, which only knows about plain actor URLs.
	 * @return string|null The public key PEM, or null on any failure
	 *   (ActorFetcher could not fetch/decode the document, or the decoded
	 *   document has no publicKey.publicKeyPem field).
	 */
	private function fetchPublicKey( string $keyId ): ?string {
		// Strip everything from "#" onward to get the actor URL itself.
		$actorUrl = strtok( $keyId, '#' );

		if ( $actorUrl === false || $actorUrl === '' ) {
			return null;
		}

		$actorJson = $this->actorFetcher->fetchActorDocument( $actorUrl );

		if ( $actorJson === null ) {
			// ActorFetcher already logged the specific network/decode
			// failure reason; nothing further to add here.
			return null;
		}

		$publicKeyPem = $actorJson['publicKey']['publicKeyPem'] ?? null;

		if ( !is_string( $publicKeyPem ) || $publicKeyPem === '' ) {
			return null;
		}

		return $publicKeyPem;
	}

	/**
	 * Write a verification failure reason to the debug log.
	 *
	 * Centralised so every failure path logs consistently with the same
	 * channel and prefix, making it easy for an operator to grep the log
	 * for every rejected inbox request.
	 *
	 * @param string $reason A short, specific description of why verification failed.
	 */
	private function logFailure( string $reason ): void {
		wfDebugLog( self::LOG_CHANNEL, "SignatureVerifier: verification failed — {$reason}" );
	}
}