<?php
/**
 * Plugin Name: WpServio
 * Plugin URI:  https://github.com/SylvestreUi/wpservio
 * Description: Service management plugin with integrated chat, order tracking, invoicing, Stripe payments and client notifications for any Custom Post Type.
 * Version:     1.0.0
 * Author:      SylvestreUi
 * Author URI:  https://github.com/SylvestreUi
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpservio
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WPSERVIO_VERSION', '1.0.0' );
define( 'WPSERVIO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPSERVIO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

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
                'slug'                => 'wpservio',
                'type'                => 'plugin',
                'public_key'          => 'pk_b46863119a89c13fd6d225cc981e1',
                'is_premium'          => false,
                'premium_suffix'      => 'Pro',
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'is_org_compliant'    => true,
                'trial'               => array(
                    'days'               => 14,
                    'is_require_payment' => false,
                ),
                'menu'                => array(
                    'slug'           => 'wpservio',
                    'first-path'     => 'admin.php?page=wpservio',
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
function wpservio_is_premium(): bool {
    return ser_fs()->is__premium_only() && ser_fs()->can_use_premium_code();
}

// Stripe PHP SDK
$wpservio_stripe_init = WPSERVIO_PLUGIN_DIR . 'lib/init.php';
if ( file_exists( $wpservio_stripe_init ) ) {
    require_once $wpservio_stripe_init;
}

// Chargement des fichiers
require_once WPSERVIO_PLUGIN_DIR . 'includes/class-wpservio-db.php';
require_once WPSERVIO_PLUGIN_DIR . 'includes/class-wpservio-admin.php';
require_once WPSERVIO_PLUGIN_DIR . 'includes/class-wpservio-ajax.php';
require_once WPSERVIO_PLUGIN_DIR . 'includes/class-wpservio-front.php';
require_once WPSERVIO_PLUGIN_DIR . 'includes/class-wpservio-options.php';
require_once WPSERVIO_PLUGIN_DIR . 'includes/class-wpservio-orders.php';
require_once WPSERVIO_PLUGIN_DIR . 'includes/class-wpservio-dashboard.php';
require_once WPSERVIO_PLUGIN_DIR . 'includes/class-wpservio-account.php';
require_once WPSERVIO_PLUGIN_DIR . 'includes/class-wpservio-notifications.php';
require_once WPSERVIO_PLUGIN_DIR . 'includes/class-wpservio-invoices.php';
require_once WPSERVIO_PLUGIN_DIR . 'includes/class-wpservio-stripe.php';
require_once WPSERVIO_PLUGIN_DIR . 'includes/class-wpservio-payments.php';
require_once WPSERVIO_PLUGIN_DIR . 'includes/class-wpservio-todos.php';
require_once WPSERVIO_PLUGIN_DIR . 'includes/class-wpservio-shortcodes.php';

// Activation
register_activation_hook( __FILE__, function () {
    WpServio_DB::create_table();
    WpServio_Orders::create_table();
    WpServio_Notifications::create_table();
    WpServio_Invoices::create_invoices_table();
    WpServio_Invoices::create_clients_table();
    WpServio_Todos::create_table();
    WpServio_Payments::create_table();
    WpServio_DB::migrate_client_ids();
    if ( ! wp_next_scheduled( 'wpservio_daily_payments' ) ) {
        wp_schedule_event( strtotime( 'tomorrow 08:00:00' ), 'daily', 'wpservio_daily_payments' );
    }
} );

// Désactivation
register_deactivation_hook( __FILE__, function () {
    $ts = wp_next_scheduled( 'wpservio_daily_payments' );
    if ( $ts ) {
        wp_unschedule_event( $ts, 'wpservio_daily_payments' );
    }
} );

// Mise à jour DB pour installations existantes
add_action( 'admin_init', function () {
    if ( get_option( 'wpservio_db_version' ) !== WPSERVIO_VERSION ) {
        WpServio_DB::create_table();
        WpServio_Orders::create_table();
        WpServio_Notifications::create_table();
        WpServio_Invoices::create_invoices_table();
        WpServio_Invoices::create_clients_table();
        WpServio_Todos::create_table();
        WpServio_Payments::create_table();
        WpServio_DB::migrate_client_ids();
        WpServio_Payments::migrate_due_dates();
        update_option( 'wpservio_db_version', WPSERVIO_VERSION );
    }

    // Migration ponctuelle : ajout des colonnes extra_* dans wpservio_orders (v1.0.0 patch)
    if ( ! get_option( 'wpservio_orders_extra_cols' ) ) {
        WpServio_Orders::create_table();
        update_option( 'wpservio_orders_extra_cols', '1' );
    }
    // Migration ponctuelle : ajout de la colonne advanced_options_data dans wpservio_orders
    if ( ! get_option( 'wpservio_orders_advanced_opts_col' ) ) {
        WpServio_Orders::create_table();
        update_option( 'wpservio_orders_advanced_opts_col', '1' );
    }
    if ( ! wp_next_scheduled( 'wpservio_daily_payments' ) ) {
        wp_schedule_event( strtotime( 'tomorrow 08:00:00' ), 'daily', 'wpservio_daily_payments' );
    }
} );

// Avertissement si WP-Cron est désactivé (envoi automatique des paiements ne fonctionnera pas)
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
        echo '<div class="notice notice-warning"><p>'
            . '<strong>WpServio :</strong> '
            . esc_html__( 'WP-Cron est désactivé sur ce site (DISABLE_WP_CRON). L\'envoi automatique des liens de paiement à la date d\'échéance ne fonctionnera pas. Ajoutez une vraie cron job système pointant vers wp-cron.php, ou retirez DISABLE_WP_CRON de wp-config.php.', 'wpservio' )
            . '</p></div>';
    }
} );

// Restreindre l'accès au tableau de bord WP aux non-admins
add_action( 'admin_init', function () {
    if ( wp_doing_ajax() ) {
        return; // Les requêtes AJAX doivent toujours passer
    }
    if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
        wp_safe_redirect( home_url() );
        exit;
    }
} );

add_action( 'after_setup_theme', function () {
    if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
        show_admin_bar( false );
    }
} );

// Initialisation
add_action( 'init', function () {
    WpServio_Dashboard::init();
    WpServio_Admin::init();
    WpServio_Ajax::init();
    WpServio_Front::init();
    WpServio_Options::init();
    WpServio_Orders::init();
    WpServio_Account::init();
    WpServio_Notifications::init();
    WpServio_Invoices::init();
    WpServio_Stripe::init();
    WpServio_Payments::init();
    WpServio_Todos::init();
    WpServio_Shortcodes::init();
} );

// Elementor Dynamic Tags
add_action( 'elementor/dynamic_tags/register', function ( $manager ) {
    require_once WPSERVIO_PLUGIN_DIR . 'includes/class-wpservio-elementor.php';
    WpServio_Elementor::register_dynamic_tags( $manager );
} );

// Elementor Widgets (Elementor Free compatible)
add_action( 'elementor/elements/categories_registered', function ( $elements_manager ) {
    if ( ! class_exists( 'WpServio_Elementor_Widgets' ) ) {
        require_once WPSERVIO_PLUGIN_DIR . 'includes/class-wpservio-elementor-widgets.php';
    }
    WpServio_Elementor_Widgets::add_category( $elements_manager );
} );

add_action( 'elementor/widgets/register', function ( $widgets_manager ) {
    try {
        if ( ! class_exists( 'WpServio_Elementor_Widgets' ) ) {
            require_once WPSERVIO_PLUGIN_DIR . 'includes/class-wpservio-elementor-widgets.php';
        }
        WpServio_Elementor_Widgets::register_widgets( $widgets_manager );
    } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
        // Elementor widget registration failure — silently ignored to avoid fatal errors.
    }
} );
