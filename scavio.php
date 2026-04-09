<?php
/**
 * Plugin Name: Scavio
 * Plugin URI:  https://github.com/SylvestreUi/scavio
 * Description: Service management plugin with integrated chat, order tracking, invoicing, Stripe payments and client notifications for any Custom Post Type.
 * Version:     1.0.0
 * Author:      SylvestreUi
 * Author URI:  https://github.com/SylvestreUi
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: scavio
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SCAVIO_VERSION', '1.0.0' );
define( 'SCAVIO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCAVIO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

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
                'slug'                => 'scavio',
                'premium_slug'        => 'scavio-premium',
                'type'                => 'plugin',
                'public_key'          => 'pk_b46863119a89c13fd6d225cc981e1',
                'is_premium'          => false,
                'premium_suffix'      => 'Pro',
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'is_org_compliant'    => true,
                // PREMIUM ONLY — uncomment for premium zip (remove before uploading to wp.org):
                // 'wp_org_gatekeeper' => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
                'trial'               => array(
                    'days'               => 14,
                    'is_require_payment' => false,
                ),
                'menu'                => array(
                    'slug'           => 'scavio',
                    'first-path'     => 'admin.php?page=scavio',
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
function scavio_is_premium(): bool {
    return ser_fs()->is__premium_only() && ser_fs()->can_use_premium_code();
}

// Stripe PHP SDK
$scavio_stripe_init = SCAVIO_PLUGIN_DIR . 'lib/init.php';
if ( file_exists( $scavio_stripe_init ) ) {
    require_once $scavio_stripe_init;
}

// Chargement des fichiers
require_once SCAVIO_PLUGIN_DIR . 'includes/class-scavio-db.php';
require_once SCAVIO_PLUGIN_DIR . 'includes/class-scavio-admin.php';
require_once SCAVIO_PLUGIN_DIR . 'includes/class-scavio-ajax.php';
require_once SCAVIO_PLUGIN_DIR . 'includes/class-scavio-front.php';
require_once SCAVIO_PLUGIN_DIR . 'includes/class-scavio-options.php';
require_once SCAVIO_PLUGIN_DIR . 'includes/class-scavio-orders.php';
require_once SCAVIO_PLUGIN_DIR . 'includes/class-scavio-dashboard.php';
require_once SCAVIO_PLUGIN_DIR . 'includes/class-scavio-account.php';
require_once SCAVIO_PLUGIN_DIR . 'includes/class-scavio-notifications.php';
require_once SCAVIO_PLUGIN_DIR . 'includes/class-scavio-invoices.php';
require_once SCAVIO_PLUGIN_DIR . 'includes/class-scavio-stripe.php';
require_once SCAVIO_PLUGIN_DIR . 'includes/class-scavio-payments.php';
require_once SCAVIO_PLUGIN_DIR . 'includes/class-scavio-todos.php';
require_once SCAVIO_PLUGIN_DIR . 'includes/class-scavio-shortcodes.php';

// Activation
register_activation_hook( __FILE__, function () {
    Scavio_DB::create_table();
    Scavio_Orders::create_table();
    Scavio_Notifications::create_table();
    Scavio_Invoices::create_invoices_table();
    Scavio_Invoices::create_clients_table();
    Scavio_Todos::create_table();
    Scavio_Payments::create_table();
    Scavio_DB::migrate_client_ids();
    if ( ! wp_next_scheduled( 'scavio_daily_payments' ) ) {
        wp_schedule_event( strtotime( 'tomorrow 08:00:00' ), 'daily', 'scavio_daily_payments' );
    }
} );

// Désactivation
register_deactivation_hook( __FILE__, function () {
    $ts = wp_next_scheduled( 'scavio_daily_payments' );
    if ( $ts ) {
        wp_unschedule_event( $ts, 'scavio_daily_payments' );
    }
} );

// Mise à jour DB pour installations existantes
add_action( 'admin_init', function () {
    if ( get_option( 'scavio_db_version' ) !== SCAVIO_VERSION ) {
        Scavio_DB::create_table();
        Scavio_Orders::create_table();
        Scavio_Notifications::create_table();
        Scavio_Invoices::create_invoices_table();
        Scavio_Invoices::create_clients_table();
        Scavio_Todos::create_table();
        Scavio_Payments::create_table();
        Scavio_DB::migrate_client_ids();
        Scavio_Payments::migrate_due_dates();
        update_option( 'scavio_db_version', SCAVIO_VERSION );
    }

    // Migration ponctuelle : ajout des colonnes extra_* dans scavio_orders (v1.0.0 patch)
    if ( ! get_option( 'scavio_orders_extra_cols' ) ) {
        Scavio_Orders::create_table();
        update_option( 'scavio_orders_extra_cols', '1' );
    }
    // Migration ponctuelle : ajout de la colonne advanced_options_data dans scavio_orders
    if ( ! get_option( 'scavio_orders_advanced_opts_col' ) ) {
        Scavio_Orders::create_table();
        update_option( 'scavio_orders_advanced_opts_col', '1' );
    }
    if ( ! wp_next_scheduled( 'scavio_daily_payments' ) ) {
        wp_schedule_event( strtotime( 'tomorrow 08:00:00' ), 'daily', 'scavio_daily_payments' );
    }
} );

// Avertissement si WP-Cron est désactivé (envoi automatique des paiements ne fonctionnera pas)
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
        echo '<div class="notice notice-warning"><p>'
            . '<strong>Scavio :</strong> '
            . esc_html__( 'WP-Cron est désactivé sur ce site (DISABLE_WP_CRON). L\'envoi automatique des liens de paiement à la date d\'échéance ne fonctionnera pas. Ajoutez une vraie cron job système pointant vers wp-cron.php, ou retirez DISABLE_WP_CRON de wp-config.php.', 'scavio' )
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
    Scavio_Dashboard::init();
    Scavio_Admin::init();
    Scavio_Ajax::init();
    Scavio_Front::init();
    Scavio_Options::init();
    Scavio_Orders::init();
    Scavio_Account::init();
    Scavio_Notifications::init();
    Scavio_Invoices::init();
    Scavio_Stripe::init();
    Scavio_Payments::init();
    Scavio_Todos::init();
    Scavio_Shortcodes::init();
} );

// Elementor Dynamic Tags
add_action( 'elementor/dynamic_tags/register', function ( $manager ) {
    require_once SCAVIO_PLUGIN_DIR . 'includes/class-scavio-elementor.php';
    Scavio_Elementor::register_dynamic_tags( $manager );
} );

// Elementor Widgets (Elementor Free compatible)
add_action( 'elementor/elements/categories_registered', function ( $elements_manager ) {
    if ( ! class_exists( 'Scavio_Elementor_Widgets' ) ) {
        require_once SCAVIO_PLUGIN_DIR . 'includes/class-scavio-elementor-widgets.php';
    }
    Scavio_Elementor_Widgets::add_category( $elements_manager );
} );

add_action( 'elementor/widgets/register', function ( $widgets_manager ) {
    try {
        if ( ! class_exists( 'Scavio_Elementor_Widgets' ) ) {
            require_once SCAVIO_PLUGIN_DIR . 'includes/class-scavio-elementor-widgets.php';
        }
        Scavio_Elementor_Widgets::register_widgets( $widgets_manager );
    } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
        // Elementor widget registration failure — silently ignored to avoid fatal errors.
    }
} );
