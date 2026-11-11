<?php
/**
 * Plugin Name: AI Translator for Polylang
 * Description: Makes Polylang's "Add a translation" (+) create a linked draft that is translated with the WordPress AI Client in the background.
 * Version: 0.1.1
 * Author: Per Soderlind
 * Author URI: https://soderlind.no
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Requires Plugins: polylang
 * Text Domain: ai-translator-for-polylang
 *
 * @package AiTranslatorForPolylang
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang;

use AiTranslatorForPolylang\Admin\Admin;
use AiTranslatorForPolylang\Cli\Command;
use AiTranslatorForPolylang\Translation\TranslationJob;
use AiTranslatorForPolylang\Translation\Translator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AITFP_VERSION', '0.1.1' );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			if ( str_starts_with( $class_name, __NAMESPACE__ . '\\' ) ) {
				$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( __NAMESPACE__ ) + 1 ) ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		}
	);
}

add_action(
	'plugins_loaded',
	static function (): void {
		load_plugin_textdomain( 'ai-translator-for-polylang', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		$job = new TranslationJob( new Translator() );
		$job->register();

		if ( ! Polylang::is_active() ) {
			return;
		}

		$creator = new Creator( new Polylang(), $job );

		( new Admin( $creator, $job ) )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'aitfp', new Command( $creator, $job ) );
		}
	},
	20
);
