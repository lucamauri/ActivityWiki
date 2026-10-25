<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\ActivityWiki\Special;

use Html;
use JobQueueGroup;
use MediaWiki\Config\Config;
use MediaWiki\Extension\ActivityWiki\ActivityWikiStatusReporter;
use MediaWiki\Extension\ActivityWiki\KeyManager;
use MediaWiki\Extension\ActivityWiki\WebFingerCheckResult;
use MediaWiki\Extension\ActivityWiki\WebFingerStatusChecker;
use MediaWiki\User\User;
use SpecialPage;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * SpecialActivityWiki — Special:ActivityWiki, the federation status page.
 *
 * Phase 5, Task 5.1 of the project plan. Gives the wiki operator a single
 * page to check whether ActivityPub federation is actually working, rather
 * than having to infer it from debug logs or wait for a real Fediverse
 * server to surface a problem (as happened with the silently-broken
 * WebFinger endpoint found in the 2026-06-20 session — see
 * ActivityWiki-plan.md).
 *
 * This class is intentionally "thin": every fact it displays is read from
 * ActivityWikiStatusReporter, which it builds manually from the services
 * injected via extension.json's "SpecialPages" entry — the same
 * manually-build-a-collaborator-from-injected-services pattern
 * Hooks::onPageSaveComplete() already uses for ActivityBuilder and
 * DeliveryQueue. This class's only job is turning that data into HTML.
 *
 * Access: open to any logged-in user (no dedicated right). Nothing shown
 * here is sensitive — the public key is, definitionally, public; follower
 * counts and queue depths are operational metadata, not secrets — so this
 * deliberately does not gate on a sysop-only right. It does require login
 * (via requireLogin() in execute()) simply because an anonymous status
 * dashboard has no real use case and MediaWiki's convention is that
 * special pages default to requiring at least a registered account unless
 * there's a specific reason for anonymous access.
 *
 * @since 1.2.0 (Phase 5 — Administration)
 */
class SpecialActivityWiki extends SpecialPage {

	private Config $config;
	private IConnectionProvider $dbProvider;
	private JobQueueGroup $jobQueueGroup;
	private KeyManager $keyManager;
	private WebFingerStatusChecker $webFingerStatusChecker;

	/**
	 * @param Config $config Main wiki config — passed straight through to
	 *   ActivityWikiStatusReporter.
	 * @param IConnectionProvider $dbProvider Database connection provider —
	 *   passed straight through to ActivityWikiStatusReporter.
	 * @param JobQueueGroup $jobQueueGroup Job queue group — passed straight
	 *   through to ActivityWikiStatusReporter.
	 * @param KeyManager $keyManager Key manager service — passed straight
	 *   through to ActivityWikiStatusReporter.
	 * @param WebFingerStatusChecker $webFingerStatusChecker WebFinger probe
	 *   service — passed straight through to ActivityWikiStatusReporter.
	 */
	public function __construct(
		Config $config,
		IConnectionProvider $dbProvider,
		JobQueueGroup $jobQueueGroup,
		KeyManager $keyManager,
		WebFingerStatusChecker $webFingerStatusChecker
	) {
		// The string passed to parent::__construct() is the special page's
		// canonical name — it is what makes this page live at
		// Special:ActivityWiki, and it is also, by MediaWiki convention,
		// the (lowercased) message key used for the page's title/
		// description unless getDescription() is overridden. We rely on
		// that default rather than overriding it — see the "activitywiki"
		// message in i18n/en.json.
		parent::__construct( 'ActivityWiki' );

		$this->config                 = $config;
		$this->dbProvider             = $dbProvider;
		$this->jobQueueGroup          = $jobQueueGroup;
		$this->keyManager             = $keyManager;
		$this->webFingerStatusChecker = $webFingerStatusChecker;
	}

