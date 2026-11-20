<?php

/**
 * The main plugin file.
 *
 * @package           PrimePlaced\WithdrawalButton
 * @author            Dobrzepostawieni
 * @copyright         2026 Dobrzepostawieni.pl
 * @license           GPL-3.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       PrimePlaced - Withdrawal Button & Refunds for WooCommerce
 * Plugin URI:        https://dobrzepostawieni.pl/withdrawal-button/
 * Description:       Withdrawal button and returns for WooCommerce: EU right of withdrawal form, confirmation email with PDF, returns panel and refunds.
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Version:           1.0.0
 * Author:            Dobrzepostawieni
 * Author URI:        https://dobrzepostawieni.pl
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       primeplaced-withdrawal-button
 * Domain Path:       /languages
 * WC requires at least: 8.5
 *
 */
// Prevent direct file access.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
// With Free and Pro both installed, the copy loaded second only tells Freemius
// which file it is; the copy loaded first runs the plugin.
// Keep the exact ppwb_fs()->set_basename( true, __FILE__ ) form: the Freemius Free
// build rewrites it to false.
if ( function_exists( 'ppwb_fs' ) ) {
    if ( null !== ppwb_fs() ) {
        ppwb_fs()->set_basename( false, __FILE__ );
    }
    return;
}
define( 'PPWB_VERSION', '1.0.0' );
define( 'PPWB_PLUGIN_ROOT', plugin_dir_path( __FILE__ ) );
define( 'PPWB_PLUGIN_ABSOLUTE', __FILE__ );
define( 'PPWB_API_NAMESPACE', 'ppwb/v1' );
// Freemius product credentials (Freemius dashboard > Settings > Keys). wp-config.php
// may define them first, for example as empty strings to run without Freemius.
if ( !defined( 'PPWB_FS_ID' ) ) {
    define( 'PPWB_FS_ID', '44179' );
}
if ( !defined( 'PPWB_FS_PUBLIC_KEY' ) ) {
    define( 'PPWB_FS_PUBLIC_KEY', 'pk_d62a14f578acc2143b0c639114154' );
}
require PPWB_PLUGIN_ROOT . 'vendor-prefixed/autoload.php';
require PPWB_PLUGIN_ROOT . 'vendor/autoload.php';
// Declared conditionally: an unconditional declaration would be hoisted above the early return.
if ( !function_exists( 'ppwb_fs' ) ) {
    /**
     * Freemius SDK accessor. Returns null until the product credentials are configured.
     *
     * @return \Freemius|null
     */
    function ppwb_fs() : ?\Freemius {
        global $ppwb_fs;
        // Read through constant() so static analysis does not fold the placeholder into dead code.
        if ( '' === (string) constant( 'PPWB_FS_ID' ) ) {
            return null;
        }
        if ( !isset( $ppwb_fs ) ) {
            require_once PPWB_PLUGIN_ROOT . 'vendor/freemius/wordpress-sdk/start.php';
            $ppwb_fs = fs_dynamic_init( array(
                'id'               => PPWB_FS_ID,
                'slug'             => 'primeplaced-withdrawal-button',
                'premium_slug'     => 'primeplaced-withdrawal-button-pro',
                'type'             => 'plugin',
                'public_key'       => PPWB_FS_PUBLIC_KEY,
                'is_premium'       => false,
                'premium_suffix'   => 'Pro',
                'has_addons'       => false,
                'has_paid_plans'   => true,
                'is_org_compliant' => true,
                'menu'             => array(
                    'slug'    => 'ppwb-returns',
                    'parent'  => array(
                        'slug' => 'woocommerce',
                    ),
                    'contact' => false,
                    'support' => false,
                    'account' => true,
                    'pricing' => false,
                ),
                'is_live'          => true,
            ) );
        }
        return $ppwb_fs;
    }

    ppwb_fs();
}
add_action( 
    'before_woocommerce_init',
    /**
     * Declares compatibility with HPOS and the block checkout.
     *
     * @return void
     */
    static function () : void {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
        }
    }
 );
register_activation_hook( __FILE__, array(\PrimePlaced\WithdrawalButton\Backend\Lifecycle::class, 'activate') );
register_deactivation_hook( __FILE__, array(\PrimePlaced\WithdrawalButton\Backend\Lifecycle::class, 'deactivate') );
// Freemius owns the uninstall hook when it is configured; otherwise use the WordPress one.
if ( null !== ppwb_fs() ) {
    ppwb_fs()->add_action( 'after_uninstall', array(\PrimePlaced\WithdrawalButton\Backend\Lifecycle::class, 'uninstall') );
} else {
    register_uninstall_hook( __FILE__, array(\PrimePlaced\WithdrawalButton\Backend\Lifecycle::class, 'uninstall') );
}
add_action( 
    'plugins_loaded',
    /**
     * Boots the plugin once WooCommerce is available.
     *
     * @return void
     */
    static function () : void {
        if ( !class_exists( \WooCommerce::class ) ) {
            return;
        }
        ( new \PrimePlaced\WithdrawalButton\Engine\Plugin() )->boot();
    }
 );