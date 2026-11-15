<?php
/**
 * Plugin Name: Translate with AI for Polylang
 * Description: Makes Polylang's "Add a translation" (+) create a linked draft that is translated with the WordPress AI Client in the background.
 * Version: 1.0.0
 * Author: Per Soderlind
 * Author URI: https://soderlind.no
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Requires Plugins: polylang
 * Text Domain: translate-with-ai-for-polylang
 *
 * @package TranslateWithAiForPolylang
 */

declare(strict_types=1);

namespace Soderlind\TranslateWithAiForPolylang;

use Soderlind\TranslateWithAiForPolylang\Admin\Admin;
use Soderlind\TranslateWithAiForPolylang\Cli\Command;
use Soderlind\TranslateWithAiForPolylang\Translation\AiClientGenerator;
use Soderlind\TranslateWithAiForPolylang\Translation\TranslationJob;
use Soderlind\TranslateWithAiForPolylang\Translation\Translator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TWAIFP_VERSION', '1.0.0' );

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
		load_plugin_textdomain( 'translate-with-ai-for-polylang', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		$generator = new AiClientGenerator();
		$job       = new TranslationJob( new Translator( $generator ), $generator );
		$job->register();

		if ( ! Polylang::is_active() ) {
			return;
		}

		$creator = new Creator( new Polylang(), $job );

		( new Admin( $creator, $job ) )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'twaifp', new Command( $creator, $job ) );
		}
	},
	20
);