	/**
	 * This page only ever reads data — it never writes to the database,
	 * pushes jobs, or performs any action with side effects (the WebFinger
	 * probe is an outbound GET to the wiki's own public endpoint, not a
	 * write). Declaring this explicitly lets MediaWiki core correctly treat
	 * the page as safe to load even when the wiki is in read-only mode.
	 *
	 * @return bool
	 */
	public function doesWrites(): bool {
		return false;
	}

	/**
	 * Renders the page.
	 *
	 * @param string|null $subPage Unused — this page takes no subpage
	 *   parameter; everything it shows is a global, wiki-wide status.
	 * @return void
	 */
	public function execute( $subPage ): void {
		// Require a logged-in (named) user. See this class's docblock for
		// why this page is open to any registered account rather than
		// gated behind a dedicated right.
		$this->requireLogin();

		$this->setHeaders();
		$this->outputHeader();

		$this->getOutput()->addModuleStyles( 'ext.activitywiki.special' );

		// Built manually here, not injected — see this class's docblock
		// for why ActivityWikiStatusReporter is not itself a registered
		// service.
		$reporter = new ActivityWikiStatusReporter(
			$this->config,
			$this->dbProvider,
			$this->jobQueueGroup,
			$this->keyManager,
			$this->webFingerStatusChecker
		);

		$html = '';
		$html .= $this->renderFederationSection( $reporter );
		$html .= $this->renderWebFingerSection( $reporter );
		$html .= $this->renderFollowersSection( $reporter );
		$html .= $this->renderJobQueueSection( $reporter );
		$html .= $this->renderKeySection( $reporter );
		$html .= $this->renderRecentActivitySection( $reporter );

		$this->getOutput()->addHTML( $html );
	}

	// -------------------------------------------------------------------------
	// Section renderers — one per row of the Phase 5 plan, in the same order
	// they appear in ActivityWiki-plan.md's Task 5.1 list, with the WebFinger
	// check (not in the original plan) placed right after federation status,
	// since it's the one row that can explain *why* federation looks "on" but
	// isn't actually reaching anyone.
	// -------------------------------------------------------------------------

	private function renderFederationSection( ActivityWikiStatusReporter $reporter ): string {
		$enabled = $reporter->isFederationEnabled();

		$stateMsgKey = $enabled ? 'activitywiki-federation-enabled' : 'activitywiki-federation-disabled';
		$cssClass    = $enabled ? 'activitywiki-status-ok' : 'activitywiki-status-bad';

		return Html::rawElement( 'h2', [], $this->msg( 'activitywiki-section-federation' )->escaped() )
			. Html::element( 'p', [ 'class' => $cssClass ], $this->msg( $stateMsgKey )->text() );
	}

	private function renderWebFingerSection( ActivityWikiStatusReporter $reporter ): string {
		$result = $reporter->getWebFingerCheck();

		// Map the result's STATE_* constant to its display message. The
		// match()'s default arm is unreachable in practice (getState() can
		// only ever return one of the three constants) but is required for
		// match()'s exhaustiveness and left as a safe fallback rather than
		// a thrown error, since this is a status page — it should never
		// itself be the thing that breaks.
		$stateMsgKey = match ( $result->getState() ) {
			WebFingerCheckResult::STATE_OK => 'activitywiki-webfinger-state-ok',
			WebFingerCheckResult::STATE_MISCONFIGURED => 'activitywiki-webfinger-state-misconfigured',
			WebFingerCheckResult::STATE_UNREACHABLE => 'activitywiki-webfinger-state-unreachable',
			default => 'activitywiki-webfinger-state-unreachable',
		};
		$cssClass = $result->isOk() ? 'activitywiki-status-ok' : 'activitywiki-status-bad';

		$httpStatusCode = $result->getHttpStatusCode();
		$httpStatusText = $httpStatusCode !== null
			? (string)$httpStatusCode
			: $this->msg( 'activitywiki-webfinger-http-status-none' )->text();

		$rows = $this->buildDefinitionRow(
			$this->msg( 'activitywiki-webfinger-probed-url' )->text(),
			$result->getProbedUrl()
		);
		$rows .= $this->buildDefinitionRow(
			$this->msg( 'activitywiki-webfinger-http-status' )->text(),
			$httpStatusText
		);
		$rows .= $this->buildDefinitionRow(
			$this->msg( 'activitywiki-webfinger-detail' )->text(),
			$result->getDetail()
		);

		$html = Html::rawElement( 'h2', [], $this->msg( 'activitywiki-section-webfinger' )->escaped() );
		$html .= Html::element( 'p', [ 'class' => $cssClass ], $this->msg( $stateMsgKey )->text() );
		$html .= Html::rawElement( 'table', [ 'class' => 'wikitable' ], $rows );

		// Only show the "how to fix this" hint when something is actually
		// wrong — it would be noise on a healthy install.
		if ( !$result->isOk() ) {
			$html .= Html::element(
				'p',
				[ 'class' => 'activitywiki-hint' ],
				$this->msg( 'activitywiki-webfinger-setup-hint' )->text()
			);
		}

		return $html;
	}

