<?php
/**
 * PHPUnit bootstrap.
 *
 * WordPress functions are mocked per test with Brain Monkey (see TestCase).
 * The HTML API classes come from the johnpbloch/wordpress-core dev dependency.
 *
 * @package AiTranslatorForPolylang\Tests
 */

declare(strict_types=1);

$root = dirname( __DIR__ );

require_once $root . '/vendor/autoload.php';
require_once $root . '/vendor/johnpbloch/wordpress-core/wp-includes/html-api/html5-named-character-references.php';
require_once __DIR__ . '/php/wp-stubs.php';
require_once __DIR__ . '/php/ai-client-stubs.php';
