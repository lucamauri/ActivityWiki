<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\ActivityWiki;

/**
 * WebFingerCheckResult — immutable value object describing the outcome of
 * a live WebFinger reachability probe (see WebFingerStatusChecker).
 *
 * WHY THIS CLASS EXISTS (rather than WebFingerStatusChecker just returning
 * an array, as ActorFetcher does for actor documents):
 * ActorFetcher's return shape is "whatever JSON the remote server sent, or
 * null" — the caller already knows what fields to expect. Here, the thing
 * being reported is the *outcome of a diagnostic check*, not a document, so
 * callers (today: SpecialActivityWiki) need a small fixed set of fields
 * every time (which state, what URL was tried, what HTTP status came back,
 * and a human-readable explanation) rather than a loosely-shaped array they
 * would have to defensively isset() their way through. A dedicated class
 * also gives the three possible states names (see the STATE_* constants)
 * instead of magic strings scattered across the codebase.
 *
 * Instances are built only by WebFingerStatusChecker::check() — this class
 * itself has no behaviour beyond holding and exposing its four fields.
 *
 * @since 1.2.0 (Phase 5 — Administration)
 */
class WebFingerCheckResult {

	/**
	 * The probe got a 200 response whose JSON body is a valid WebFinger
	 * JRD (JSON Resource Descriptor) matching this wiki's expected actor.
	 * WebFinger discovery is working end-to-end — the entry-point file and
	 * web-server rewrite rule described in README.md are correctly in place.
	 */
	public const STATE_OK = 'ok';

	/**
	 * The probe reached *something* at the expected URL — an HTTP response
	 * came back — but the body was not valid WebFinger JSON for this wiki's
	 * actor (e.g. a generic web-server 404 page, a different site entirely
	 * because the rewrite target is wrong, or the JRD's "subject" field
	 * doesn't match what this wiki expects). This usually means the
	 * rewrite rule exists but points at the wrong place, or entry-points/
	 * webfinger.php was copied with the wrong $wgScriptPath substituted in.
	 */
	public const STATE_MISCONFIGURED = 'misconfigured';

	/**
	 * The probe could not get a usable response at all — connection
	 * refused, DNS failure, timeout, or an HTTP error status. This is the
	 * state a fresh install will show until the admin completes the manual
	 * WebFinger routing step documented in README.md — it is the
	 * "completely invisible to the Fediverse" failure mode described in
	 * ActivityWiki-plan.md's bug writeup from the 2026-06-20 session.
	 */
	public const STATE_UNREACHABLE = 'unreachable';

	/** @var string One of the STATE_* constants above. */
	private string $state;

	/**
	 * @var string The full public WebFinger URL that was probed, e.g.
	 *   "https://wikitrek.org/.well-known/webfinger?resource=acct:wikitrek%40wikitrek.org".
	 *   Shown to the operator so they know exactly what was tested.
	 */
	private string $probedUrl;

	/**
	 * @var int|null The HTTP status code returned by the probe, or null if
	 *   no response was received at all (e.g. connection refused/timeout —
	 *   as opposed to a received-but-wrong status such as 404, which is
	 *   still recorded here).
	 */
	private ?int $httpStatusCode;

	/**
	 * @var string A short, human-readable explanation of the result,
	 *   suitable for direct display on Special:ActivityWiki. Always set,
	 *   regardless of state, so the page never has to invent its own
	 *   wording per state.
	 */
	private string $detail;

	/**
	 * @param string $state One of the STATE_* constants.
	 * @param string $probedUrl The public URL that was probed.
	 * @param int|null $httpStatusCode The HTTP status received, or null if none.
	 * @param string $detail Human-readable explanation for display.
	 */
	public function __construct( string $state, string $probedUrl, ?int $httpStatusCode, string $detail ) {
		$this->state          = $state;
		$this->probedUrl      = $probedUrl;
		$this->httpStatusCode = $httpStatusCode;
		$this->detail         = $detail;
	}

	public function getState(): string {
		return $this->state;
	}

	public function isOk(): bool {
		return $this->state === self::STATE_OK;
	}

	public function getProbedUrl(): string {
		return $this->probedUrl;
	}

	public function getHttpStatusCode(): ?int {
		return $this->httpStatusCode;
	}

	public function getDetail(): string {
		return $this->detail;
	}
}