	private function renderFollowersSection( ActivityWikiStatusReporter $reporter ): string {
		$count = $reporter->getFollowerCount();

		return Html::rawElement( 'h2', [], $this->msg( 'activitywiki-section-followers' )->escaped() )
			// numParams() (rather than params()) marks $1 for both number
			// formatting (digit grouping per the viewing user's locale) and
			// {{PLURAL:}} support in the message definition.
			. Html::element( 'p', [], $this->msg( 'activitywiki-follower-count' )->numParams( $count )->text() );
	}

	private function renderJobQueueSection( ActivityWikiStatusReporter $reporter ): string {
		$depths = $reporter->getJobQueueDepths();

		$header = Html::rawElement( 'tr', [],
			Html::element( 'th', [], $this->msg( 'activitywiki-jobqueue-col-type' )->text() )
			. Html::element( 'th', [], $this->msg( 'activitywiki-jobqueue-col-ready' )->text() )
			. Html::element( 'th', [], $this->msg( 'activitywiki-jobqueue-col-delayed' )->text() )
			. Html::element( 'th', [], $this->msg( 'activitywiki-jobqueue-col-pending' )->text() )
		);

		$body = '';
		foreach ( $depths as $jobType => $counts ) {
			// $jobType (e.g. "MediawikiActivityPubDelivery") is an internal
			// job class name, not user-facing text — deliberately shown
			// as-is rather than through a message, since it's exactly the
			// string an operator would grep server logs for.
			$body .= Html::rawElement( 'tr', [],
				Html::element( 'td', [], $jobType )
				. Html::element( 'td', [], (string)$counts['ready'] )
				. Html::element( 'td', [], (string)$counts['delayed'] )
				. Html::element( 'td', [], (string)$counts['pending'] )
			);
		}

		return Html::rawElement( 'h2', [], $this->msg( 'activitywiki-section-jobqueue' )->escaped() )
			. Html::rawElement( 'table', [ 'class' => 'wikitable' ], $header . $body );
	}

	private function renderKeySection( ActivityWikiStatusReporter $reporter ): string {
		$fingerprint = $reporter->getPublicKeyFingerprint();

		$html = Html::rawElement( 'h2', [], $this->msg( 'activitywiki-section-key' )->escaped() );

		if ( $fingerprint === null ) {
			$html .= Html::element(
				'p',
				[ 'class' => 'activitywiki-status-bad' ],
				$this->msg( 'activitywiki-key-missing' )->text()
			);
			return $html;
		}

		$html .= Html::rawElement( 'table', [ 'class' => 'wikitable' ],
			$this->buildDefinitionRow( $this->msg( 'activitywiki-key-fingerprint-label' )->text(), $fingerprint )
		);
		return $html;
	}

