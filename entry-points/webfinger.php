<?php
/**
 * webfinger.php — Dedicated entry point for WebFinger discovery requests.
 *
 * This file is part of the ActivityWiki MediaWiki extension.
 * It must be placed in the same directory as rest.php (e.g. /var/www/mw/wt/).
 *
 * WHY THIS FILE EXISTS:
 * The WebFinger protocol (RFC 7033) requires that discovery requests arrive at:
 *   /.well-known/webfinger
 * at the root of the domain. MediaWiki's REST router validates that REQUEST_URI
 * starts with the REST base path (e.g. /wt/rest.php). When Apache rewrites
 * /.well-known/webfinger to rest.php, REQUEST_URI still contains the original
 * path and the router throws a "rest-prefix-mismatch" error.
 *
 * This entry point solves the problem cleanly by rewriting REQUEST_URI to the
 * correct REST path BEFORE MediaWiki boots — so the router sees a normal
 * REST request and routes it to WebFingerHandler as expected.
 *
 * INSTALLATION:
 * 1. Place this file alongside rest.php in your MediaWiki script directory.
 * 2. Add the following Apache rewrite rule to your VirtualHost config,
 *    before the existing MediaWiki rewrite rules:
 *
 *      RewriteRule ^/\.well-known/webfinger$ %{DOCUMENT_ROOT}/wt/webfinger.php [QSA,L]
 *
 *    Replace /wt/ with your $wgScriptPath if different.
 *
 * 3. For Nginx, add to your server block:
 *
 *      location = /.well-known/webfinger {
 *          fastcgi_param REQUEST_URI /wt/rest.php/activitywiki/webfinger;
 *          # ... your existing fastcgi_pass settings
 *      }
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

// Rewrite REQUEST_URI to the MediaWiki REST path for the WebFinger route.
// This must happen before rest.php bootstraps MediaWiki, because the REST
// router reads REQUEST_URI via RequestFromGlobals during initialisation.
//
// We preserve the query string (e.g. ?resource=acct:user@domain) by
// appending it to the rewritten URI — the WebFingerHandler reads it via
// getValidatedParams(), which also reads from the server globals.
$_SERVER['REQUEST_URI'] = '/wt/rest.php/activitywiki/webfinger'
    . ( isset( $_SERVER['QUERY_STRING'] ) && $_SERVER['QUERY_STRING'] !== ''
        ? '?' . $_SERVER['QUERY_STRING']
        : '' );

// Bootstrap MediaWiki's REST API exactly as rest.php would.
// All authentication, routing, and response handling happens inside rest.php.
require_once __DIR__ . '/rest.php';