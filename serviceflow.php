<?php
/**
 * Plugin Name: ServiceFlow
 * Plugin URI:  https://github.com/SylvestreUi/serviceflow
 * Description: Service management plugin with integrated chat, order tracking, invoicing, Stripe payments and client notifications for any Custom Post Type.
 * Version:     1.0.0
 * Author:      SylvestreUi
 * Author URI:  https://github.com/SylvestreUi
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: serviceflow
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SERVICEFLOW_VERSION', '1.0.0' );
define( 'SERVICEFLOW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SERVICEFLOW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/* ================================================================
 *  FREEMIUS SDK
 * ================================================================ */

if ( ! function_exists( 'ser_fs' ) ) {
    function ser_fs() {
        global $ser_fs;

        if ( ! isset( $ser_fs ) ) {
            require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';

            $ser_fs = fs_dynamic_init( array(
                'id'                  => '24444',
                'slug'                => 'serviceflow',
                'type'                => 'plugin',
                'public_key'          => 'pk_b46863119a89c13fd6d225cc981e1',
                'is_premium'          => true,
                'premium_suffix'      => 'Pro',
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'trial'               => array(
                    'days'               => 7,
                    'is_require_payment' => false,
                ),
                'menu'                => array(
                    'slug'           => 'serviceflow',
                    'first-path'     => 'admin.php?page=serviceflow',
                    'support'        => false,
                ),
            ) );
        }

        return $ser_fs;
    }

    ser_fs();
    do_action( 'ser_fs_loaded' );
}

/**
 * Vérifie si le plan premium est actif.
 */
function serviceflow_is_premium(): bool {
    return ser_fs()->is__premium_only() && ser_fs()->can_use_premium_code();
}

// Stripe PHP SDK
$serviceflow_stripe_init = SERVICEFLOW_PLUGIN_DIR . 'lib/init.php';
if ( file_exists( $serviceflow_stripe_init ) ) {
    require_once $serviceflow_stripe_init;
}

// Chargement des fichiers
require_once SERVICEFLOW_PLUGIN_DIR . 'includes/class-serviceflow-db.php';
require_once SERVICEFLOW_PLUGIN_DIR . 'includes/class-serviceflow-admin.php';
require_once SERVICEFLOW_PLUGIN_DIR . 'includes/class-serviceflow-ajax.php';
require_once SERVICEFLOW_PLUGIN_DIR . 'includes/class-serviceflow-front.php';
require_once SERVICEFLOW_PLUGIN_DIR . 'includes/class-serviceflow-options.php';
require_once SERVICEFLOW_PLUGIN_DIR . 'includes/class-serviceflow-orders.php';
require_once SERVICEFLOW_PLUGIN_DIR . 'includes/class-serviceflow-dashboard.php';
require_once SERVICEFLOW_PLUGIN_DIR . 'includes/class-serviceflow-account.php';
require_once SERVICEFLOW_PLUGIN_DIR . 'includes/class-serviceflow-notifications.php';
require_once SERVICEFLOW_PLUGIN_DIR . 'includes/class-serviceflow-invoices.php';
require_once SERVICEFLOW_PLUGIN_DIR . 'includes/class-serviceflow-stripe.php';
require_once SERVICEFLOW_PLUGIN_DIR . 'includes/class-serviceflow-payments.php';
require_once SERVICEFLOW_PLUGIN_DIR . 'includes/class-serviceflow-todos.php';
require_once SERVICEFLOW_PLUGIN_DIR . 'includes/class-serviceflow-shortcodes.php';

// Activation
register_activation_hook( __FILE__, function () {
    ServiceFlow_DB::create_table();
    ServiceFlow_Orders::create_table();
    ServiceFlow_Notifications::create_table();
    ServiceFlow_Invoices::create_invoices_table();
    ServiceFlow_Invoices::create_clients_table();
    ServiceFlow_Todos::create_table();
    ServiceFlow_Payments::create_table();
    ServiceFlow_DB::migrate_client_ids();
    if ( ! wp_next_scheduled( 'serviceflow_daily_payments' ) ) {
        wp_schedule_event( strtotime( 'tomorrow 08:00:00' ), 'daily', 'serviceflow_daily_payments' );
    }
} );

