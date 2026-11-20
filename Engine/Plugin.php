<?php

/**
 * Plugin bootstrap with an explicit module list.
 *
 * @package PrimePlaced\WithdrawalButton
 */
namespace PrimePlaced\WithdrawalButton\Engine;

use PrimePlaced\WithdrawalButton\Backend\AdminPage;
use PrimePlaced\WithdrawalButton\Backend\DashboardWidget;
use PrimePlaced\WithdrawalButton\Backend\ProductEligibility;
use PrimePlaced\WithdrawalButton\Backend\OrderBox;
use PrimePlaced\WithdrawalButton\Backend\RequestScreen;
use PrimePlaced\WithdrawalButton\Backend\SettingsTab;
use PrimePlaced\WithdrawalButton\Backend\SetupWizard;
use PrimePlaced\WithdrawalButton\Blocks\Shortcodes;
use PrimePlaced\WithdrawalButton\Data\SchemaUpgrader;
use PrimePlaced\WithdrawalButton\Emails\Mailer;
use PrimePlaced\WithdrawalButton\Frontend\MyAccount;
use PrimePlaced\WithdrawalButton\Frontend\ProductExclusions;
use PrimePlaced\WithdrawalButton\Frontend\StatusPage;
use PrimePlaced\WithdrawalButton\Frontend\WithdrawalForm;
use PrimePlaced\WithdrawalButton\Integrations\OrderIntegration;
use PrimePlaced\WithdrawalButton\Privacy\PersonalData;
use PrimePlaced\WithdrawalButton\Rest\RequestsController;
use Throwable;
/**
 * Class Plugin
 *
 * Every module is listed here by hand. There is no directory scanning: adding
 * a module means adding its class to the right group.
 */
final class Plugin {
    /**
     * Container shared by all modules.
     *
     * @var Container
     */
    private Container $container;

    /**
     * Creates the plugin with its container.
     *
     * @param Container|null $container Optional container, for tests.
     */
    public function __construct( ?Container $container = null ) {
        $this->container = $container ?? new Container();
    }

    /**
     * Core (Free) modules per context group.
     *
     * @return array<string, list<class-string<Module>>>
     */
    public static function modules() : array {
        return array(
            Context::CORE     => array(
                SchemaUpgrader::class,
                Mailer::class,
                OrderIntegration::class,
                MyAccount::class,
                ProductExclusions::class,
                Shortcodes::class,
                PersonalData::class
            ),
            Context::ADMIN    => array(
                AdminPage::class,
                RequestScreen::class,
                OrderBox::class,
                SettingsTab::class,
                SetupWizard::class,
                ProductEligibility::class,
                DashboardWidget::class
            ),
            Context::FRONTEND => array(WithdrawalForm::class, StatusPage::class),
            Context::REST     => array(RequestsController::class),
        );
    }

    /**
     * Registers the modules needed by the current request.
     *
     * @param list<string>|null $groups Context groups; detected when null.
     * @throws Throwable When a module fails to register and WP_DEBUG is on.
     * @return void
     */
    public function boot( ?array $groups = null ) : void {
        $groups ??= ( new Context() )->groups();
        $modules = self::modules();
        // Keep the premium check alone in its condition, as in the sibling
        // plugins, so the Freemius preprocessor strips the whole block. The
        // premium build asks ProModules even without a license: it then returns
        // only the cleanup after a license that ended.
        if ( function_exists( 'ppwb_fs' ) && null !== ppwb_fs() ) {
        }
        foreach ( $groups as $group ) {
            foreach ( $modules[$group] ?? array() as $module_class ) {
                $this->register_module( $module_class );
            }
        }
    }

    /**
     * Builds and registers one module. A failure is logged and shown to
     * administrators; with WP_DEBUG it is rethrown.
     *
     * @param class-string<Module> $module_class Module class.
     * @throws Throwable When registration fails and WP_DEBUG is on.
     * @return void
     */
    private function register_module( string $module_class ) : void {
        try {
            $this->container->get( $module_class )->register();
        } catch ( Throwable $error ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                throw $error;
            }
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Boot failures must reach the server log.
            error_log( sprintf( '[primeplaced-withdrawal-button] Module %s failed: %s', $module_class, $error->getMessage() ) );
            add_action( 'admin_notices', static function () use($module_class) : void {
                if ( !current_user_can( 'manage_options' ) ) {
                    return;
                }
                printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( sprintf( 
                    /* translators: 1: plugin name, 2: module class name. */
                    __( '%1$s: module %2$s failed to start. Check the server error log.', 'primeplaced-withdrawal-button' ),
                    \PrimePlaced\WithdrawalButton\Backend\BrandBanner::name(),
                    $module_class
                 ) ) );
            } );
        }
    }

}
