<?php

/**
 * Activation, deactivation and uninstall.
 *
 * @package PrimePlaced\WithdrawalButton
 */
namespace PrimePlaced\WithdrawalButton\Backend;

use PrimePlaced\WithdrawalButton\Data\Schema;
use PrimePlaced\WithdrawalButton\Data\SchemaUpgrader;
use PrimePlaced\WithdrawalButton\Data\Settings;
use PrimePlaced\WithdrawalButton\Emails\Mailer;
use PrimePlaced\WithdrawalButton\Frontend\MyAccount;
use PrimePlaced\WithdrawalButton\Frontend\StatusPage;
use PrimePlaced\WithdrawalButton\Pdf\PrivateStorage;
/**
 * Class Lifecycle
 */
final class Lifecycle {
    public const CAP_MANAGE_REQUESTS = 'ppwb_manage_requests';

    public const CAP_MANAGE_SETTINGS = 'ppwb_manage_settings';

    public const SETTINGS_OPTION = 'ppwb_settings';

    /**
     * Capabilities per role: shop managers handle requests, only
     * administrators change settings.
     *
     * @var array<string, list<string>>
     */
    private const ROLE_CAPS = array(
        'administrator' => array(self::CAP_MANAGE_REQUESTS, self::CAP_MANAGE_SETTINGS),
        'shop_manager'  => array(self::CAP_MANAGE_REQUESTS),
    );

    /**
     * Creates tables, pages and capabilities.
     *
     * @return void
     */
    public static function activate() : void {
        SchemaUpgrader::upgrade();
        Mailer::schedule_daily();
        self::ensure_page(
            Settings::FORM_PAGE_OPTION,
            __( 'Withdrawal from contract', 'primeplaced-withdrawal-button' ),
            _x( 'withdrawal-from-contract', 'page slug', 'primeplaced-withdrawal-button' ),
            '[ppwb_form]'
        );
        self::ensure_page(
            StatusPage::PAGE_OPTION,
            __( 'Return status', 'primeplaced-withdrawal-button' ),
            _x( 'return-status', 'page slug', 'primeplaced-withdrawal-button' ),
            '[ppwb_status]'
        );
        foreach ( self::ROLE_CAPS as $role_name => $caps ) {
            $role = get_role( $role_name );
            if ( null === $role ) {
                continue;
            }
            $role->remove_cap( self::CAP_MANAGE_SETTINGS );
            foreach ( $caps as $cap ) {
                $role->add_cap( $cap );
            }
        }
        MyAccount::add_endpoint();
        flush_rewrite_rules();
        if ( 'yes' !== ( new Settings() )->get( 'wizard_done' ) ) {
            set_transient( 'ppwb_activation_redirect', 1, 60 );
        }
    }

    /**
     * Creates a page with a shortcode unless it exists.
     *
     * @param string $option    Option holding the page ID.
     * @param string $title     Page title.
     * @param string $slug      Page slug.
     * @param string $shortcode Shortcode.
     * @return void
     */
    private static function ensure_page(
        string $option,
        string $title,
        string $slug,
        string $shortcode
    ) : void {
        $page_id = (int) get_option( $option, 0 );
        $status = ( $page_id > 0 ? get_post_status( $page_id ) : false );
        if ( false !== $status && 'trash' !== $status ) {
            return;
        }
        $page_id = wp_insert_post( array(
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_content' => '<!-- wp:shortcode -->' . $shortcode . '<!-- /wp:shortcode -->',
        ) );
        if ( is_int( $page_id ) && $page_id > 0 ) {
            update_option( $option, $page_id, false );
        }
    }

    /**
     * Deactivation keeps all data; it only stops scheduled jobs.
     *
     * @return void
     */
    public static function deactivate() : void {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( '', array(), 'ppwb' );
        }
        flush_rewrite_rules();
    }

    /**
     * Removes data only when the shop enabled "Delete data on uninstall".
     *
     * @return void
     */
    public static function uninstall() : void {
        if ( !( new Settings() )->flag( 'delete_data_on_uninstall' ) ) {
            return;
        }
        Schema::drop();
        ( new PrivateStorage() )->delete_all();
        // Pro tables and options go too, also when the license has ended.
        if ( function_exists( 'ppwb_fs' ) && null !== ppwb_fs() ) {
        }
        foreach ( array(Settings::FORM_PAGE_OPTION, StatusPage::PAGE_OPTION) as $option ) {
            wp_delete_post( (int) get_option( $option, 0 ), true );
            delete_option( $option );
        }
        delete_option( self::SETTINGS_OPTION );
        Mailer::delete_submission_locks( 0 );
        foreach ( self::ROLE_CAPS as $role_name => $caps ) {
            $role = get_role( $role_name );
            if ( null === $role ) {
                continue;
            }
            foreach ( array(self::CAP_MANAGE_REQUESTS, self::CAP_MANAGE_SETTINGS) as $cap ) {
                $role->remove_cap( $cap );
            }
        }
    }

}
