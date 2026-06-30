<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\ActivityWiki;

use MediaWiki\Config\Config;

/**
 * WikiActorUrls — single source of truth for this wiki's own ActivityPub
 * self-identifying URLs.
 * ...
 */
class WikiActorUrls {

	private const ACTOR_PATH = 'rest.php/activitywiki/actor';

	private Config $config;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	public function getWikiUrl(): string {
		$server     = $this->config->get( 'Server' );
		$scriptPath = $this->config->get( 'ScriptPath' );
		return rtrim( $server, '/' ) . $scriptPath . '/';
	}

	public function getWikiActorUrl(): string {
		return $this->getWikiUrl() . self::ACTOR_PATH;
	}
}