// Désactivation
register_deactivation_hook( __FILE__, function () {
    $ts = wp_next_scheduled( 'serviceflow_daily_payments' );
    if ( $ts ) {
        wp_unschedule_event( $ts, 'serviceflow_daily_payments' );
    }
} );

// Mise à jour DB pour installations existantes
add_action( 'admin_init', function () {
    if ( get_option( 'serviceflow_db_version' ) !== SERVICEFLOW_VERSION ) {
        ServiceFlow_DB::create_table();
        ServiceFlow_Orders::create_table();
        ServiceFlow_Notifications::create_table();
        ServiceFlow_Invoices::create_invoices_table();
        ServiceFlow_Invoices::create_clients_table();
        ServiceFlow_Todos::create_table();
        ServiceFlow_Payments::create_table();
        ServiceFlow_DB::migrate_client_ids();
        ServiceFlow_Payments::migrate_due_dates();
        update_option( 'serviceflow_db_version', SERVICEFLOW_VERSION );
    }
    if ( ! wp_next_scheduled( 'serviceflow_daily_payments' ) ) {
        wp_schedule_event( strtotime( 'tomorrow 08:00:00' ), 'daily', 'serviceflow_daily_payments' );
    }
} );

// Avertissement si WP-Cron est désactivé (envoi automatique des paiements ne fonctionnera pas)
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
        echo '<div class="notice notice-warning"><p>'
            . '<strong>ServiceFlow :</strong> '
            . esc_html__( 'WP-Cron est désactivé sur ce site (DISABLE_WP_CRON). L\'envoi automatique des liens de paiement à la date d\'échéance ne fonctionnera pas. Ajoutez une vraie cron job système pointant vers wp-cron.php, ou retirez DISABLE_WP_CRON de wp-config.php.', 'serviceflow' )
            . '</p></div>';
    }
} );

// Traductions
add_action( 'init', function () {
    load_plugin_textdomain( 'serviceflow', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}, 5 );

// Initialisation
add_action( 'init', function () {
    ServiceFlow_Dashboard::init();
    ServiceFlow_Admin::init();
    ServiceFlow_Ajax::init();
    ServiceFlow_Front::init();
    ServiceFlow_Options::init();
    ServiceFlow_Orders::init();
    ServiceFlow_Account::init();
    ServiceFlow_Notifications::init();
    ServiceFlow_Invoices::init();
    ServiceFlow_Stripe::init();
    ServiceFlow_Payments::init();
    ServiceFlow_Todos::init();
    ServiceFlow_Shortcodes::init();
} );

// Elementor Dynamic Tags
add_action( 'elementor/dynamic_tags/register', function ( $manager ) {
    require_once SERVICEFLOW_PLUGIN_DIR . 'includes/class-serviceflow-elementor.php';
    ServiceFlow_Elementor::register_dynamic_tags( $manager );
} );

// Elementor Widgets (Elementor Free compatible)
add_action( 'elementor/elements/categories_registered', function ( $elements_manager ) {
    if ( ! class_exists( 'ServiceFlow_Elementor_Widgets' ) ) {
        require_once SERVICEFLOW_PLUGIN_DIR . 'includes/class-serviceflow-elementor-widgets.php';
    }
    ServiceFlow_Elementor_Widgets::add_category( $elements_manager );
} );

add_action( 'elementor/widgets/register', function ( $widgets_manager ) {
    try {
        if ( ! class_exists( 'ServiceFlow_Elementor_Widgets' ) ) {
            require_once SERVICEFLOW_PLUGIN_DIR . 'includes/class-serviceflow-elementor-widgets.php';
        }
        ServiceFlow_Elementor_Widgets::register_widgets( $widgets_manager );
    } catch ( \Throwable $e ) {
        error_log( 'ServiceFlow widgets error: ' . $e->getMessage() );
    }
} );
