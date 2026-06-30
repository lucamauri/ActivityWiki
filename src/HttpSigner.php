<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\ActivityWiki;

use RuntimeException;

/**
 * HttpSigner — signs outbound ActivityPub HTTP POST requests.
 *
 * Every activity the wiki delivers to a remote Fediverse inbox (e.g. Mastodon)
 * must carry a cryptographic HTTP Signature, otherwise the remote server will
 * silently reject it. This class produces the full set of HTTP headers needed
 * for a valid signed POST, using the wiki's RSA private key stored by KeyManager.
 *
 * The signature scheme implemented here is "draft-cavage-http-signatures-12"
 * (October 2019), which is the version Mastodon and the majority of the Fediverse
 * actually use in practice. This predates the final RFC 9421 ("HTTP Message
 * Signatures", February 2024) and differs from it in several ways; using the
 * draft version is intentional and required for interoperability.
 *
 * Four components are covered by the signature:
 *   - (request-target)  pseudo-header: "post <path>"
 *   - host              the remote server's hostname
 *   - date              the current UTC timestamp in HTTP-date format
 *   - digest            SHA-256 hash of the request body, base64-encoded
 *
 * Usage (from DeliveryJob, Phase 3):
 * @code
 *   $headers = $httpSigner->signRequest( $inboxUrl, $activityJson );
 *   // $headers is a string-keyed array ready to attach to an HTTP POST request.
 * @endcode
 *
 * This class has no side effects and makes no network calls. It is a pure
 * transformation: (URL, body) → (signed headers array).
 *
 * @since 1.0.0
 */
class HttpSigner {

	/**
	 * The algorithm identifier sent in the Signature header.
	 *
	 * Mastodon and most Fediverse software expect exactly this string.
	 * Do not change to "hs2019" or any RFC 9421 identifier without verifying
	 * interoperability across target implementations.
	 */
	private const ALGORITHM = 'rsa-sha256';

	/**
	 * The ordered list of header names included in the signing string.
	 *
	 * Order matters: the receiver reconstructs the signing string in exactly
	 * this order. The value is also written verbatim into the `headers` field
	 * of the Signature header so the receiver knows which headers to use.
	 */
	private const SIGNED_HEADERS = '(request-target) host date digest';

	/**
	 * The fragment appended to the actor URL to form the public key identifier.
	 *
	 * This must match the `publicKey.id` value published in the actor object
	 * (built in ActivityPubModule::buildActorObject()). Mastodon fetches the
	 * actor document, finds `publicKey.id`, and uses it to locate the public
	 * key for signature verification.
	 */
	private const KEY_FRAGMENT = '#main-key';

	/** @var KeyManager Provides access to the stored RSA private key. */
	private KeyManager $keyManager;

	/**
	 * @var WikiActorUrls Provides this wiki's own actor URL, used to build
	 *   the keyId sent in the Signature header. Previously this class read
	 *   $wgServer/$wgScriptPath directly via a Config object and built that
	 *   URL itself in two separate places (inline in signRequest() and
	 *   again, slightly differently, in the public getActorUrl() method
	 *   below); consolidated into WikiActorUrls, shared with
	 *   ActivityBuilder, FollowManager, and SignatureVerifier — see that
	 *   class's docblock for the full history.
	 */
	private WikiActorUrls $wikiActorUrls;

	/**
	 * @param KeyManager $keyManager Service that holds the RSA key pair.
	 * @param WikiActorUrls $wikiActorUrls Provides this wiki's own actor URL.
	 */
	public function __construct( KeyManager $keyManager, WikiActorUrls $wikiActorUrls ) {
		$this->keyManager    = $keyManager;
		$this->wikiActorUrls = $wikiActorUrls;
	}

