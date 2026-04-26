<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\ActivityWiki;

use MediaWiki\Config\Config;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\Platform\ISQLPlatform;

/**
 * KeyManager — manages the RSA key pair used for HTTP Signature signing.
 *
 * Responsibilities:
 *  - Generate a new RSA key pair (private + public PEM) using PHP's built-in
 *    openssl_* functions.
 *  - Persist the key pair to the `activitywiki_keys` database table.
 *  - Retrieve the stored public or private key PEM for use by other components
 *    (actor object builder, HTTP signer).
 *
 * Design decisions (confirmed before coding):
 *  - Keys are stored as plain PEM in the database (no encryption at rest).
 *  - Key generation happens automatically on first install (no row in the table)
 *    and is also available manually via the GenerateKeys maintenance script.
 *  - PHP built-in openssl_* functions are used; no external libraries required.
 *
 * @package MediaWiki\Extension\ActivityWiki
 */
class KeyManager {

	/**
	 * Name of the database table that stores the key pair.
	 */
	private const TABLE = 'activitywiki_keys';

	/**
	 * The MediaWiki configuration object, used to read $wgActivityWikiKeySize.
	 */
	private Config $config;

	/**
	 * The database connection provider, injected via the service container.
	 * Gives access to both replica (read) and primary (write) connections.
	 */
	private IConnectionProvider $dbProvider;

