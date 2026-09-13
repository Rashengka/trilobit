<?php

declare(strict_types=1);

/*
 * Loaded before every script by the server playwright.config.ts starts for the
 * browser suite - `php -d auto_prepend_file=...` - and by nothing else. On one
 * path it answers which checkout the server belongs to, so that the suite can
 * take over a server that is already running only when it is this checkout's
 * (tests/e2e/checkout.mjs). Every other request passes through untouched.
 *
 * A prepended file rather than a router script, because a router changes how
 * PHP's server fills in SCRIPT_NAME and PHP_SELF, which the application reads
 * its base path from; this changes nothing about any other request. And a file
 * outside www/ and outside the application, so that a production deployment
 * does not have it at all: nothing but the command line of the suite's server
 * loads it.
 *
 * On top of that it answers only under PHP's own development server and only
 * in dev, as the suite starts it. The mode is read from the process
 * environment and not from .env, because this runs before the application does
 * and the suite's server is given the mode on its command line.
 *
 * The answer is the SHA-256 of the checkout's real path, not the path: what a
 * directory is called on a developer's disk is nobody's business, and equality
 * is all the suite needs.
 */

$trilobitE2eUri = $_SERVER['REQUEST_URI'] ?? null;
$trilobitE2eRoot = realpath(dirname(__DIR__, 2));

if (
    PHP_SAPI === 'cli-server'
    && getenv('TRILOBIT_ENV') === 'dev'
    && is_string($trilobitE2eUri)
    && parse_url($trilobitE2eUri, PHP_URL_PATH) === '/__trilobit-e2e-checkout'
    && $trilobitE2eRoot !== false
) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo hash('sha256', $trilobitE2eRoot);
    exit;
}

// The variables are global scope of every request the server handles; they are
// not left for the application to trip over.
unset($trilobitE2eUri, $trilobitE2eRoot);