	/**
	 * Sign an outbound HTTP POST and return the headers required to send it.
	 *
	 * The returned array contains exactly the headers the caller must attach
	 * to the POST request. Keys are HTTP header names (e.g. 'Host', 'Date').
	 * The caller is responsible for actually sending the request; this method
	 * only produces the headers.
	 *
	 * Note: (request-target) is used internally in the signing string but is
	 * NOT included in the returned array — it is a pseudo-header that must
	 * never be sent as a real HTTP header.
	 *
	 * @param string $targetUrl The full URL of the remote inbox, e.g.
	 *   "https://mastodon.social/users/alice/inbox".
	 * @param string $body The raw JSON body of the ActivityPub activity that
	 *   will be POSTed. Must be the exact bytes that will be sent — any
	 *   re-encoding after signing will invalidate the Digest header.
	 * @return array<string,string> Associative array of HTTP headers:
	 *   'Host', 'Date', 'Digest', and 'Signature'.
	 * @throws RuntimeException If the private key is missing or if OpenSSL
	 *   fails to sign (e.g. corrupt key material).
	 */
	public function signRequest( string $targetUrl, string $body ): array {
		// ----------------------------------------------------------------
		// Step 1 — Parse the target URL into host and path components.
		// ----------------------------------------------------------------

		$parsedUrl = parse_url( $targetUrl );

		if ( $parsedUrl === false
			|| empty( $parsedUrl['host'] )
			|| empty( $parsedUrl['path'] )
		) {
			throw new RuntimeException(
				"HttpSigner: cannot parse target URL: {$targetUrl}"
			);
		}

		$host = $parsedUrl['host'];

		// Include the query string in (request-target) if present,
		// exactly as the remote server will see it in the request line.
		$path = $parsedUrl['path'];
		if ( !empty( $parsedUrl['query'] ) ) {
			$path .= '?' . $parsedUrl['query'];
		}

		// ----------------------------------------------------------------
		// Step 2 — Build the Date header.
		//
		// HTTP-date format per RFC 7231 §7.1.1.1, always in UTC ("GMT").
		// The \G\M\T escapes produce the literal string "GMT" without PHP
		// interpreting those letters as format tokens.
		// ----------------------------------------------------------------
		$date = gmdate( 'D, d M Y H:i:s \G\M\T' );

		// ----------------------------------------------------------------
		// Step 3 — Build the Digest header.
		//
		// SHA-256 hash of the raw request body, base64-encoded, prefixed
		// with the algorithm label "SHA-256=".
		//
		// The second argument `true` to hash() returns raw binary bytes
		// rather than a lowercase hex string. base64_encode() of raw bytes
		// is what the spec and Mastodon both expect.
		// ----------------------------------------------------------------
		$digest = 'SHA-256=' . base64_encode(
			hash( 'sha256', $body, true )
		);

		// ----------------------------------------------------------------
		// Step 4 — Assemble the signing string.
		//
		// Each line is "lowercase-header-name: value". Lines are joined
		// with a single newline (\n). The order must exactly match the
		// `headers` field we will declare in the Signature header, so that
		// the receiver can reconstruct this string during verification.
		//
		// (request-target) is a pseudo-header defined by the draft spec;
		// its value is always the HTTP method (lowercase) + space + path.
		// ----------------------------------------------------------------
		$signingString = implode( "\n", [
			"(request-target): post {$path}",
			"host: {$host}",
			"date: {$date}",
			"digest: {$digest}",
		] );

		// ----------------------------------------------------------------
		// Step 5 — Retrieve the private key and sign.
		//
		// openssl_sign() writes the raw binary signature into $rawSignature
		// by reference. OPENSSL_ALGO_SHA256 tells OpenSSL to hash the input
		// with SHA-256 before applying the RSA private-key operation —
		// this corresponds to the "rsa-sha256" algorithm identifier.
		// ----------------------------------------------------------------
		$privateKeyPem = $this->keyManager->getPrivateKeyPem();

		if ( $privateKeyPem === null ) {
			throw new RuntimeException(
				'HttpSigner: no private key found in the database. '
				. 'Run the GenerateKeys maintenance script or reinstall the extension.'
			);
		}

		$rawSignature = '';
		$signed = openssl_sign(
			$signingString,
			$rawSignature,
			$privateKeyPem,
			OPENSSL_ALGO_SHA256
		);

		if ( $signed === false ) {
			throw new RuntimeException(
				'HttpSigner: openssl_sign() failed. '
				. 'The private key may be corrupt: '
				. openssl_error_string()
			);
		}

		$signatureB64 = base64_encode( $rawSignature );

		// ----------------------------------------------------------------
		// Step 6 — Build the keyId URL.
		//
		// The keyId must exactly match the `publicKey.id` value published
		// in the actor object. That value is the actor REST endpoint URL
		// with "#main-key" appended. Mastodon fetches the actor document
		// and uses publicKey.id to look up the public key for verification.
		// ----------------------------------------------------------------
		$keyId = $this->wikiActorUrls->getWikiActorUrl() . self::KEY_FRAGMENT;

		// ----------------------------------------------------------------
		// Step 7 — Assemble the Signature header value.
		//
		// The four comma-separated fields are defined by draft-cavage-12:
		//   keyId     — URL where the public key can be fetched
		//   algorithm — always "rsa-sha256" for our scheme
		//   headers   — space-separated list of signed header names, in order
		//   signature — base64-encoded RSA signature bytes
		// ----------------------------------------------------------------
		$signatureHeader = implode( ',', [
			'keyId="' . $keyId . '"',
			'algorithm="' . self::ALGORITHM . '"',
			'headers="' . self::SIGNED_HEADERS . '"',
			'signature="' . $signatureB64 . '"',
		] );

		// ----------------------------------------------------------------
		// Step 8 — Return the headers array.
		//
		// These four headers must all be present on the outbound POST.
		// (request-target) is intentionally excluded — it was only used
		// in the signing string and must not be sent as a real header.
		// ----------------------------------------------------------------
		return [
			'Host'      => $host,
			'Date'      => $date,
			'Digest'    => $digest,
			'Signature' => $signatureHeader,
		];
	}

	/**
	 * Build the canonical actor URL used as the base for the keyId.
	 *
	 * Extracted as a separate method so that future code (e.g. the inbox
	 * handler in Phase 4) can reuse the same URL construction logic without
	 * duplicating the config reads. Returns the actor endpoint URL without
	 * the #main-key fragment.
	 *
	 * Thin passthrough to WikiActorUrls — kept as a named method on this
	 * class since it's part of HttpSigner's existing public API, even
	 * though nothing outside this class currently calls it (confirmed by
	 * search across the rest of the extension).
	 *
	 * @return string Full URL of the actor REST endpoint.
	 */
	public function getActorUrl(): string {
		return $this->wikiActorUrls->getWikiActorUrl();
	}
}