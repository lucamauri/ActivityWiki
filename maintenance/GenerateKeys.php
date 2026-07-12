<?php

declare(strict_types=1);

/**
 * ActivityWiki — GenerateKeys maintenance script.
 *
 * Provides a manual, operator-facing way to generate the RSA key pair used
 * for HTTP Signature signing (see KeyManager.php).
 *
 * Two situations this script is meant to cover:
 *
 *  1. First-time setup where, for whatever reason, the automatic
 *     generateAndStoreKeyPairIfAbsent() call made during
 *     `php maintenance/run.php update.php` did not run or did not succeed
 *     (e.g. it was skipped, or OpenSSL failed at that point and the
 *     operator fixed the underlying issue afterwards). Running this script
 *     with no key present is safe and non-destructive.
 *
 *  2. Deliberate key ROTATION — e.g. the private key is suspected to be
 *     compromised, or the operator wants to increase $wgActivityWikiKeySize
 *     and regenerate. This is destructive: the existing key pair is deleted
 *     and replaced, and MUST be gated behind an explicit --force flag so it
 *     can never happen by accident (e.g. from a mistyped or copy-pasted
 *     command). There is no interactive (y/N) prompt in addition to
 *     --force — maintenance scripts are frequently run non-interactively,
 *     and a required flag is already an unambiguous, deliberate signal.
 *
 * Usage:
 *   php maintenance/run.php extensions/ActivityWiki/maintenance/GenerateKeys.php
 *   php maintenance/run.php extensions/ActivityWiki/maintenance/GenerateKeys.php --force
 *   php maintenance/run.php extensions/ActivityWiki/maintenance/GenerateKeys.php --force --show
 *
 * @file
 * @ingroup Maintenance
 */

// Standard MediaWiki maintenance script bootstrap. This walks up from
// extensions/ActivityWiki/maintenance/ to the MediaWiki installation root,
// then down into its own maintenance/ directory to find the base
// Maintenance class. Three levels up matches this file's actual location
// (maintenance/ -> ActivityWiki/ -> extensions/ -> installation root).
require_once __DIR__ . '/../../../maintenance/Maintenance.php';

use MediaWiki\Extension\ActivityWiki\KeyManager;

/**
 * GenerateKeys — manually (re)generate the ActivityWiki RSA key pair.
 *
 * All actual key-generation and persistence logic lives in KeyManager;
 * this class is deliberately thin. Its only responsibilities are:
 *  - parsing/validating command-line options,
 *  - deciding whether it is safe to proceed (the --force gate for rotation),
 *  - printing clear, operator-facing output.
 */
class GenerateKeys extends Maintenance
{

	/**
	 * Registers this script with the ActivityWiki extension group and
	 * declares its command-line options.
	 */
	public function __construct()
	{
		parent::__construct();

		$this->addDescription(
			'Generates the RSA key pair ActivityWiki uses for HTTP Signature ' .
				'signing. Safe to run with no existing key (first-time setup). ' .
				'Regenerating an EXISTING key (rotation) is destructive and ' .
				'requires --force.'
		);

		$this->addOption(
			'force',
			'Required to regenerate a key pair that already exists. Without ' .
				'this flag, the script refuses to touch an existing key and ' .
				'exits with an explanatory message.'
		);

		$this->addOption(
			'show',
			'After generating the key pair, print the new public key PEM to ' .
				'the console for verification.'
		);

		$this->requireExtension('ActivityWiki');
	}