	/**
	 * @param Config              $config     Injected MediaWiki config service.
	 * @param IConnectionProvider $dbProvider Injected database connection provider.
	 */
	public function __construct( Config $config, IConnectionProvider $dbProvider ) {
		$this->config = $config;
		$this->dbProvider = $dbProvider;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Returns the stored public key PEM, or null if no key pair exists yet.
	 *
	 * Uses a replica (read-only) connection — the public key is non-sensitive
	 * and will be served on every actor object request, so we want the cheapest
	 * possible read path.
	 *
	 * @return string|null PEM-encoded public key, or null if none stored.
	 */
	public function getPublicKeyPem(): ?string {
		return $this->fetchColumn( 'public_key_pem' );
	}

	/**
	 * Returns the stored private key PEM, or null if no key pair exists yet.
	 *
	 * Uses a replica connection. The private key never leaves the server — it
	 * is only read internally by HttpSigner when signing outbound requests.
	 *
	 * @return string|null PEM-encoded private key, or null if none stored.
	 */
	public function getPrivateKeyPem(): ?string {
		return $this->fetchColumn( 'private_key_pem' );
	}

	/**
	 * Returns true if a key pair already exists in the database.
	 *
	 * Used by the schema updater hook to decide whether to auto-generate keys
	 * on first install, and by GenerateKeys to warn before rotation.
	 *
	 * @return bool
	 */
	public function hasKey(): bool {
		return $this->fetchColumn( 'key_id' ) !== null;
	}

	/**
	 * Generates a new RSA key pair and stores it in the database.
	 *
	 * If a key pair already exists, it is REPLACED (rotation). The caller is
	 * responsible for warning the operator before calling this method during
	 * rotation — see GenerateKeys maintenance script.
	 *
	 * Key size is read from $wgActivityWikiKeySize (default 2048, recommended
	 * 4096). The value is validated to be at least 2048 bits.
	 *
	 * @throws \RuntimeException If OpenSSL key generation fails.
	 * @return void
	 */
	public function generateAndStoreKeyPair(): void {
		$keySize = $this->resolveKeySize();

		[ $privatePem, $publicPem ] = $this->generateKeyPair( $keySize );

		$this->persistKeyPair( $privatePem, $publicPem, $keySize );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Reads $wgActivityWikiKeySize from config and enforces the minimum of 2048.
	 *
	 * If the configured value is below 2048, it is silently clamped to 2048.
	 * This prevents accidentally generating an insecure key if the config is
	 * misconfigured.
	 *
	 * @return int Validated key size in bits.
	 */
	private function resolveKeySize(): int {
		$configured = (int)$this->config->get( 'ActivityWikiKeySize' );

		// Enforce a hard floor — RSA keys below 2048 bits are considered
		// cryptographically weak and must not be generated.
		return max( 2048, $configured );
	}

	/**
	 * Generates an RSA key pair using PHP's built-in OpenSSL extension.
	 *
	 * Returns the private key PEM first, public key PEM second. This order
	 * matches the PHP openssl_pkey_export / openssl_pkey_get_details API.
	 *
	 * @param int $keySize RSA key size in bits (must be >= 2048).
	 * @throws \RuntimeException If OpenSSL fails to generate or export keys.
	 * @return array{string, string} [ $privatePem, $publicPem ]
	 */
	private function generateKeyPair( int $keySize ): array {
		// openssl_pkey_new() accepts a config array. OPENSSL_KEYTYPE_RSA and
		// OPENSSL_ALGO_SHA256 are PHP constants defined by the openssl extension.
		$resource = openssl_pkey_new( [
			'private_key_bits' => $keySize,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		] );

		if ( $resource === false ) {
			throw new \RuntimeException(
				'ActivityWiki: OpenSSL failed to generate RSA key pair. ' .
				'OpenSSL error: ' . openssl_error_string()
			);
		}

		// Export the private key as a PEM string.
		// The second argument is filled by reference; null means no passphrase.
		$privatePem = '';
		$exported = openssl_pkey_export( $resource, $privatePem );

		if ( !$exported || $privatePem === '' ) {
			throw new \RuntimeException(
				'ActivityWiki: OpenSSL failed to export private key PEM. ' .
				'OpenSSL error: ' . openssl_error_string()
			);
		}

		// Extract the public key details. getDetails returns an array that
		// includes 'key' => the public key PEM string.
		$details = openssl_pkey_get_details( $resource );

		if ( $details === false || empty( $details['key'] ) ) {
			throw new \RuntimeException(
				'ActivityWiki: OpenSSL failed to extract public key PEM. ' .
				'OpenSSL error: ' . openssl_error_string()
			);
		}

		$publicPem = $details['key'];

		return [ $privatePem, $publicPem ];
	}

	/**
	 * Persists the key pair to the database.
	 *
	 * Deletes all existing rows first (rotation), then inserts the new pair.
	 * Both operations run inside an explicit transaction to ensure atomicity —
	 * the table is never left in a state where there are zero rows after a
	 * successful call.
	 *
	 * Uses the primary (write) database connection.
	 *
	 * @param string $privatePem PEM-encoded private key.
	 * @param string $publicPem  PEM-encoded public key.
	 * @param int    $keySize    Key size in bits (recorded for auditing).
	 * @return void
	 */
	private function persistKeyPair( string $privatePem, string $publicPem, int $keySize ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->startAtomic( __METHOD__ );

		try {
			// Remove any existing key pair before inserting the new one.
			// In normal operation there is at most one row, but DELETE ALL
			// is safer than UPDATE in case of unexpected duplicates.
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( self::TABLE )
				->where( ISQLPlatform::ALL_ROWS )
				->caller( __METHOD__ )
				->execute();

			// Insert the new key pair with the current UTC timestamp.
			$dbw->newInsertQueryBuilder()
				->insertInto( self::TABLE )
				->row( [
					'public_key_pem'  => $publicPem,
					'private_key_pem' => $privatePem,
					'key_size'        => $keySize,
					// wfTimestampNow() returns the current time in MediaWiki's
					// internal timestamp format (TS_MW: YYYYMMDDHHmmss), which
					// the database abstraction layer converts to the appropriate
					// type for the DBMS in use.
					'generated_at'    => wfTimestampNow(),
				] )
				->caller( __METHOD__ )
				->execute();

			$dbw->endAtomic( __METHOD__ );

		} catch ( \Exception $e ) {
			// Roll back the transaction if anything went wrong, then re-throw
			// so the caller (schema updater or maintenance script) can handle it.
			$dbw->cancelAtomic( __METHOD__ );
			throw $e;
		}
	}

	/**
	 * Fetches a single column value from the most recent key row.
	 *
	 * Since there is always at most one row in the table, ORDER BY and LIMIT
	 * are technically redundant, but are included defensively.
	 *
	 * Uses a replica (read) connection.
	 *
	 * @param string $column Column name to fetch ('public_key_pem', 'private_key_pem', or 'key_id').
	 * @return string|null The column value as a string, or null if no row exists.
	 */
	private function fetchColumn( string $column ): ?string {
		$dbr = $this->dbProvider->getReplicaDatabase();

		$value = $dbr->newSelectQueryBuilder()
			->select( $column )
			->from( self::TABLE )
			->orderBy( 'key_id', 'DESC' )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchField();

		// fetchField() returns false when no rows match.
		// We normalise this to null for a cleaner public API.
		return $value === false ? null : (string)$value;
	}
}