	private function renderRecentActivitySection( ActivityWikiStatusReporter $reporter ): string {
		$rows = $reporter->getRecentActivity();

		$html = Html::rawElement( 'h2', [], $this->msg( 'activitywiki-section-recent-activity' )->escaped() );

		if ( $rows === [] ) {
			$html .= Html::element( 'p', [], $this->msg( 'activitywiki-recent-activity-empty' )->text() );
			return $html;
		}

		$header = Html::rawElement( 'tr', [],
			Html::element( 'th', [], $this->msg( 'activitywiki-recent-activity-col-type' )->text() )
			. Html::element( 'th', [], $this->msg( 'activitywiki-recent-activity-col-object' )->text() )
			. Html::element( 'th', [], $this->msg( 'activitywiki-recent-activity-col-page' )->text() )
			. Html::element( 'th', [], $this->msg( 'activitywiki-recent-activity-col-user' )->text() )
			. Html::element( 'th', [], $this->msg( 'activitywiki-recent-activity-col-time' )->text() )
			. Html::element( 'th', [], $this->msg( 'activitywiki-recent-activity-col-delivered' )->text() )
		);

		$body = '';
		foreach ( $rows as $row ) {
			$userDisplay = $row['userId'] !== null ? $this->resolveUserName( $row['userId'] ) : '';
			$deliveredMsgKey = $row['delivered'] ? 'activitywiki-yes' : 'activitywiki-no';

			$body .= Html::rawElement( 'tr', [],
				Html::element( 'td', [], $row['activityType'] )
				. Html::element( 'td', [], $row['objectType'] )
				. Html::element( 'td', [], $row['pageTitle'] ?? '' )
				. Html::element( 'td', [], $userDisplay )
				// userTimeAndDate() normalises almost any timestamp format
				// (including the "Y-m-d H:i:s" shape a "timestamp"-typed
				// DB column comes back as) to the viewing user's own
				// timezone and date format preference via MWTimestamp
				// internally. Called out explicitly in a comment because
				// this codebase has specifically been bitten by a
				// timestamp-handling bug before (ActivityBuilder's UTC
				// offset fix, Phase 4/5 — see ActivityWiki-plan.md) —
				// this is the core-provided, already-correct way to do it,
				// not a hand-rolled conversion.
				. Html::element( 'td', [], $this->getLanguage()->userTimeAndDate( $row['createdAt'], $this->getUser() ) )
				. Html::element( 'td', [], $this->msg( $deliveredMsgKey )->text() )
			);
		}

		$html .= Html::rawElement( 'table', [ 'class' => 'wikitable sortable' ], $header . $body );
		return $html;
	}

	// -------------------------------------------------------------------------
	// Small shared rendering helpers
	// -------------------------------------------------------------------------

	/**
	 * Builds a single "<tr><th>label</th><td>value</td></tr>" row, used by
	 * the WebFinger and key-fingerprint sections for their label/value
	 * tables.
	 *
	 * @param string $label Already-localised label text.
	 * @param string $value Value text — escaped by Html::element() like
	 *   everything else on this page, so callers never need to escape it
	 *   themselves first.
	 * @return string
	 */
	private function buildDefinitionRow( string $label, string $value ): string {
		return Html::rawElement( 'tr', [],
			Html::element( 'th', [], $label ) . Html::element( 'td', [], $value )
		);
	}

	/**
	 * Resolves a user ID to a display name for the recent-activity table.
	 *
	 * @param int $userId
	 * @return string The username, or "#<id>" if the account no longer
	 *   exists (e.g. it was deleted after federating an edit) — this page
	 *   should never let a dangling user_id turn into an empty or
	 *   confusing cell.
	 */
	private function resolveUserName( int $userId ): string {
		$name = User::newFromId( $userId )->getName();
		return $name !== '' ? $name : "#{$userId}";
	}
}