	/**
	 * Entry point, called by Maintenance::execute() after option parsing.
	 *
	 * @return bool|void Returning false signals failure to the maintenance
	 *   runner; a fatalError() call (which itself exits) makes an explicit
	 *   return value unnecessary in the abort paths below, but PHPStan/IDE
	 *   tooling is happier with a declared return type of void here since
	 *   Maintenance::execute() is untyped in core.
	 */
	public function execute()
	{
		/** @var KeyManager $keyManager */
		$keyManager = $this->getServiceContainer()->get('ActivityWiki.KeyManager');

		$keyAlreadyExists = $keyManager->hasKey();

		if ($keyAlreadyExists && !$this->hasOption('force')) {
			// Safety gate: refuse to rotate an existing key unless the
			// operator has explicitly opted in with --force. This is the
			// single most important behaviour of this script — silently
			// "just regenerating" here would invalidate the current key
			// with no warning, breaking outbound delivery until every
			// remote server's cached view of our public key expires.
			$this->output(
				"A key pair already exists for this wiki.\n\n" .
					"Regenerating it now would immediately invalidate the " .
					"current key. Outbound HTTP Signatures signed with the " .
					"old key would stop verifying on the remote end right " .
					"away; some remote servers may also have CACHED our old " .
					"public key and could continue rejecting our requests " .
					"until that cache expires on their side, even after we " .
					"rotate.\n\n" .
					"If this is intentional (e.g. suspected key compromise, " .
					"or increasing \$wgActivityWikiKeySize), re-run this " .
					"script with --force to proceed.\n\n" .
					"No changes have been made.\n"
			);

			// A non-zero-ish, non-fatal early return: this is a deliberate
			// abort, not an error condition, so fatalError() (which implies
			// something went wrong) would be the wrong tool here.
			return;
		}

		if ($keyAlreadyExists) {
			$this->output("--force supplied — rotating existing key pair.\n");
		} else {
			$this->output("No existing key pair found — generating a new one.\n");
		}

		try {
			// This single call handles both the "no key yet" and "rotate
			// existing key" cases identically — see generateAndStoreKeyPair()'s
			// own docblock. The DELETE+INSERT happens inside an atomic
			// section, so the table is never left in a half-written state
			// even if something fails partway through.
			$keyManager->generateAndStoreKeyPair();
		} catch (\RuntimeException $e) {
			// generateAndStoreKeyPair() throws RuntimeException specifically
			// when OpenSSL itself fails (e.g. misconfigured OpenSSL, or an
			// unsupported key size). fatalError() prints the message and
			// exits with a non-zero status, which is exactly what we want
			// for a genuine failure — unlike the --force gate above, this
			// path really is an error.
			$this->fatalError('Key generation failed: ' . $e->getMessage());
			return;
		}

		$this->output("Key pair generated and stored successfully.\n");

		// Restarting PHP-FPM is not strictly required for correctness — the
		// KeyManager reads from the database on every request, not from an
		// in-process cache — but opcache can sometimes hold stale compiled
		// state across unrelated deploys, and it costs nothing to remind
		// the operator to restart as a precaution, consistent with the
		// deploy habits used elsewhere in this project.
		$this->output(
			"\nRestart PHP-FPM as a precaution:\n" .
				"  sudo systemctl restart php8.4-fpm\n" .
				"(or your equivalent service name)\n"
		);

		if ($this->hasOption('show')) {
			$publicKeyPem = $keyManager->getPublicKeyPem();

			// Defensive check only — generateAndStoreKeyPair() just
			// succeeded, so a null public key here would indicate a serious
			// internal inconsistency (e.g. a read against a replica that
			// hasn't caught up yet) rather than a normal failure mode. Report
			// it plainly rather than silently omitting the --show output.
			if ($publicKeyPem === null) {
				$this->output(
					"\nCould not read back the public key immediately after " .
						"writing it (possible replication lag). It is stored " .
						"correctly; try checking again in a moment, e.g. via " .
						"the actor endpoint.\n"
				);
			} else {
				$this->output("\nNew public key (PEM):\n\n" . $publicKeyPem . "\n");
			}
		}
	}
}

// Standard MediaWiki maintenance script trailer: tells the runner which
// class in this file to actually instantiate and execute.
$maintClass = GenerateKeys::class;
require_once RUN_MAINTENANCE_IF_MAIN;