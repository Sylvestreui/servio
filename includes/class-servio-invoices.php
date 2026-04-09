<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Servio_Invoices {

    const STATUS_DRAFT     = 'draft';
    const STATUS_PENDING   = 'pending';
    const STATUS_VALIDATED = 'validated';
    const STATUS_PAID      = 'paid';
    const STATUS_CANCELLED = 'cancelled';

    public static function init(): void {
        if ( ! servio_is_premium() ) {
            return;
        }

        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_scripts' ] );

        // Auto-génération de facture quand commande acceptée
        add_action( 'servio_order_status_changed', [ __CLASS__, 'on_order_accepted' ], 10, 4 );

        // AJAX admin
        add_action( 'wp_ajax_servio_invoice_validate',  [ __CLASS__, 'ajax_validate' ] );
        add_action( 'wp_ajax_servio_invoice_mark_paid', [ __CLASS__, 'ajax_mark_paid' ] );
        add_action( 'wp_ajax_servio_invoice_cancel',    [ __CLASS__, 'ajax_cancel' ] );
        add_action( 'wp_ajax_servio_invoice_save',      [ __CLASS__, 'ajax_save_invoice' ] );
        add_action( 'wp_ajax_servio_invoice_update',    [ __CLASS__, 'ajax_update_invoice' ] );
        add_action( 'wp_ajax_servio_invoice_set_status', [ __CLASS__, 'ajax_set_status' ] );
        add_action( 'wp_ajax_servio_save_ext_client',   [ __CLASS__, 'ajax_save_client' ] );
        add_action( 'wp_ajax_servio_delete_ext_client', [ __CLASS__, 'ajax_delete_client' ] );
        add_action( 'wp_ajax_servio_save_invoice_settings', [ __CLASS__, 'ajax_save_settings' ] );

        // AJAX frontend (vue client)
        add_action( 'wp_ajax_servio_view_invoice', [ __CLASS__, 'ajax_client_view_invoice' ] );
    }

    /* ================================================================
     *  TABLES
     * ================================================================ */

    public static function invoices_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'servio_invoices';
    }

    public static function clients_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'servio_clients';
    }

    public static function create_invoices_table(): void {
        global $wpdb;

        $table   = self::invoices_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_number  VARCHAR(30)     NOT NULL,
            order_id        BIGINT UNSIGNED DEFAULT NULL,
            client_id       BIGINT UNSIGNED DEFAULT NULL,
            ext_client_id   BIGINT UNSIGNED DEFAULT NULL,
            status          VARCHAR(20)     NOT NULL DEFAULT 'draft',
            items           LONGTEXT        NOT NULL,
            subtotal        DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            tax_rate        DECIMAL(5,2)    NOT NULL DEFAULT 20.00,
            tax_amount      DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            total           DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            notes           TEXT            DEFAULT NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            validated_at    DATETIME        DEFAULT NULL,
            paid_at         DATETIME        DEFAULT NULL,
            invoice_type    VARCHAR(30)     NOT NULL DEFAULT 'single',
            schedule_id     BIGINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY invoice_number (invoice_number),
            KEY order_id (order_id),
            KEY client_id (client_id),
            KEY ext_client_id (ext_client_id),
            KEY status (status)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function create_clients_table(): void {
        global $wpdb;

        $table   = self::clients_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(255)    NOT NULL,
            email       VARCHAR(255)    DEFAULT NULL,
            company     VARCHAR(255)    DEFAULT NULL,
            address     TEXT            DEFAULT NULL,
            city        VARCHAR(100)    DEFAULT NULL,
            postal_code VARCHAR(20)     DEFAULT NULL,
            country     VARCHAR(100)    DEFAULT 'France',
            phone       VARCHAR(50)     DEFAULT NULL,
            vat_number  VARCHAR(50)     DEFAULT NULL,
            siret       VARCHAR(50)     DEFAULT NULL,
            notes       TEXT            DEFAULT NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY email (email(191))
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /* ================================================================
     *  SETTINGS
     * ================================================================ */

    public static function get_settings(): array {
        $defaults = [
            'company_name'    => '',
            'company_address' => '',
            'company_city'    => '',
            'company_postal'  => '',
            'company_country' => 'France',
            'company_phone'   => '',
            'company_email'   => get_bloginfo( 'admin_email' ),
            'company_logo'    => '',
            'vat_number'      => '',
            'siret_ifu'       => '',
            'siret_label'     => 'SIRET/IFU',
            'invoice_prefix'  => 'FACT-',
            'tax_rate'        => 20,
            'tax_notice'      => '',
            'payment_terms'   => __( 'Paiement à réception de facture.', 'servio' ),
            'footer_text'     => '',
        ];
        $saved = get_option( 'servio_invoice_settings', [] );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }
        return wp_parse_args( $saved, $defaults );
    }

    /* ================================================================
     *  HELPERS
     * ================================================================ */

    private static function get_status_labels(): array {
        return [
            self::STATUS_DRAFT     => __( 'Brouillon', 'servio' ),
            self::STATUS_PENDING   => __( 'En attente', 'servio' ),
            self::STATUS_VALIDATED => __( 'Validée', 'servio' ),
            self::STATUS_PAID      => __( 'Payée', 'servio' ),
            self::STATUS_CANCELLED => __( 'Annulée', 'servio' ),
        ];
    }

    private static function get_status_colors(): array {
        return [
            self::STATUS_DRAFT     => '#9ca3af',
            self::STATUS_PENDING   => '#f59e0b',
            self::STATUS_VALIDATED => '#3b82f6',
            self::STATUS_PAID      => '#10b981',
            self::STATUS_CANCELLED => '#ef4444',
        ];
    }

    private static function generate_invoice_number(): string {
        global $wpdb;

        $settings = self::get_settings();
        $prefix   = $settings['invoice_prefix'] ?: 'FACT-';
        $table    = self::invoices_table_name();

        // Chercher uniquement les factures qui commencent par le préfixe actuel
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $last = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT invoice_number FROM {$table} WHERE invoice_number LIKE %s ORDER BY id DESC LIMIT 1",
            $wpdb->esc_like( $prefix ) . '%'
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $seq = 1;
        if ( $last ) {
            // Extraire la partie numérique après le préfixe exact
            $num_part = substr( $last, strlen( $prefix ) );
            $num      = absint( $num_part );
            if ( $num > 0 ) {
                $seq = $num + 1;
            }
        }

        return $prefix . str_pad( $seq, 3, '0', STR_PAD_LEFT );
    }

    public static function get_client_info( ?int $client_id, ?int $ext_client_id ): ?object {
        global $wpdb;

        if ( $client_id ) {
            $user = get_userdata( $client_id );
            if ( $user ) {
                return (object) [
                    'name'        => $user->display_name,
                    'email'       => $user->user_email,
                    'company'     => '',
                    'address'     => '',
                    'city'        => '',
                    'postal_code' => '',
                    'country'     => '',
                    'phone'       => '',
                    'vat_number'  => '',
                    'siret'       => '',
                    'is_wp'       => true,
                ];
            }
        }

        if ( $ext_client_id ) {
            $table  = self::clients_table_name();
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are plugin-defined constants.
            $client = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $ext_client_id ) );
            if ( $client ) {
                $client->is_wp = false;
                return $client;
            }
        }

        return null;
    }

    public static function get_invoices( string $status = '', int $limit = 50 ): array {
        global $wpdb;

        $table = self::invoices_table_name();
        $where = '';
        if ( $status && in_array( $status, [ self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_VALIDATED, self::STATUS_PAID, self::STATUS_CANCELLED ], true ) ) {
            $where = $wpdb->prepare( 'WHERE status = %s', $status );
        }

        $limit = absint( $limit );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are plugin-defined constants; queries use no user input.
        return $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT {$limit}" );
    }

    public static function get_invoices_for_client( int $client_id ): array {
        global $wpdb;

        $table = self::invoices_table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT * FROM {$table} WHERE client_id = %d AND status IN ('validated','paid') ORDER BY created_at DESC",
            $client_id
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function get_invoice( int $invoice_id ): ?object {
        global $wpdb;

        $table = self::invoices_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are plugin-defined constants.
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $invoice_id ) );
    }

    private static function get_status_counts(): array {
        global $wpdb;

        $table  = self::invoices_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are plugin-defined constants; queries use no user input.
        $rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status" );
        $counts = [ 'all' => 0 ];

        foreach ( [ self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_VALIDATED, self::STATUS_PAID, self::STATUS_CANCELLED ] as $s ) {
            $counts[ $s ] = 0;
        }

        foreach ( $rows as $row ) {
            $counts[ $row->status ] = (int) $row->cnt;
            $counts['all']         += (int) $row->cnt;
        }

        return $counts;
    }

    private static function get_all_ext_clients(): array {
        global $wpdb;
        $table = self::clients_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are plugin-defined constants; queries use no user input.
        return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC" );
    }

    /* ================================================================
     *  ADMIN MENU
     * ================================================================ */

    public static function add_menu(): void {
        add_submenu_page(
            'servio',
            __( 'Factures', 'servio' ),
            __( 'Factures', 'servio' ),
            'manage_options',
            'serviceflow-invoices',
            [ __CLASS__, 'render_invoices_list' ]
        );
        add_submenu_page(
            'servio',
            __( 'Nouvelle facture', 'servio' ),
            __( 'Nouvelle facture', 'servio' ),
            'manage_options',
            'serviceflow-invoice-new',
            [ __CLASS__, 'render_invoice_new' ]
        );
        add_submenu_page(
            'servio',
            __( 'Clients externes', 'servio' ),
            __( 'Clients externes', 'servio' ),
            'manage_options',
            'serviceflow-clients',
            [ __CLASS__, 'render_clients_page' ]
        );
        add_submenu_page(
            'servio',
            __( 'Réglages facturation', 'servio' ),
            __( 'Réglages facturation', 'servio' ),
            'manage_options',
            'serviceflow-invoice-settings',
            [ __CLASS__, 'render_settings_page' ]
        );
        // Page cachée pour voir une facture
        add_submenu_page(
            null,
            __( 'Voir facture', 'servio' ),
            '',
            'manage_options',
            'serviceflow-invoice-view',
            [ __CLASS__, 'render_invoice_view' ]
        );
        // Page cachée pour modifier une facture brouillon
        add_submenu_page(
            null,
            __( 'Modifier facture', 'servio' ),
            '',
            'manage_options',
            'serviceflow-invoice-edit',
            [ __CLASS__, 'render_invoice_edit' ]
        );
    }

    private static function get_invoice_view_css( string $color ): string {
        return
            '.serviceflow-invoice-page{max-width:800px;margin:20px auto;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:40px;padding-bottom:60px;box-shadow:0 2px 8px rgba(0,0,0,0.06);font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;color:#333}' .
            '.serviceflow-inv-header{display:flex;justify-content:space-between;align-items:flex-start}' .
            '.serviceflow-inv-logo img{max-height:60px}' .
            '.serviceflow-inv-company{font-size:12px;color:#555;line-height:1.6;margin-top:10px}' .
            '.serviceflow-inv-company strong{font-size:16px;color:#222;display:block;margin-bottom:4px}' .
            '.serviceflow-inv-header-right{text-align:right}' .
            '.serviceflow-inv-header-right h3{font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#888;margin:0 0 8px}' .
            '.serviceflow-inv-header-right p{margin:0;font-size:13px;line-height:1.5}' .
            '.serviceflow-inv-parties{display:flex;justify-content:space-between;gap:20px;margin-top:6px;margin-bottom:15px;align-items:flex-end}' .
            '.serviceflow-inv-emetteur{flex:1}' .
            '.serviceflow-inv-emetteur h3,.serviceflow-inv-client h3{font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#888;margin:0 0 8px}' .
            '.serviceflow-inv-emetteur p,.serviceflow-inv-client p{margin:0;font-size:13px;line-height:1.5}' .
            '.serviceflow-inv-client{flex:1;text-align:right}' .
            '.serviceflow-inv-items-table{width:100%;border-collapse:collapse;margin-top:80px;margin-bottom:20px}' .
            '.serviceflow-inv-items-table th{background:#f9f9f9;text-align:left;padding:10px 12px;font-size:11px;font-weight:700;color:#555;text-transform:uppercase;border-bottom:2px solid #e0e0e0}' .
            '.serviceflow-inv-items-table td{padding:10px 12px;font-size:13px;border-bottom:1px solid #f0f0f0}' .
            '.serviceflow-inv-items-table .text-right{text-align:right}' .
            '.serviceflow-inv-totals{margin-left:auto;width:280px}' .
            '.serviceflow-inv-totals-row{display:flex;justify-content:space-between;padding:6px 0;font-size:13px}' .
            '.serviceflow-inv-totals-row.total-row{border-top:2px solid #222;font-size:16px;font-weight:700;padding-top:10px;margin-top:4px}' .
            '.serviceflow-inv-notes{margin-top:40px;padding:16px;background:#f9f9f9;border-radius:6px;font-size:12px;color:#555;line-height:1.5;width:40%}' .
            '.serviceflow-inv-footer-text{text-align:center;font-size:11px;color:#999;border-top:1px solid #eee;padding-top:12px;margin-top:auto}' .
            '.serviceflow-inv-actions{margin-top:20px;display:flex;gap:8px;flex-wrap:wrap}' .
            '.serviceflow-inv-actions button{padding:8px 20px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}' .
            '@media print{' .
                '@page{size:A4;margin:10mm 10mm 14mm 10mm}' .
                '#adminmenumain,#wpadminbar,.no-print,#wpfooter,.update-nag,.notice{display:none!important}' .
                '#wpcontent{margin-left:0!important;padding:0!important}' .
                '.serviceflow-invoice-page{box-shadow:none!important;border:none!important;border-radius:0!important;padding:20px!important;padding-bottom:10px!important;margin:0!important;max-width:100%!important;display:flex!important;flex-direction:column!important;min-height:calc(297mm - 24mm - 40px)!important}' .
                '.serviceflow-inv-header{display:flex!important;justify-content:space-between!important;align-items:flex-start!important}' .
                '.serviceflow-inv-parties{display:flex!important;justify-content:space-between!important;gap:20px!important;margin-top:6px!important;margin-bottom:15px!important;align-items:flex-end!important}' .
                '.serviceflow-inv-emetteur{flex:1!important}' .
                '.serviceflow-inv-client{flex:1!important;text-align:right!important}' .
                '.serviceflow-inv-notes{margin-top:40px!important;width:40%!important;background:#f9f9f9!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}' .
                '.serviceflow-inv-items-table{margin-top:80px!important}' .
                '.serviceflow-inv-items-table th{background:#f9f9f9!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}' .
                '.serviceflow-inv-footer-text{margin-top:auto!important;text-align:center!important;padding-top:8px!important;border-top:1px solid #ccc!important;font-size:9px!important;color:#999!important;display:block!important}' .
            '}';
    }

    public static function enqueue_admin_scripts( string $hook ): void {
        if ( strpos( $hook, 'serviceflow-invoice' ) === false && strpos( $hook, 'serviceflow-clients' ) === false ) {
            return;
        }

        if ( strpos( $hook, 'serviceflow-invoice-settings' ) !== false ) {
            wp_enqueue_media();
        }

        if ( ! wp_script_is( 'servio-invoices-js', 'registered' ) ) {
            wp_register_script( 'servio-invoices-js', false, [ 'jquery' ], SERVIO_VERSION, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        }
        wp_enqueue_script( 'servio-invoices-js' );

        $color = esc_attr( Servio_Admin::get_color() );

        wp_add_inline_style(
            'wp-admin',
            // Settings page
            '.serviceflow-inv-section{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:24px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.06)}' .
            '.serviceflow-inv-section h2{font-size:15px;font-weight:700;color:#222;margin:0 0 16px;padding:0 0 12px;border-bottom:1px solid #eee;display:flex;align-items:center;gap:8px}' .
            '.serviceflow-inv-field{margin-bottom:14px}' .
            '.serviceflow-inv-field label{display:block;font-size:13px;font-weight:600;color:#555;margin-bottom:4px}' .
            '.serviceflow-inv-field input[type="text"],.serviceflow-inv-field input[type="number"],.serviceflow-inv-field input[type="email"],.serviceflow-inv-field textarea{width:100%;max-width:500px}' .
            '.serviceflow-inv-field textarea{height:80px}' .
            '.serviceflow-inv-row{display:flex;gap:16px;flex-wrap:wrap}' .
            '.serviceflow-inv-row .serviceflow-inv-field{flex:1;min-width:200px}' .
            '.serviceflow-inv-logo-preview{margin-top:8px}' .
            '.serviceflow-inv-logo-preview img{max-height:80px;border:1px solid #e0e0e0;border-radius:4px;padding:4px;background:#fafafa}' .
            // Clients page
            '.serviceflow-clients-wrap{display:flex;gap:24px;flex-wrap:wrap}' .
            '.serviceflow-clients-list{flex:1;min-width:400px}' .
            '.serviceflow-clients-form{flex:0 0 380px}' .
            '.serviceflow-clients-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden}' .
            '.serviceflow-clients-table th{background:#f9f9f9;text-align:left;padding:10px 12px;font-size:12px;font-weight:600;color:#555;border-bottom:1px solid #e0e0e0}' .
            '.serviceflow-clients-table td{padding:10px 12px;font-size:13px;color:#333;border-bottom:1px solid #f5f5f5}' .
            '.serviceflow-clients-table tr:last-child td{border-bottom:none}' .
            '.serviceflow-cl-form-card{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.06)}' .
            '.serviceflow-cl-form-card h3{margin:0 0 16px;font-size:15px;font-weight:700;color:#222}' .
            '.serviceflow-cl-field{margin-bottom:12px}' .
            '.serviceflow-cl-field label{display:block;font-size:12px;font-weight:600;color:#555;margin-bottom:3px}' .
            '.serviceflow-cl-field input,.serviceflow-cl-field textarea{width:100%}' .
            '.serviceflow-cl-field textarea{height:60px}' .
            '.serviceflow-cl-row{display:flex;gap:10px}' .
            '.serviceflow-cl-row .serviceflow-cl-field{flex:1}' .
            '.serviceflow-cl-actions a{cursor:pointer;font-size:12px;margin-right:8px}' .
            '.serviceflow-cl-actions .edit{color:#0073aa}' .
            '.serviceflow-cl-actions .delete{color:#dc3545}' .
            // List page (dynamic color)
            '.serviceflow-inv-filters{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px}' .
            '.serviceflow-inv-filter{padding:6px 14px;border:1px solid #ddd;border-radius:20px;font-size:13px;font-weight:500;color:#555;text-decoration:none;background:#fff;cursor:pointer;transition:all .15s}' .
            '.serviceflow-inv-filter.active,.serviceflow-inv-filter:hover{border-color:' . $color . ';color:' . $color . '}' .
            '.serviceflow-inv-filter .count{font-size:11px;color:#999;margin-left:4px}' .
            '.serviceflow-inv-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden}' .
            '.serviceflow-inv-table th{background:#f9f9f9;text-align:left;padding:10px 12px;font-size:12px;font-weight:600;color:#555;border-bottom:1px solid #e0e0e0}' .
            '.serviceflow-inv-table td{padding:10px 12px;font-size:13px;color:#333;border-bottom:1px solid #f5f5f5}' .
            '.serviceflow-inv-table tr:last-child td{border-bottom:none}' .
            '.serviceflow-inv-badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;color:#fff}' .
            '.serviceflow-inv-status-wrap{position:relative;display:inline-block}' .
            '.serviceflow-inv-badge.clickable{cursor:pointer;transition:opacity .15s}' .
            '.serviceflow-inv-badge.clickable:hover{opacity:.8}' .
            '.serviceflow-inv-status-dd{display:none;position:absolute;top:100%;left:0;margin-top:4px;background:#fff;border:1px solid #e0e0e0;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.12);z-index:100;min-width:140px;overflow:hidden}' .
            '.serviceflow-inv-status-dd.open{display:block}' .
            '.serviceflow-inv-status-dd a{display:flex;align-items:center;gap:6px;padding:7px 12px;font-size:12px;color:#333;text-decoration:none;white-space:nowrap;transition:background .1s}' .
            '.serviceflow-inv-status-dd a:hover{background:#f5f5f5}' .
            '.serviceflow-inv-status-dot{width:8px;height:8px;border-radius:50%;display:inline-block;flex-shrink:0}' .
            '.serviceflow-inv-act{font-size:12px;margin-right:6px;cursor:pointer;text-decoration:none}' .
            // New/Edit invoice
            '.serviceflow-newinv-section{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:24px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.06)}' .
            '.serviceflow-newinv-section h2{font-size:15px;font-weight:700;color:#222;margin:0 0 16px;padding:0 0 12px;border-bottom:1px solid #eee;display:flex;align-items:center;gap:8px}' .
            '.serviceflow-newinv-field{margin-bottom:14px}' .
            '.serviceflow-newinv-field label{display:block;font-size:13px;font-weight:600;color:#555;margin-bottom:4px}' .
            '.serviceflow-newinv-field select,.serviceflow-newinv-field input,.serviceflow-newinv-field textarea{width:100%;max-width:500px}' .
            '.serviceflow-newinv-field textarea{height:80px}' .
            '.serviceflow-newinv-radio{display:flex;gap:16px;margin-bottom:12px}' .
            '.serviceflow-newinv-radio label{display:flex;align-items:center;gap:6px;font-size:14px;font-weight:500;cursor:pointer;color:#333}' .
            '.serviceflow-items-table{width:100%;border-collapse:collapse;margin-bottom:8px}' .
            '.serviceflow-items-table th{text-align:left;padding:8px;font-size:12px;font-weight:600;color:#555;background:#f9f9f9;border-bottom:1px solid #e0e0e0}' .
            '.serviceflow-items-table td{padding:6px 8px;border-bottom:1px solid #f5f5f5}' .
            '.serviceflow-items-table input{width:100%}' .
            '.serviceflow-items-rm{background:#dc3545;color:#fff;border:none;border-radius:3px;padding:4px 10px;cursor:pointer;font-size:11px}' .
            '.serviceflow-items-rm:hover{background:#c82333}' .
            '.serviceflow-newinv-totals{text-align:right;font-size:14px;color:#333;line-height:2}' .
            '.serviceflow-newinv-totals strong{font-size:16px}' .
            // View invoice page
            self::get_invoice_view_css( $color )
        );
    }

    /* ================================================================
     *  AUTO-GÉNÉRATION (commande acceptée)
     * ================================================================ */

    public static function on_order_accepted( int $order_id, string $new_status, string $old_status, int $acting_user_id ): void {
        if ( $new_status !== Servio_Orders::STATUS_ACCEPTED && $new_status !== Servio_Orders::STATUS_COMPLETED ) {
            return;
        }

        global $wpdb;

        // Vérifier qu'aucune facture non-annulée n'existe déjà
        $table  = self::invoices_table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $exists = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT id FROM {$table} WHERE order_id = %d AND status != %s LIMIT 1",
            $order_id, self::STATUS_CANCELLED
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $exists ) {
            return;
        }

        $order = Servio_Orders::get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Les commandes avec échéancier ont leurs propres factures partielles
        if ( isset( $order->payment_mode ) && $order->payment_mode !== 'single' ) {
            return;
        }

        $settings = self::get_settings();
        $tax_rate = floatval( $settings['tax_rate'] );

        // Construire les items depuis la commande
        $service_name = get_the_title( (int) $order->post_id ) ?: '';
        $items        = [];
        $base_offer   = json_decode( $order->base_offer, true );
        if ( $base_offer ) {
            $items[] = [
                'service_name' => $service_name,
                'description'  => $base_offer['name'] ?? 'Pack',
                'quantity'     => 1,
                'unit_price'   => floatval( $base_offer['price'] ?? 0 ),
                'total'        => floatval( $base_offer['price'] ?? 0 ),
            ];
        }

        $selected_options = json_decode( $order->selected_options, true );
        if ( is_array( $selected_options ) ) {
            foreach ( $selected_options as $opt ) {
                $items[] = [
                    'description' => $opt['name'] ?? 'Option',
                    'quantity'    => 1,
                    'unit_price'  => floatval( $opt['price'] ?? 0 ),
                    'total'       => floatval( $opt['price'] ?? 0 ),
                ];
            }
        }

        // Options avancées dynamiques (nouveau système)
        $adv_order_data = [];
        if ( ! empty( $order->advanced_options_data ) ) {
            $adv_order_data = json_decode( $order->advanced_options_data, true ) ?: [];
        }

        if ( ! empty( $adv_order_data ) ) {
            // Nouveau système : lire depuis advanced_options_data
            foreach ( $adv_order_data as $asel ) {
                $qty   = absint( $asel['qty'] ?? 1 );
                $price = floatval( $asel['price'] ?? 0 );
                $mode  = $asel['mode'] ?? 'unit';
                $lbl   = sanitize_text_field( $asel['label'] ?? '' );
                if ( ! $lbl || ! $price ) {
                    continue;
                }
                $items[] = [
                    'description' => $lbl . ( $mode === 'monthly' ? ' (' . __( 'mensuel', 'servio' ) . ')' : '' ),
                    'quantity'    => $qty,
                    'unit_price'  => $price,
                    'total'       => round( $qty * $price, 2 ),
                ];
            }
        } else {
            // Ancien système : colonnes individuelles (backward compat)
            $extra_pages      = (int) ( $order->extra_pages ?? 0 );
            $extra_page_price = floatval( $order->extra_page_price ?? 0 );
            $maintenance      = floatval( $order->maintenance_price ?? 0 );
            $express_days     = (int) ( $order->express_days ?? 0 );
            $express_price    = floatval( $order->express_price ?? 0 );

            if ( $extra_pages > 0 && $extra_page_price > 0 ) {
                $items[] = [
                    'description' => Servio_Admin::get_extra_pages_label( (int) $order->post_id ),
                    'quantity'    => $extra_pages,
                    'unit_price'  => $extra_page_price,
                    'total'       => round( $extra_pages * $extra_page_price, 2 ),
                ];
            }
            if ( $maintenance > 0 ) {
                $items[] = [
                    'description' => Servio_Admin::get_maintenance_label( (int) $order->post_id ),
                    'quantity'    => 1,
                    'unit_price'  => $maintenance,
                    'total'       => $maintenance,
                ];
            }
            if ( $express_days > 0 && $express_price > 0 ) {
                $items[] = [
                    'description' => Servio_Admin::get_express_label( (int) $order->post_id ),
                    'quantity'    => $express_days,
                    'unit_price'  => $express_price,
                    'total'       => round( $express_days * $express_price, 2 ),
                ];
            }
        }

        $subtotal   = array_sum( array_column( $items, 'total' ) );
        $tax_amount = round( $subtotal * $tax_rate / 100, 2 );
        $total      = $subtotal + $tax_amount;

        // Stripe encaissé OU commande auto-terminée → facture directement « paid »
        $is_stripe_paid = ! empty( $order->stripe_payment_intent );
        $invoice_status = ( $is_stripe_paid || $new_status === Servio_Orders::STATUS_COMPLETED )
            ? self::STATUS_PAID
            : self::STATUS_PENDING;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $inserted = $wpdb->insert( $table, [
            'invoice_number' => self::generate_invoice_number(),
            'order_id'       => $order_id,
            'client_id'      => (int) $order->client_id,
            'ext_client_id'  => null,
            'status'         => $invoice_status,
            'items'          => wp_json_encode( $items ),
            'subtotal'       => $subtotal,
            'tax_rate'       => $tax_rate,
            'tax_amount'     => $tax_amount,
            'total'          => $total,
            'notes'          => $settings['payment_terms'],
            'created_at'     => current_time( 'mysql' ),
            'updated_at'     => current_time( 'mysql' ),
            'paid_at'        => ( $is_stripe_paid || $new_status === Servio_Orders::STATUS_COMPLETED ) ? current_time( 'mysql' ) : null,
        ] );

        if ( ! $inserted ) {
            // silent fail — no action needed
        }
    }

    /* ================================================================
     *  Factures partielles (acompte / solde / mensualité)
     * ================================================================ */

    /**
     * Crée une facture partielle liée à une ligne d'échéancier (mode deposit ou installments).
     *
     * @param int    $order_id       ID commande
     * @param float  $amount_ttc     Montant TTC de cette facture
     * @param string $invoice_type   'acompte' | 'solde' | 'mensualite'
     * @param int    $schedule_id    ID ligne servio_payment_schedule (0 si inconnu)
     * @param int    $installment_no Numéro de mensualité
     * @return int|false  ID facture ou false
     */
    public static function create_partial_invoice( int $order_id, float $amount_ttc, string $invoice_type, int $schedule_id = 0, int $installment_no = 0 ) {
        global $wpdb;
        $table = self::invoices_table_name();

        // Idempotence : une seule facture par ligne d'échéancier
        if ( $schedule_id ) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $exists = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
                "SELECT id FROM {$table} WHERE schedule_id = %d AND status != %s LIMIT 1",
                $schedule_id, self::STATUS_CANCELLED
            ) );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( $exists ) {
                return false;
            }
        }

        $order = Servio_Orders::get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        $settings = self::get_settings();
        $tax_rate = floatval( $settings['tax_rate'] );

        $service_name     = get_the_title( (int) $order->post_id ) ?: 'Service';
        $base_offer       = json_decode( $order->base_offer ?? '', true ) ?: [];
        $selected_options = json_decode( $order->selected_options ?? '', true ) ?: [];

        $type_label = match ( $invoice_type ) {
            /* translators: deposit percentage label on invoice */
            'acompte'    => __( 'Acompte 50%', 'servio' ),
            'solde'      => __( 'Solde', 'servio' ),
            /* translators: %d: installment number */
            'mensualite' => sprintf( __( 'Mensualité %d', 'servio' ), $installment_no ),
            default      => '',
        };

        $pack_name = $base_offer['name'] ?? '';

        // Facteur de réduction : proportion de ce paiement sur le total TTC du contrat
        $full_total_ttc = floatval( $order->total_price ?? 0 );
        $factor         = ( $full_total_ttc > 0 ) ? ( $amount_ttc / $full_total_ttc ) : 1.0;

        // Construction des items à prix proportionnel
        $items        = [];
        $items_ht_sum = 0.0;

        // Pack principal (en gras via service_name)
        $pack_ht  = round( floatval( $base_offer['price'] ?? 0 ) * $factor, 2 );
        $items_ht_sum += $pack_ht;
        $items[] = [
            'service_name' => $service_name,
            'description'  => $pack_name . ( $type_label ? ' — ' . $type_label : '' ),
            'quantity'     => 1,
            'unit_price'   => $pack_ht,
            'total'        => $pack_ht,
        ];

        // Options sélectionnées (packs/options classiques)
        foreach ( $selected_options as $opt ) {
            $opt_name = $opt['name'] ?? '';
            if ( empty( $opt_name ) ) {
                continue;
            }
            $opt_ht = round( floatval( $opt['price'] ?? 0 ) * $factor, 2 );
            $items_ht_sum += $opt_ht;
            $items[] = [
                'description' => $opt_name,
                'quantity'    => 1,
                'unit_price'  => $opt_ht,
                'total'       => $opt_ht,
            ];
        }

        // Options avancées dynamiques
        $adv_order_data_partial = [];
        if ( ! empty( $order->advanced_options_data ) ) {
            $adv_order_data_partial = json_decode( $order->advanced_options_data, true ) ?: [];
        }
        if ( ! empty( $adv_order_data_partial ) ) {
            foreach ( $adv_order_data_partial as $asel ) {
                $lbl  = sanitize_text_field( $asel['label'] ?? '' );
                $qty  = absint( $asel['qty'] ?? 1 );
                $pht  = floatval( $asel['price'] ?? 0 );
                if ( ! $lbl ) {
                    continue;
                }
                $unit_ht  = round( $pht * $factor, 2 );
                $total_ht = round( $unit_ht * $qty, 2 );
                $items_ht_sum += $total_ht;
                $items[] = [
                    'description' => $lbl,
                    'quantity'    => $qty,
                    'unit_price'  => $unit_ht,
                    'total'       => $total_ht,
                ];
            }
        } else {
            // Backward compat : colonnes individuelles (anciennes commandes)
            $extra_pages = (int) ( $order->extra_pages ?? 0 );
            if ( $extra_pages > 0 ) {
                $unit_ht  = round( floatval( $order->extra_page_price ?? 0 ) * $factor, 2 );
                $total_ht = round( $unit_ht * $extra_pages, 2 );
                $items_ht_sum += $total_ht;
                $items[] = [
                    'description' => Servio_Admin::get_extra_pages_label( (int) $order->post_id ),
                    'quantity'    => $extra_pages,
                    'unit_price'  => $unit_ht,
                    'total'       => $total_ht,
                ];
            }
            $maintenance = floatval( $order->maintenance_price ?? 0 );
            if ( $maintenance > 0 ) {
                $unit_ht = round( $maintenance * $factor, 2 );
                $items_ht_sum += $unit_ht;
                $items[] = [
                    'description' => Servio_Admin::get_maintenance_label( (int) $order->post_id ),
                    'quantity'    => 1,
                    'unit_price'  => $unit_ht,
                    'total'       => $unit_ht,
                ];
            }
            $express_days = (int) ( $order->express_days ?? 0 );
            if ( $express_days > 0 ) {
                $unit_ht  = round( floatval( $order->express_price ?? 0 ) * $factor, 2 );
                $total_ht = round( $unit_ht * $express_days, 2 );
                $items_ht_sum += $total_ht;
                $items[] = [
                    'description' => Servio_Admin::get_express_label( (int) $order->post_id ),
                    'quantity'    => $express_days,
                    'unit_price'  => $unit_ht,
                    'total'       => $total_ht,
                ];
            }
        }

        // Totaux : assure que sous-total + TVA = amount_ttc exactement
        $subtotal   = round( $items_ht_sum, 2 );
        $tax_amount = round( $amount_ttc - $subtotal, 2 );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $inserted = $wpdb->insert( $table, [
            'invoice_number' => self::generate_invoice_number(),
            'order_id'       => $order_id,
            'client_id'      => (int) $order->client_id,
            'ext_client_id'  => null,
            'status'         => self::STATUS_PAID,
            'items'          => wp_json_encode( $items ),
            'subtotal'       => $subtotal,
            'tax_rate'       => $tax_rate,
            'tax_amount'     => $tax_amount,
            'total'          => $amount_ttc,
            'notes'          => $settings['payment_terms'],
            'invoice_type'   => $invoice_type,
            'schedule_id'    => $schedule_id ?: null,
            'created_at'     => current_time( 'mysql' ),
            'updated_at'     => current_time( 'mysql' ),
            'paid_at'        => current_time( 'mysql' ),
        ] );

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    /* ================================================================
     *  AJAX — Transitions de statut
     * ================================================================ */

    public static function ajax_validate(): void {
        check_ajax_referer( 'servio_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'servio' ) ], 403 );
        }

        global $wpdb;
        $invoice_id = absint( $_POST['invoice_id'] ?? 0 );
        if ( ! $invoice_id ) {
            wp_send_json_error( [ 'message' => __( 'ID manquant.', 'servio' ) ], 400 );
        }

        $table = self::invoices_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update( $table, [
            'status'       => self::STATUS_VALIDATED,
            'validated_at' => current_time( 'mysql' ),
            'updated_at'   => current_time( 'mysql' ),
        ], [ 'id' => $invoice_id ] );

        wp_send_json_success();
    }

    public static function ajax_mark_paid(): void {
        check_ajax_referer( 'servio_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'servio' ) ], 403 );
        }

        global $wpdb;
        $invoice_id = absint( $_POST['invoice_id'] ?? 0 );
        if ( ! $invoice_id ) {
            wp_send_json_error( [ 'message' => __( 'ID manquant.', 'servio' ) ], 400 );
        }

        $table = self::invoices_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update( $table, [
            'status'     => self::STATUS_PAID,
            'paid_at'    => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => $invoice_id ] );

        wp_send_json_success();
    }

    public static function ajax_cancel(): void {
        check_ajax_referer( 'servio_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'servio' ) ], 403 );
        }

        global $wpdb;
        $invoice_id = absint( $_POST['invoice_id'] ?? 0 );
        if ( ! $invoice_id ) {
            wp_send_json_error( [ 'message' => __( 'ID manquant.', 'servio' ) ], 400 );
        }

        $table = self::invoices_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update( $table, [
            'status'     => self::STATUS_CANCELLED,
            'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => $invoice_id ] );

        wp_send_json_success();
    }

    /* ================================================================
     *  AJAX — Sauvegarde facture manuelle
     * ================================================================ */

    public static function ajax_save_invoice(): void {
        check_ajax_referer( 'servio_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'servio' ) ], 403 );
        }

        global $wpdb;

        $client_type   = sanitize_text_field( wp_unslash( $_POST['client_type'] ?? 'wp' ) );
        $client_id     = absint( $_POST['client_id'] ?? 0 );
        $ext_client_id = absint( $_POST['ext_client_id'] ?? 0 );
        $order_id      = absint( $_POST['order_id'] ?? 0 );
        $tax_rate      = floatval( $_POST['tax_rate'] ?? 20 );
        $notes         = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
        $save_status   = sanitize_text_field( wp_unslash( $_POST['save_status'] ?? self::STATUS_DRAFT ) );

        // Construire les items
        $raw_items = wp_unslash( $_POST['items'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array items are sanitized individually in the foreach loop below.
        $items     = [];
        if ( is_array( $raw_items ) ) {
            foreach ( $raw_items as $item ) {
                $desc = sanitize_text_field( $item['description'] ?? '' );
                if ( empty( $desc ) ) {
                    continue;
                }
                $qty   = max( 1, absint( $item['quantity'] ?? 1 ) );
                $price = floatval( $item['unit_price'] ?? 0 );
                $items[] = [
                    'description' => $desc,
                    'quantity'    => $qty,
                    'unit_price'  => $price,
                    'total'       => round( $qty * $price, 2 ),
                ];
            }
        }

        if ( empty( $items ) ) {
            wp_send_json_error( [ 'message' => __( 'Ajoutez au moins un article.', 'servio' ) ], 400 );
        }

        if ( $client_type === 'wp' && ! $client_id ) {
            wp_send_json_error( [ 'message' => __( 'Sélectionnez un client.', 'servio' ) ], 400 );
        }
        if ( $client_type === 'ext' && ! $ext_client_id ) {
            wp_send_json_error( [ 'message' => __( 'Sélectionnez un client externe.', 'servio' ) ], 400 );
        }

        $subtotal   = array_sum( array_column( $items, 'total' ) );
        $tax_amount = round( $subtotal * $tax_rate / 100, 2 );
        $total      = $subtotal + $tax_amount;

        $valid_statuses = [ self::STATUS_DRAFT, self::STATUS_VALIDATED ];
        if ( ! in_array( $save_status, $valid_statuses, true ) ) {
            $save_status = self::STATUS_DRAFT;
        }

        $data = [
            'invoice_number' => self::generate_invoice_number(),
            'order_id'       => $order_id ?: null,
            'client_id'      => $client_type === 'wp' ? $client_id : null,
            'ext_client_id'  => $client_type === 'ext' ? $ext_client_id : null,
            'status'         => $save_status,
            'items'          => wp_json_encode( $items ),
            'subtotal'       => $subtotal,
            'tax_rate'       => $tax_rate,
            'tax_amount'     => $tax_amount,
            'total'          => $total,
            'notes'          => $notes,
            'created_at'     => current_time( 'mysql' ),
            'updated_at'     => current_time( 'mysql' ),
        ];

        if ( $save_status === self::STATUS_VALIDATED ) {
            $data['validated_at'] = current_time( 'mysql' );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $inserted = $wpdb->insert( self::invoices_table_name(), $data );

        if ( ! $inserted ) {
            wp_send_json_error( [ 'message' => __( 'Erreur lors de la création.', 'servio' ) ], 500 );
        }

        wp_send_json_success( [ 'invoice_id' => (int) $wpdb->insert_id ] );
    }

    /* ================================================================
     *  AJAX — Mise à jour facture brouillon
     * ================================================================ */

    public static function ajax_update_invoice(): void {
        check_ajax_referer( 'servio_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'servio' ) ], 403 );
        }

        global $wpdb;

        $invoice_id = absint( $_POST['invoice_id'] ?? 0 );
        if ( ! $invoice_id ) {
            wp_send_json_error( [ 'message' => __( 'Facture introuvable.', 'servio' ) ], 400 );
        }

        $invoice = self::get_invoice( $invoice_id );
        if ( ! $invoice || $invoice->status !== self::STATUS_DRAFT ) {
            wp_send_json_error( [ 'message' => __( 'Seuls les brouillons peuvent être modifiés.', 'servio' ) ], 400 );
        }

        $client_type   = sanitize_text_field( wp_unslash( $_POST['client_type'] ?? 'wp' ) );
        $client_id     = absint( $_POST['client_id'] ?? 0 );
        $ext_client_id = absint( $_POST['ext_client_id'] ?? 0 );
        $tax_rate      = floatval( $_POST['tax_rate'] ?? 20 );
        $notes         = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
        $save_status   = sanitize_text_field( wp_unslash( $_POST['save_status'] ?? self::STATUS_DRAFT ) );

        $raw_items = wp_unslash( $_POST['items'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array items are sanitized individually in the foreach loop below.
        $items     = [];
        if ( is_array( $raw_items ) ) {
            foreach ( $raw_items as $item ) {
                $desc = sanitize_text_field( $item['description'] ?? '' );
                if ( empty( $desc ) ) {
                    continue;
                }
                $qty   = max( 1, absint( $item['quantity'] ?? 1 ) );
                $price = floatval( $item['unit_price'] ?? 0 );
                $items[] = [
                    'description' => $desc,
                    'quantity'    => $qty,
                    'unit_price'  => $price,
                    'total'       => round( $qty * $price, 2 ),
                ];
            }
        }

        if ( empty( $items ) ) {
            wp_send_json_error( [ 'message' => __( 'Ajoutez au moins un article.', 'servio' ) ], 400 );
        }

        if ( $client_type === 'wp' && ! $client_id ) {
            wp_send_json_error( [ 'message' => __( 'Sélectionnez un client.', 'servio' ) ], 400 );
        }
        if ( $client_type === 'ext' && ! $ext_client_id ) {
            wp_send_json_error( [ 'message' => __( 'Sélectionnez un client externe.', 'servio' ) ], 400 );
        }

        $subtotal   = array_sum( array_column( $items, 'total' ) );
        $tax_amount = round( $subtotal * $tax_rate / 100, 2 );
        $total      = $subtotal + $tax_amount;

        $valid_statuses = [ self::STATUS_DRAFT, self::STATUS_VALIDATED ];
        if ( ! in_array( $save_status, $valid_statuses, true ) ) {
            $save_status = self::STATUS_DRAFT;
        }

        $data = [
            'client_id'      => $client_type === 'wp' ? $client_id : null,
            'ext_client_id'  => $client_type === 'ext' ? $ext_client_id : null,
            'status'         => $save_status,
            'items'          => wp_json_encode( $items ),
            'subtotal'       => $subtotal,
            'tax_rate'       => $tax_rate,
            'tax_amount'     => $tax_amount,
            'total'          => $total,
            'notes'          => $notes,
            'updated_at'     => current_time( 'mysql' ),
        ];

        if ( $save_status === self::STATUS_VALIDATED ) {
            $data['validated_at'] = current_time( 'mysql' );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->update( self::invoices_table_name(), $data, [ 'id' => $invoice_id ] );

        if ( $updated === false ) {
            wp_send_json_error( [ 'message' => __( 'Erreur lors de la mise à jour.', 'servio' ) ], 500 );
        }

        wp_send_json_success( [ 'invoice_id' => $invoice_id ] );
    }

    /* ================================================================
     *  AJAX — Changement de statut rapide
     * ================================================================ */

    public static function ajax_set_status(): void {
        check_ajax_referer( 'servio_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'servio' ) ], 403 );
        }

        global $wpdb;

        $invoice_id = absint( $_POST['invoice_id'] ?? 0 );
        $new_status = sanitize_text_field( wp_unslash( $_POST['new_status'] ?? '' ) );

        if ( ! $invoice_id ) {
            wp_send_json_error( [ 'message' => __( 'Facture introuvable.', 'servio' ) ], 400 );
        }

        $invoice = self::get_invoice( $invoice_id );
        if ( ! $invoice ) {
            wp_send_json_error( [ 'message' => __( 'Facture introuvable.', 'servio' ) ], 404 );
        }

        // Transitions autorisées
        $allowed = [
            self::STATUS_DRAFT     => [ self::STATUS_PENDING, self::STATUS_VALIDATED, self::STATUS_CANCELLED ],
            self::STATUS_PENDING   => [ self::STATUS_VALIDATED, self::STATUS_CANCELLED ],
            self::STATUS_VALIDATED => [ self::STATUS_PAID, self::STATUS_CANCELLED ],
            self::STATUS_PAID      => [],
            self::STATUS_CANCELLED => [],
        ];

        $transitions = $allowed[ $invoice->status ] ?? [];
        if ( ! in_array( $new_status, $transitions, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Transition de statut non autorisée.', 'servio' ) ], 400 );
        }

        $data = [
            'status'     => $new_status,
            'updated_at' => current_time( 'mysql' ),
        ];

        if ( $new_status === self::STATUS_VALIDATED ) {
            $data['validated_at'] = current_time( 'mysql' );
        }
        if ( $new_status === self::STATUS_PAID ) {
            $data['paid_at'] = current_time( 'mysql' );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update( self::invoices_table_name(), $data, [ 'id' => $invoice_id ] );

        wp_send_json_success();
    }

    /* ================================================================
     *  AJAX — Clients externes CRUD
     * ================================================================ */

    public static function ajax_save_client(): void {
        check_ajax_referer( 'servio_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'servio' ) ], 403 );
        }

        global $wpdb;

        $id   = absint( $_POST['client_id'] ?? 0 );
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

        if ( empty( $name ) ) {
            wp_send_json_error( [ 'message' => __( 'Le nom est obligatoire.', 'servio' ) ], 400 );
        }

        $data = [
            'name'        => $name,
            'email'       => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
            'company'     => sanitize_text_field( wp_unslash( $_POST['company'] ?? '' ) ),
            'address'     => sanitize_textarea_field( wp_unslash( $_POST['address'] ?? '' ) ),
            'city'        => sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) ),
            'postal_code' => sanitize_text_field( wp_unslash( $_POST['postal_code'] ?? '' ) ),
            'country'     => sanitize_text_field( wp_unslash( $_POST['country'] ?? 'France' ) ),
            'phone'       => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
            'vat_number'  => sanitize_text_field( wp_unslash( $_POST['vat_number'] ?? '' ) ),
            'siret'       => sanitize_text_field( wp_unslash( $_POST['siret'] ?? '' ) ),
            'notes'       => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
        ];

        $table = self::clients_table_name();

        if ( $id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update( $table, $data, [ 'id' => $id ] );
            wp_send_json_success( [ 'client_id' => $id ] );
        } else {
            $data['created_at'] = current_time( 'mysql' );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->insert( $table, $data );
            wp_send_json_success( [ 'client_id' => (int) $wpdb->insert_id ] );
        }
    }

    public static function ajax_delete_client(): void {
        check_ajax_referer( 'servio_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'servio' ) ], 403 );
        }

        global $wpdb;
        $id = absint( $_POST['client_id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => __( 'ID manquant.', 'servio' ) ], 400 );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( self::clients_table_name(), [ 'id' => $id ] );
        wp_send_json_success();
    }

    /* ================================================================
     *  AJAX — Sauvegarde réglages
     * ================================================================ */

    public static function ajax_save_settings(): void {
        check_ajax_referer( 'servio_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'servio' ) ], 403 );
        }

        $settings = [
            'company_name'    => sanitize_text_field( wp_unslash( $_POST['company_name'] ?? '' ) ),
            'company_address' => sanitize_textarea_field( wp_unslash( $_POST['company_address'] ?? '' ) ),
            'company_city'    => sanitize_text_field( wp_unslash( $_POST['company_city'] ?? '' ) ),
            'company_postal'  => sanitize_text_field( wp_unslash( $_POST['company_postal'] ?? '' ) ),
            'company_country' => sanitize_text_field( wp_unslash( $_POST['company_country'] ?? 'France' ) ),
            'company_phone'   => sanitize_text_field( wp_unslash( $_POST['company_phone'] ?? '' ) ),
            'company_email'   => sanitize_email( wp_unslash( $_POST['company_email'] ?? '' ) ),
            'company_logo'    => esc_url_raw( wp_unslash( $_POST['company_logo'] ?? '' ) ),
            'vat_number'      => sanitize_text_field( wp_unslash( $_POST['vat_number'] ?? '' ) ),
            'siret_ifu'       => sanitize_text_field( wp_unslash( $_POST['siret_ifu'] ?? '' ) ),
            'siret_label'     => sanitize_text_field( wp_unslash( $_POST['siret_label'] ?? 'SIRET/IFU' ) ),
            'invoice_prefix'  => sanitize_text_field( wp_unslash( $_POST['invoice_prefix'] ?? 'FACT-' ) ),
            'tax_rate'        => floatval( $_POST['tax_rate'] ?? 20 ),
            'tax_notice'      => sanitize_textarea_field( wp_unslash( $_POST['tax_notice'] ?? '' ) ),
            'payment_terms'   => sanitize_textarea_field( wp_unslash( $_POST['payment_terms'] ?? '' ) ),
            'footer_text'     => sanitize_textarea_field( wp_unslash( $_POST['footer_text'] ?? '' ) ),
        ];

        update_option( 'servio_invoice_settings', $settings );
        wp_send_json_success();
    }

    /* ================================================================
     *  AJAX — Vue client (frontend)
     * ================================================================ */

    public static function ajax_client_view_invoice(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Vous devez être connecté.', 'servio' ) );
        }

        $invoice_id = absint( $_GET['invoice_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view; ownership verified below.
        if ( ! $invoice_id ) {
            wp_die( esc_html__( 'Facture introuvable.', 'servio' ) );
        }

        $invoice = self::get_invoice( $invoice_id );
        if ( ! $invoice ) {
            wp_die( esc_html__( 'Facture introuvable.', 'servio' ) );
        }

        // Vérifier que la facture appartient à l'utilisateur
        if ( (int) $invoice->client_id !== get_current_user_id() ) {
            wp_die( esc_html__( 'Accès non autorisé.', 'servio' ) );
        }

        // Seules validated / paid visibles
        if ( ! in_array( $invoice->status, [ self::STATUS_VALIDATED, self::STATUS_PAID ], true ) ) {
            wp_die( esc_html__( 'Cette facture n\'est pas encore disponible.', 'servio' ) );
        }

        $color = esc_attr( Servio_Admin::get_color() );
        header( 'Content-Type: text/html; charset=utf-8' );
        ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php esc_html_e( 'Facture', 'servio' ); ?></title>
<style><?php echo self::get_invoice_view_css( $color ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS built from hardcoded strings and already-escaped color value. ?></style>
</head>
<body>
<?php
        self::render_invoice_html( $invoice, false );
        ?>
<script>function sfPrintInvoice(){window.print();}</script>
</body>
</html>
<?php
        exit;
    }

    /* ================================================================
     *  PAGE ADMIN — Réglages facturation
     * ================================================================ */

    public static function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $s     = self::get_settings();
        $color = esc_attr( Servio_Admin::get_color() );
        $nonce = wp_create_nonce( 'servio_nonce' );
        ?>
        <div class="wrap serviceflow-dashboard">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
                <span class="dashicons dashicons-media-text" style="font-size:28px;width:28px;height:28px;color:<?php echo esc_attr( $color ); ?>"></span>
                <?php esc_html_e( 'Servio — Réglages facturation', 'servio' ); ?>
            </h1>

            <div id="serviceflow-inv-settings-form">
                <!-- Entreprise -->
                <div class="serviceflow-inv-section">
                    <h2><span class="dashicons dashicons-building"></span> <?php esc_html_e( 'Informations de l\'entreprise', 'servio' ); ?></h2>
                    <div class="serviceflow-inv-field">
                        <label><?php esc_html_e( 'Nom de l\'entreprise', 'servio' ); ?></label>
                        <input type="text" id="serviceflow-inv-company-name" value="<?php echo esc_attr( $s['company_name'] ); ?>" />
                    </div>
                    <div class="serviceflow-inv-field">
                        <label><?php esc_html_e( 'Adresse', 'servio' ); ?></label>
                        <textarea id="serviceflow-inv-company-address"><?php echo esc_textarea( $s['company_address'] ); ?></textarea>
                    </div>
                    <div class="serviceflow-inv-row">
                        <div class="serviceflow-inv-field">
                            <label><?php esc_html_e( 'Code postal', 'servio' ); ?></label>
                            <input type="text" id="serviceflow-inv-company-postal" value="<?php echo esc_attr( $s['company_postal'] ); ?>" />
                        </div>
                        <div class="serviceflow-inv-field">
                            <label><?php esc_html_e( 'Ville', 'servio' ); ?></label>
                            <input type="text" id="serviceflow-inv-company-city" value="<?php echo esc_attr( $s['company_city'] ); ?>" />
                        </div>
                        <div class="serviceflow-inv-field">
                            <label><?php esc_html_e( 'Pays', 'servio' ); ?></label>
                            <input type="text" id="serviceflow-inv-company-country" value="<?php echo esc_attr( $s['company_country'] ); ?>" />
                        </div>
                    </div>
                    <div class="serviceflow-inv-row">
                        <div class="serviceflow-inv-field">
                            <label><?php esc_html_e( 'Téléphone', 'servio' ); ?></label>
                            <input type="text" id="serviceflow-inv-company-phone" value="<?php echo esc_attr( $s['company_phone'] ); ?>" />
                        </div>
                        <div class="serviceflow-inv-field">
                            <label><?php esc_html_e( 'Email', 'servio' ); ?></label>
                            <input type="email" id="serviceflow-inv-company-email" value="<?php echo esc_attr( $s['company_email'] ); ?>" />
                        </div>
                    </div>
                </div>

                <!-- Logo -->
                <div class="serviceflow-inv-section">
                    <h2><span class="dashicons dashicons-format-image"></span> <?php esc_html_e( 'Logo', 'servio' ); ?></h2>
                    <div class="serviceflow-inv-field">
                        <input type="hidden" id="serviceflow-inv-company-logo" value="<?php echo esc_url( $s['company_logo'] ); ?>" />
                        <button type="button" id="serviceflow-inv-upload-logo" class="button"><?php esc_html_e( 'Choisir un logo', 'servio' ); ?></button>
                        <button type="button" id="serviceflow-inv-remove-logo" class="button" style="<?php echo empty( $s['company_logo'] ) ? 'display:none' : ''; ?>"><?php esc_html_e( 'Supprimer', 'servio' ); ?></button>
                        <div class="serviceflow-inv-logo-preview">
                            <?php if ( ! empty( $s['company_logo'] ) ) : ?>
                                <img src="<?php echo esc_url( $s['company_logo'] ); ?>" />
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Identification -->
                <div class="serviceflow-inv-section">
                    <h2><span class="dashicons dashicons-id-alt"></span> <?php esc_html_e( 'Identification', 'servio' ); ?></h2>
                    <div class="serviceflow-inv-row">
                        <div class="serviceflow-inv-field">
                            <label><?php esc_html_e( 'Numéro de TVA', 'servio' ); ?></label>
                            <input type="text" id="serviceflow-inv-vat" value="<?php echo esc_attr( $s['vat_number'] ); ?>" />
                        </div>
                        <div class="serviceflow-inv-field">
                            <label><?php esc_html_e( 'Libellé identifiant', 'servio' ); ?></label>
                            <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
                                <select id="serviceflow-inv-siret-label-select" style="max-width:180px">
                                    <option value="SIRET" <?php selected( $s['siret_label'], 'SIRET' ); ?>>SIRET</option>
                                    <option value="IFU" <?php selected( $s['siret_label'], 'IFU' ); ?>>IFU</option>
                                    <option value="SIRET/IFU" <?php selected( $s['siret_label'], 'SIRET/IFU' ); ?>>SIRET/IFU</option>
                                    <option value="custom" <?php echo ! in_array( $s['siret_label'], [ 'SIRET', 'IFU', 'SIRET/IFU' ], true ) ? 'selected' : ''; ?>><?php esc_html_e( 'Personnalisé', 'servio' ); ?></option>
                                </select>
                                <input type="text" id="serviceflow-inv-siret-label-custom" value="<?php echo esc_attr( $s['siret_label'] ); ?>" placeholder="<?php esc_attr_e( 'Ex: RCS, SIREN...', 'servio' ); ?>" style="max-width:200px;<?php echo in_array( $s['siret_label'], [ 'SIRET', 'IFU', 'SIRET/IFU' ], true ) ? 'display:none' : ''; ?>" />
                            </div>
                            <input type="hidden" id="serviceflow-inv-siret-label" value="<?php echo esc_attr( $s['siret_label'] ); ?>" />
                            <label><?php esc_html_e( 'Numéro', 'servio' ); ?></label>
                            <input type="text" id="serviceflow-inv-siret" value="<?php echo esc_attr( $s['siret_ifu'] ); ?>" />
                        </div>
                    </div>
                </div>

                <!-- Facturation -->
                <div class="serviceflow-inv-section">
                    <h2><span class="dashicons dashicons-money-alt"></span> <?php esc_html_e( 'Facturation', 'servio' ); ?></h2>
                    <div class="serviceflow-inv-row">
                        <div class="serviceflow-inv-field">
                            <label><?php esc_html_e( 'Préfixe des factures', 'servio' ); ?></label>
                            <input type="text" id="serviceflow-inv-prefix" value="<?php echo esc_attr( $s['invoice_prefix'] ); ?>" placeholder="FACT-" />
                        </div>
                        <div class="serviceflow-inv-field">
                            <label><?php esc_html_e( 'Taux de TVA (%)', 'servio' ); ?></label>
                            <input type="number" id="serviceflow-inv-taxrate" value="<?php echo esc_attr( $s['tax_rate'] ); ?>" min="0" max="100" step="0.01" />
                        </div>
                    </div>
                    <div class="serviceflow-inv-field">
                        <label><?php esc_html_e( 'Mention TVA (affiché si taux = 0%)', 'servio' ); ?></label>
                        <input type="text" id="serviceflow-inv-taxnotice" value="<?php echo esc_attr( $s['tax_notice'] ); ?>" placeholder="<?php esc_attr_e( 'TVA non applicable, article 293 B du CGI', 'servio' ); ?>" style="width:100%" />
                    </div>
                </div>

                <!-- Textes -->
                <div class="serviceflow-inv-section">
                    <h2><span class="dashicons dashicons-editor-alignleft"></span> <?php esc_html_e( 'Textes', 'servio' ); ?></h2>
                    <div class="serviceflow-inv-field">
                        <label><?php esc_html_e( 'Conditions de paiement', 'servio' ); ?></label>
                        <textarea id="serviceflow-inv-terms"><?php echo esc_textarea( $s['payment_terms'] ); ?></textarea>
                    </div>
                    <div class="serviceflow-inv-field">
                        <label><?php esc_html_e( 'Pied de page facture', 'servio' ); ?></label>
                        <textarea id="serviceflow-inv-footer"><?php echo esc_textarea( $s['footer_text'] ); ?></textarea>
                    </div>
                </div>

                <button type="button" id="serviceflow-inv-save-settings" class="button button-primary" style="background:<?php echo esc_attr( $color ); ?>;border-color:<?php echo esc_attr( $color ); ?>;padding:8px 24px;font-size:14px">
                    <?php esc_html_e( 'Enregistrer', 'servio' ); ?>
                </button>
            </div>

            <?php
            ob_start();
            ?>
            (function(){
                var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
                var nonce   = '<?php echo esc_js( $nonce ); ?>';

                // Logo uploader
                var uploadBtn = document.getElementById('serviceflow-inv-upload-logo');
                var removeBtn = document.getElementById('serviceflow-inv-remove-logo');
                var logoInput = document.getElementById('serviceflow-inv-company-logo');
                var preview   = document.querySelector('.serviceflow-inv-logo-preview');

                if(uploadBtn){
                    uploadBtn.addEventListener('click', function(){
                        var frame = wp.media({ title: '<?php echo esc_js( __( 'Choisir un logo', 'servio' ) ); ?>', multiple: false });
                        frame.on('select', function(){
                            var attachment = frame.state().get('selection').first().toJSON();
                            logoInput.value = attachment.url;
                            preview.innerHTML = '<img src="'+attachment.url+'" />';
                            removeBtn.style.display = '';
                        });
                        frame.open();
                    });
                }
                if(removeBtn){
                    removeBtn.addEventListener('click', function(){
                        logoInput.value = '';
                        preview.innerHTML = '';
                        removeBtn.style.display = 'none';
                    });
                }

                // Libellé SIRET/IFU select ↔ custom
                var siretSelect = document.getElementById('serviceflow-inv-siret-label-select');
                var siretCustom = document.getElementById('serviceflow-inv-siret-label-custom');
                var siretHidden = document.getElementById('serviceflow-inv-siret-label');
                function syncSiretLabel(){
                    if(siretSelect.value === 'custom'){
                        siretCustom.style.display = '';
                        siretHidden.value = siretCustom.value;
                    } else {
                        siretCustom.style.display = 'none';
                        siretHidden.value = siretSelect.value;
                    }
                }
                siretSelect.addEventListener('change', syncSiretLabel);
                siretCustom.addEventListener('input', function(){ siretHidden.value = this.value; });

                // Save
                document.getElementById('serviceflow-inv-save-settings').addEventListener('click', function(){
                    var btn = this;
                    btn.disabled = true;
                    btn.textContent = '<?php echo esc_js( __( 'Enregistrement...', 'servio' ) ); ?>';

                    var fd = new FormData();
                    fd.append('action', 'servio_save_invoice_settings');
                    fd.append('nonce', nonce);
                    fd.append('company_name', document.getElementById('serviceflow-inv-company-name').value);
                    fd.append('company_address', document.getElementById('serviceflow-inv-company-address').value);
                    fd.append('company_postal', document.getElementById('serviceflow-inv-company-postal').value);
                    fd.append('company_city', document.getElementById('serviceflow-inv-company-city').value);
                    fd.append('company_country', document.getElementById('serviceflow-inv-company-country').value);
                    fd.append('company_phone', document.getElementById('serviceflow-inv-company-phone').value);
                    fd.append('company_email', document.getElementById('serviceflow-inv-company-email').value);
                    fd.append('company_logo', document.getElementById('serviceflow-inv-company-logo').value);
                    fd.append('vat_number', document.getElementById('serviceflow-inv-vat').value);
                    fd.append('siret_ifu', document.getElementById('serviceflow-inv-siret').value);
                    fd.append('siret_label', document.getElementById('serviceflow-inv-siret-label').value);
                    fd.append('invoice_prefix', document.getElementById('serviceflow-inv-prefix').value);
                    fd.append('tax_rate', document.getElementById('serviceflow-inv-taxrate').value);
                    fd.append('tax_notice', document.getElementById('serviceflow-inv-taxnotice').value);
                    fd.append('payment_terms', document.getElementById('serviceflow-inv-terms').value);
                    fd.append('footer_text', document.getElementById('serviceflow-inv-footer').value);

                    fetch(ajaxUrl, {method:'POST', body:fd, credentials:'same-origin'})
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        btn.disabled = false;
                        btn.textContent = '<?php echo esc_js( __( 'Enregistrer', 'servio' ) ); ?>';
                        if(res.success){
                            btn.textContent = '<?php echo esc_js( __( 'Enregistré !', 'servio' ) ); ?>';
                            setTimeout(function(){ btn.textContent = '<?php echo esc_js( __( 'Enregistrer', 'servio' ) ); ?>'; }, 2000);
                        } else {
                            alert(res.data && res.data.message ? res.data.message : 'Erreur');
                        }
                    });
                });
            })();
            <?php
            wp_add_inline_script( 'servio-invoices-js', ob_get_clean() );
            ?>
        </div>
        <?php
    }

    /* ================================================================
     *  PAGE ADMIN — Clients externes
     * ================================================================ */

    public static function render_clients_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $clients = self::get_all_ext_clients();
        $color   = esc_attr( Servio_Admin::get_color() );
        $nonce   = wp_create_nonce( 'servio_nonce' );
        ?>
        <div class="wrap serviceflow-dashboard">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
                <span class="dashicons dashicons-groups" style="font-size:28px;width:28px;height:28px;color:<?php echo esc_attr( $color ); ?>"></span>
                <?php esc_html_e( 'Servio — Clients externes', 'servio' ); ?>
            </h1>

            <div class="serviceflow-clients-wrap">
                <div class="serviceflow-clients-list">
                    <table class="serviceflow-clients-table" id="serviceflow-cl-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Nom', 'servio' ); ?></th>
                                <th><?php esc_html_e( 'Email', 'servio' ); ?></th>
                                <th><?php esc_html_e( 'Société', 'servio' ); ?></th>
                                <th><?php esc_html_e( 'Ville', 'servio' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'servio' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $clients ) ) : ?>
                                <tr><td colspan="5" style="text-align:center;color:#888;padding:24px"><?php esc_html_e( 'Aucun client externe.', 'servio' ); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ( $clients as $cl ) : ?>
                                    <tr data-id="<?php echo (int) $cl->id; ?>"
                                        data-name="<?php echo esc_attr( $cl->name ); ?>"
                                        data-email="<?php echo esc_attr( $cl->email ); ?>"
                                        data-company="<?php echo esc_attr( $cl->company ); ?>"
                                        data-address="<?php echo esc_attr( $cl->address ); ?>"
                                        data-city="<?php echo esc_attr( $cl->city ); ?>"
                                        data-postal="<?php echo esc_attr( $cl->postal_code ); ?>"
                                        data-country="<?php echo esc_attr( $cl->country ); ?>"
                                        data-phone="<?php echo esc_attr( $cl->phone ); ?>"
                                        data-vat="<?php echo esc_attr( $cl->vat_number ); ?>"
                                        data-siret="<?php echo esc_attr( $cl->siret ); ?>"
                                        data-notes="<?php echo esc_attr( $cl->notes ); ?>">
                                        <td><?php echo esc_html( $cl->name ); ?></td>
                                        <td><?php echo esc_html( $cl->email ); ?></td>
                                        <td><?php echo esc_html( $cl->company ); ?></td>
                                        <td><?php echo esc_html( $cl->city ); ?></td>
                                        <td class="serviceflow-cl-actions">
                                            <a class="edit" data-id="<?php echo (int) $cl->id; ?>"><?php esc_html_e( 'Modifier', 'servio' ); ?></a>
                                            <a class="delete" data-id="<?php echo (int) $cl->id; ?>"><?php esc_html_e( 'Supprimer', 'servio' ); ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="serviceflow-clients-form">
                    <div class="serviceflow-cl-form-card">
                        <h3 id="serviceflow-cl-form-title"><?php esc_html_e( 'Ajouter un client', 'servio' ); ?></h3>
                        <input type="hidden" id="serviceflow-cl-id" value="0" />
                        <div class="serviceflow-cl-field">
                            <label><?php esc_html_e( 'Nom *', 'servio' ); ?></label>
                            <input type="text" id="serviceflow-cl-name" />
                        </div>
                        <div class="serviceflow-cl-field">
                            <label><?php esc_html_e( 'Email', 'servio' ); ?></label>
                            <input type="email" id="serviceflow-cl-email" />
                        </div>
                        <div class="serviceflow-cl-field">
                            <label><?php esc_html_e( 'Société', 'servio' ); ?></label>
                            <input type="text" id="serviceflow-cl-company" />
                        </div>
                        <div class="serviceflow-cl-field">
                            <label><?php esc_html_e( 'Adresse', 'servio' ); ?></label>
                            <textarea id="serviceflow-cl-address"></textarea>
                        </div>
                        <div class="serviceflow-cl-row">
                            <div class="serviceflow-cl-field">
                                <label><?php esc_html_e( 'Code postal', 'servio' ); ?></label>
                                <input type="text" id="serviceflow-cl-postal" />
                            </div>
                            <div class="serviceflow-cl-field">
                                <label><?php esc_html_e( 'Ville', 'servio' ); ?></label>
                                <input type="text" id="serviceflow-cl-city" />
                            </div>
                        </div>
                        <div class="serviceflow-cl-row">
                            <div class="serviceflow-cl-field">
                                <label><?php esc_html_e( 'Pays', 'servio' ); ?></label>
                                <input type="text" id="serviceflow-cl-country" value="France" />
                            </div>
                            <div class="serviceflow-cl-field">
                                <label><?php esc_html_e( 'Téléphone', 'servio' ); ?></label>
                                <input type="text" id="serviceflow-cl-phone" />
                            </div>
                        </div>
                        <div class="serviceflow-cl-row">
                            <div class="serviceflow-cl-field">
                                <label><?php esc_html_e( 'N° TVA', 'servio' ); ?></label>
                                <input type="text" id="serviceflow-cl-vat" />
                            </div>
                            <div class="serviceflow-cl-field">
                                <label><?php esc_html_e( 'SIRET / IFU', 'servio' ); ?></label>
                                <input type="text" id="serviceflow-cl-siret" />
                            </div>
                        </div>
                        <div class="serviceflow-cl-field">
                            <label><?php esc_html_e( 'Notes', 'servio' ); ?></label>
                            <textarea id="serviceflow-cl-notes"></textarea>
                        </div>
                        <button type="button" id="serviceflow-cl-save" class="button button-primary" style="background:<?php echo esc_attr( $color ); ?>;border-color:<?php echo esc_attr( $color ); ?>;margin-right:8px">
                            <?php esc_html_e( 'Enregistrer', 'servio' ); ?>
                        </button>
                        <button type="button" id="serviceflow-cl-reset" class="button"><?php esc_html_e( 'Annuler', 'servio' ); ?></button>
                    </div>
                </div>
            </div>

            <?php
            ob_start();
            ?>
            (function(){
                var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
                var nonce   = '<?php echo esc_js( $nonce ); ?>';

                function resetForm(){
                    document.getElementById('serviceflow-cl-id').value = '0';
                    document.getElementById('serviceflow-cl-form-title').textContent = '<?php echo esc_js( __( 'Ajouter un client', 'servio' ) ); ?>';
                    ['name','email','company','address','postal','city','phone','vat','siret','notes'].forEach(function(f){
                        document.getElementById('serviceflow-cl-'+f).value = '';
                    });
                    document.getElementById('serviceflow-cl-country').value = 'France';
                }

                document.getElementById('serviceflow-cl-reset').addEventListener('click', resetForm);

                // Edit
                document.querySelectorAll('.serviceflow-cl-actions .edit').forEach(function(a){
                    a.addEventListener('click', function(){
                        var tr = this.closest('tr');
                        document.getElementById('serviceflow-cl-id').value = tr.dataset.id;
                        document.getElementById('serviceflow-cl-name').value = tr.dataset.name;
                        document.getElementById('serviceflow-cl-email').value = tr.dataset.email;
                        document.getElementById('serviceflow-cl-company').value = tr.dataset.company;
                        document.getElementById('serviceflow-cl-address').value = tr.dataset.address;
                        document.getElementById('serviceflow-cl-city').value = tr.dataset.city;
                        document.getElementById('serviceflow-cl-postal').value = tr.dataset.postal;
                        document.getElementById('serviceflow-cl-country').value = tr.dataset.country;
                        document.getElementById('serviceflow-cl-phone').value = tr.dataset.phone;
                        document.getElementById('serviceflow-cl-vat').value = tr.dataset.vat;
                        document.getElementById('serviceflow-cl-siret').value = tr.dataset.siret;
                        document.getElementById('serviceflow-cl-notes').value = tr.dataset.notes;
                        document.getElementById('serviceflow-cl-form-title').textContent = '<?php echo esc_js( __( 'Modifier le client', 'servio' ) ); ?>';
                        document.querySelector('.serviceflow-clients-form').scrollIntoView({behavior:'smooth'});
                    });
                });

                // Delete
                document.querySelectorAll('.serviceflow-cl-actions .delete').forEach(function(a){
                    a.addEventListener('click', function(){
                        if(!confirm('<?php echo esc_js( __( 'Supprimer ce client ?', 'servio' ) ); ?>')) return;
                        var id = this.dataset.id;
                        var fd = new FormData();
                        fd.append('action','servio_delete_ext_client');
                        fd.append('nonce',nonce);
                        fd.append('client_id',id);
                        fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
                        .then(function(r){return r.json();})
                        .then(function(res){ if(res.success) location.reload(); else alert(res.data.message||'Erreur'); });
                    });
                });

                // Save
                document.getElementById('serviceflow-cl-save').addEventListener('click', function(){
                    var btn = this;
                    btn.disabled = true;
                    var fd = new FormData();
                    fd.append('action','servio_save_ext_client');
                    fd.append('nonce',nonce);
                    fd.append('client_id', document.getElementById('serviceflow-cl-id').value);
                    fd.append('name', document.getElementById('serviceflow-cl-name').value);
                    fd.append('email', document.getElementById('serviceflow-cl-email').value);
                    fd.append('company', document.getElementById('serviceflow-cl-company').value);
                    fd.append('address', document.getElementById('serviceflow-cl-address').value);
                    fd.append('city', document.getElementById('serviceflow-cl-city').value);
                    fd.append('postal_code', document.getElementById('serviceflow-cl-postal').value);
                    fd.append('country', document.getElementById('serviceflow-cl-country').value);
                    fd.append('phone', document.getElementById('serviceflow-cl-phone').value);
                    fd.append('vat_number', document.getElementById('serviceflow-cl-vat').value);
                    fd.append('siret', document.getElementById('serviceflow-cl-siret').value);
                    fd.append('notes', document.getElementById('serviceflow-cl-notes').value);
                    fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(res){ btn.disabled=false; if(res.success) location.reload(); else alert(res.data.message||'Erreur'); });
                });
            })();
            <?php
            wp_add_inline_script( 'servio-invoices-js', ob_get_clean() );
            ?>
        </div>
        <?php
    }

    /* ================================================================
     *  PAGE ADMIN — Liste des factures
     * ================================================================ */

    public static function render_invoices_list(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $filter   = sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin-only list filter, read-only.
        $invoices = self::get_invoices( $filter );
        $counts   = self::get_status_counts();
        $labels   = self::get_status_labels();
        $colors   = self::get_status_colors();
        $color    = esc_attr( Servio_Admin::get_color() );
        $nonce    = wp_create_nonce( 'servio_nonce' );
        $page_url = admin_url( 'admin.php?page=serviceflow-invoices' );
        ?>
        <div class="wrap serviceflow-dashboard">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
                <span class="dashicons dashicons-media-text" style="font-size:28px;width:28px;height:28px;color:<?php echo esc_attr( $color ); ?>"></span>
                <?php esc_html_e( 'Servio — Factures', 'servio' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=serviceflow-invoice-new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Nouvelle facture', 'servio' ); ?></a>
            </h1>

            <!-- Filtres -->
            <div class="serviceflow-inv-filters">
                <a href="<?php echo esc_url( $page_url ); ?>" class="serviceflow-inv-filter <?php echo empty( $filter ) ? 'active' : ''; ?>">
                    <?php esc_html_e( 'Toutes', 'servio' ); ?> <span class="count">(<?php echo absint( $counts['all'] ); ?>)</span>
                </a>
                <?php foreach ( $labels as $key => $label ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'status', $key, $page_url ) ); ?>" class="serviceflow-inv-filter <?php echo $filter === $key ? 'active' : ''; ?>">
                        <?php echo esc_html( $label ); ?> <span class="count">(<?php echo absint( $counts[ $key ] ?? 0 ); ?>)</span>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Tableau -->
            <table class="serviceflow-inv-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'N° Facture', 'servio' ); ?></th>
                        <th><?php esc_html_e( 'Client', 'servio' ); ?></th>
                        <th><?php esc_html_e( 'Commande', 'servio' ); ?></th>
                        <th><?php esc_html_e( 'Statut', 'servio' ); ?></th>
                        <th><?php esc_html_e( 'Total TTC', 'servio' ); ?></th>
                        <th><?php esc_html_e( 'Date', 'servio' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'servio' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $invoices ) ) : ?>
                        <tr><td colspan="7" style="text-align:center;color:#888;padding:24px"><?php esc_html_e( 'Aucune facture.', 'servio' ); ?></td></tr>
                    <?php else : ?>
                        <?php
                        $transitions_map = [
                            self::STATUS_DRAFT     => [ self::STATUS_PENDING, self::STATUS_VALIDATED, self::STATUS_CANCELLED ],
                            self::STATUS_PENDING   => [ self::STATUS_VALIDATED, self::STATUS_CANCELLED ],
                            self::STATUS_VALIDATED => [ self::STATUS_PAID, self::STATUS_CANCELLED ],
                            self::STATUS_PAID      => [],
                            self::STATUS_CANCELLED => [],
                        ];
                        ?>
                        <?php foreach ( $invoices as $inv ) :
                            $client_info  = self::get_client_info( $inv->client_id ? (int) $inv->client_id : null, $inv->ext_client_id ? (int) $inv->ext_client_id : null );
                            $client_name  = $client_info ? $client_info->name : '—';
                            $badge_color  = $colors[ $inv->status ] ?? '#9ca3af';
                            $status_text  = $labels[ $inv->status ] ?? $inv->status;
                            $view_url     = admin_url( 'admin.php?page=serviceflow-invoice-view&invoice_id=' . $inv->id );
                            $allowed_next = $transitions_map[ $inv->status ] ?? [];
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html( $inv->invoice_number ); ?></strong></td>
                            <td><?php echo esc_html( $client_name ); ?></td>
                            <td><?php echo $inv->order_id ? '#CMD-' . esc_html( $inv->order_id ) : '—'; ?></td>
                            <td>
                                <div class="serviceflow-inv-status-wrap">
                                    <span class="serviceflow-inv-badge <?php echo ! empty( $allowed_next ) ? 'clickable' : ''; ?>" style="background:<?php echo esc_attr( $badge_color ); ?>" <?php if ( ! empty( $allowed_next ) ) : ?>data-toggle-dd="dd-<?php echo (int) $inv->id; ?>"<?php endif; ?>><?php echo esc_html( $status_text ); ?></span>
                                    <?php if ( ! empty( $allowed_next ) ) : ?>
                                    <div class="serviceflow-inv-status-dd" id="dd-<?php echo (int) $inv->id; ?>">
                                        <?php foreach ( $allowed_next as $ns ) : ?>
                                            <a href="#" class="serviceflow-inv-set-status" data-id="<?php echo (int) $inv->id; ?>" data-status="<?php echo esc_attr( $ns ); ?>">
                                                <span class="serviceflow-inv-status-dot" style="background:<?php echo esc_attr( $colors[ $ns ] ?? '#999' ); ?>"></span>
                                                <?php echo esc_html( $labels[ $ns ] ?? $ns ); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><strong><?php echo esc_html( number_format( (float) $inv->total, 2, ',', ' ' ) ); ?> &euro;</strong></td>
                            <td><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $inv->created_at ) ) ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( $view_url ); ?>" class="serviceflow-inv-act" style="color:<?php echo esc_attr( $color ); ?>"><?php esc_html_e( 'Voir', 'servio' ); ?></a>
                                <?php if ( $inv->status === self::STATUS_DRAFT ) : ?>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=serviceflow-invoice-edit&invoice_id=' . $inv->id ) ); ?>" class="serviceflow-inv-act" style="color:#f59e0b"><?php esc_html_e( 'Modifier', 'servio' ); ?></a>
                                <?php endif; ?>
                                <?php if ( in_array( $inv->status, [ self::STATUS_DRAFT, self::STATUS_PENDING ], true ) ) : ?>
                                    <a class="serviceflow-inv-act serviceflow-inv-action" data-action="servio_invoice_validate" data-id="<?php echo (int) $inv->id; ?>" style="color:#10b981"><?php esc_html_e( 'Valider', 'servio' ); ?></a>
                                <?php endif; ?>
                                <?php if ( $inv->status === self::STATUS_VALIDATED ) : ?>
                                    <a class="serviceflow-inv-act serviceflow-inv-action" data-action="servio_invoice_mark_paid" data-id="<?php echo (int) $inv->id; ?>" style="color:#10b981"><?php esc_html_e( 'Payer', 'servio' ); ?></a>
                                <?php endif; ?>
                                <?php if ( ! in_array( $inv->status, [ self::STATUS_PAID, self::STATUS_CANCELLED ], true ) ) : ?>
                                    <a class="serviceflow-inv-act serviceflow-inv-action" data-action="servio_invoice_cancel" data-id="<?php echo (int) $inv->id; ?>" style="color:#ef4444"><?php esc_html_e( 'Annuler', 'servio' ); ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php
            ob_start();
            ?>
            (function(){
                var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
                var nonce   = '<?php echo esc_js( $nonce ); ?>';

                // Actions classiques (Valider, Payer, Annuler)
                document.querySelectorAll('.serviceflow-inv-action').forEach(function(a){
                    a.addEventListener('click', function(e){
                        e.preventDefault();
                        var action = this.dataset.action;
                        var id     = this.dataset.id;
                        var label  = action === 'servio_invoice_cancel' ? '<?php echo esc_js( __( 'Annuler cette facture ?', 'servio' ) ); ?>' : '<?php echo esc_js( __( 'Confirmer cette action ?', 'servio' ) ); ?>';
                        if(!confirm(label)) return;

                        var fd = new FormData();
                        fd.append('action', action);
                        fd.append('nonce', nonce);
                        fd.append('invoice_id', id);
                        fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
                        .then(function(r){return r.json();})
                        .then(function(res){ if(res.success) location.reload(); else alert(res.data.message||'Erreur'); });
                    });
                });

                // Toggle dropdown statut
                document.querySelectorAll('[data-toggle-dd]').forEach(function(badge){
                    badge.addEventListener('click', function(e){
                        e.stopPropagation();
                        var ddId = this.dataset.toggleDd;
                        var dd = document.getElementById(ddId);
                        // Fermer les autres
                        document.querySelectorAll('.serviceflow-inv-status-dd.open').forEach(function(d){
                            if(d.id !== ddId) d.classList.remove('open');
                        });
                        dd.classList.toggle('open');
                    });
                });

                // Fermer dropdown si clic ailleurs
                document.addEventListener('click', function(){
                    document.querySelectorAll('.serviceflow-inv-status-dd.open').forEach(function(d){
                        d.classList.remove('open');
                    });
                });

                // Changement de statut via dropdown
                document.querySelectorAll('.serviceflow-inv-set-status').forEach(function(a){
                    a.addEventListener('click', function(e){
                        e.preventDefault();
                        e.stopPropagation();
                        var id = this.dataset.id;
                        var newStatus = this.dataset.status;
                        if(!confirm('<?php echo esc_js( __( 'Changer le statut de cette facture ?', 'servio' ) ); ?>')) return;

                        var fd = new FormData();
                        fd.append('action', 'servio_invoice_set_status');
                        fd.append('nonce', nonce);
                        fd.append('invoice_id', id);
                        fd.append('new_status', newStatus);
                        fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
                        .then(function(r){return r.json();})
                        .then(function(res){ if(res.success) location.reload(); else alert(res.data&&res.data.message?res.data.message:'Erreur'); });
                    });
                });
            })();
            <?php
            wp_add_inline_script( 'servio-invoices-js', ob_get_clean() );
            ?>
        </div>
        <?php
    }

    /* ================================================================
     *  PAGE ADMIN — Nouvelle facture
     * ================================================================ */

    public static function render_invoice_new(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings    = self::get_settings();
        $color       = esc_attr( Servio_Admin::get_color() );
        $nonce       = wp_create_nonce( 'servio_nonce' );
        $ext_clients = self::get_all_ext_clients();

        // Utilisateurs WP non-admin
        $wp_users = get_users( [ 'role__not_in' => [ 'administrator' ], 'orderby' => 'display_name', 'number' => 200 ] );
        ?>
        <div class="wrap serviceflow-dashboard">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
                <span class="dashicons dashicons-plus-alt" style="font-size:28px;width:28px;height:28px;color:<?php echo esc_attr( $color ); ?>"></span>
                <?php esc_html_e( 'Servio — Nouvelle facture', 'servio' ); ?>
            </h1>

            <div id="serviceflow-newinv-form">
                <!-- Client -->
                <div class="serviceflow-newinv-section">
                    <h2><span class="dashicons dashicons-admin-users"></span> <?php esc_html_e( 'Client', 'servio' ); ?></h2>
                    <div class="serviceflow-newinv-radio">
                        <label><input type="radio" name="servio_client_type" value="wp" checked /> <?php esc_html_e( 'Utilisateur WordPress', 'servio' ); ?></label>
                        <label><input type="radio" name="servio_client_type" value="ext" /> <?php esc_html_e( 'Client externe', 'servio' ); ?></label>
                    </div>
                    <div class="serviceflow-newinv-field" id="serviceflow-newinv-wp-client">
                        <label><?php esc_html_e( 'Sélectionner un utilisateur', 'servio' ); ?></label>
                        <select id="serviceflow-newinv-client-id">
                            <option value=""><?php esc_html_e( '— Choisir —', 'servio' ); ?></option>
                            <?php foreach ( $wp_users as $u ) : ?>
                                <option value="<?php echo (int) $u->ID; ?>"><?php echo esc_html( $u->display_name . ' (' . $u->user_email . ')' ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="serviceflow-newinv-field" id="serviceflow-newinv-ext-client" style="display:none">
                        <label><?php esc_html_e( 'Sélectionner un client externe', 'servio' ); ?></label>
                        <select id="serviceflow-newinv-ext-id">
                            <option value=""><?php esc_html_e( '— Choisir —', 'servio' ); ?></option>
                            <?php foreach ( $ext_clients as $ec ) : ?>
                                <option value="<?php echo (int) $ec->id; ?>"><?php echo esc_html( $ec->name . ( $ec->company ? ' — ' . $ec->company : '' ) ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=serviceflow-clients' ) ); ?>" style="font-size:12px"><?php esc_html_e( 'Gérer les clients externes', 'servio' ); ?></a>
                    </div>
                </div>

                <!-- Articles -->
                <div class="serviceflow-newinv-section">
                    <h2><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Articles', 'servio' ); ?></h2>
                    <table class="serviceflow-items-table" id="serviceflow-items-table">
                        <thead>
                            <tr>
                                <th style="width:50%"><?php esc_html_e( 'Description', 'servio' ); ?></th>
                                <th style="width:10%"><?php esc_html_e( 'Qté', 'servio' ); ?></th>
                                <th style="width:20%"><?php esc_html_e( 'Prix unitaire HT', 'servio' ); ?></th>
                                <th style="width:15%"><?php esc_html_e( 'Total HT', 'servio' ); ?></th>
                                <th style="width:5%"></th>
                            </tr>
                        </thead>
                        <tbody id="serviceflow-items-body">
                            <tr class="serviceflow-item-row">
                                <td><input type="text" class="serviceflow-item-desc" placeholder="<?php esc_attr_e( 'Description du service', 'servio' ); ?>" /></td>
                                <td><input type="number" class="serviceflow-item-qty" value="1" min="1" step="1" /></td>
                                <td><input type="number" class="serviceflow-item-price" value="0" min="0" step="0.01" /></td>
                                <td class="serviceflow-item-total" style="text-align:right;font-weight:600">0,00 &euro;</td>
                                <td><button type="button" class="serviceflow-items-rm">&times;</button></td>
                            </tr>
                        </tbody>
                    </table>
                    <button type="button" id="serviceflow-add-item" class="button">+ <?php esc_html_e( 'Ajouter un article', 'servio' ); ?></button>

                    <div class="serviceflow-newinv-totals" style="margin-top:16px">
                        <div><?php esc_html_e( 'Sous-total HT', 'servio' ); ?> : <span id="serviceflow-newinv-subtotal">0,00</span> &euro;</div>
                        <div><?php esc_html_e( 'TVA', 'servio' ); ?> (<span id="serviceflow-newinv-taxrate-display"><?php echo esc_html( $settings['tax_rate'] ); ?></span>%) : <span id="serviceflow-newinv-tax">0,00</span> &euro;</div>
                        <div><strong><?php esc_html_e( 'Total TTC', 'servio' ); ?> : <span id="serviceflow-newinv-total">0,00</span> &euro;</strong></div>
                    </div>
                </div>

                <!-- TVA et notes -->
                <div class="serviceflow-newinv-section">
                    <h2><span class="dashicons dashicons-editor-alignleft"></span> <?php esc_html_e( 'Détails', 'servio' ); ?></h2>
                    <div class="serviceflow-newinv-field" style="max-width:200px">
                        <label><?php esc_html_e( 'Taux TVA (%)', 'servio' ); ?></label>
                        <input type="number" id="serviceflow-newinv-taxrate" value="<?php echo esc_attr( $settings['tax_rate'] ); ?>" min="0" max="100" step="0.01" />
                    </div>
                    <div class="serviceflow-newinv-field">
                        <label><?php esc_html_e( 'Notes / Conditions', 'servio' ); ?></label>
                        <textarea id="serviceflow-newinv-notes"><?php echo esc_textarea( $settings['payment_terms'] ); ?></textarea>
                    </div>
                </div>

                <!-- Actions -->
                <button type="button" class="button serviceflow-newinv-save" data-status="draft" style="margin-right:8px"><?php esc_html_e( 'Enregistrer en brouillon', 'servio' ); ?></button>
                <button type="button" class="button button-primary serviceflow-newinv-save" data-status="validated" style="background:<?php echo esc_attr( $color ); ?>;border-color:<?php echo esc_attr( $color ); ?>"><?php esc_html_e( 'Enregistrer et valider', 'servio' ); ?></button>
            </div>

            <?php
            ob_start();
            ?>
            (function(){
                var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
                var nonce   = '<?php echo esc_js( $nonce ); ?>';

                // Toggle client type
                document.querySelectorAll('input[name="servio_client_type"]').forEach(function(r){
                    r.addEventListener('change', function(){
                        document.getElementById('serviceflow-newinv-wp-client').style.display = this.value==='wp' ? '' : 'none';
                        document.getElementById('serviceflow-newinv-ext-client').style.display = this.value==='ext' ? '' : 'none';
                    });
                });

                // Calcul totaux
                function recalc(){
                    var subtotal = 0;
                    document.querySelectorAll('.serviceflow-item-row').forEach(function(row){
                        var qty   = parseFloat(row.querySelector('.serviceflow-item-qty').value) || 0;
                        var price = parseFloat(row.querySelector('.serviceflow-item-price').value) || 0;
                        var lt    = Math.round(qty * price * 100) / 100;
                        row.querySelector('.serviceflow-item-total').textContent = lt.toFixed(2).replace('.',',') + ' \u20ac';
                        subtotal += lt;
                    });
                    var rate = parseFloat(document.getElementById('serviceflow-newinv-taxrate').value) || 0;
                    var tax  = Math.round(subtotal * rate) / 100;
                    var total = subtotal + tax;
                    document.getElementById('serviceflow-newinv-subtotal').textContent = subtotal.toFixed(2).replace('.',',');
                    document.getElementById('serviceflow-newinv-tax').textContent = tax.toFixed(2).replace('.',',');
                    document.getElementById('serviceflow-newinv-total').textContent = total.toFixed(2).replace('.',',');
                    document.getElementById('serviceflow-newinv-taxrate-display').textContent = rate;
                }

                document.addEventListener('input', function(e){
                    if(e.target.classList.contains('serviceflow-item-qty') || e.target.classList.contains('serviceflow-item-price') || e.target.id === 'serviceflow-newinv-taxrate') recalc();
                });

                // Ajouter article
                document.getElementById('serviceflow-add-item').addEventListener('click', function(){
                    var row = document.createElement('tr');
                    row.className = 'serviceflow-item-row';
                    row.innerHTML = '<td><input type="text" class="serviceflow-item-desc" placeholder="<?php echo esc_js( __( 'Description du service', 'servio' ) ); ?>" /></td>' +
                        '<td><input type="number" class="serviceflow-item-qty" value="1" min="1" step="1" /></td>' +
                        '<td><input type="number" class="serviceflow-item-price" value="0" min="0" step="0.01" /></td>' +
                        '<td class="serviceflow-item-total" style="text-align:right;font-weight:600">0,00 \u20ac</td>' +
                        '<td><button type="button" class="serviceflow-items-rm">&times;</button></td>';
                    document.getElementById('serviceflow-items-body').appendChild(row);
                });

                // Supprimer article
                document.addEventListener('click', function(e){
                    if(e.target.classList.contains('serviceflow-items-rm')){
                        var rows = document.querySelectorAll('.serviceflow-item-row');
                        if(rows.length > 1){ e.target.closest('tr').remove(); recalc(); }
                    }
                });

                // Save
                document.querySelectorAll('.serviceflow-newinv-save').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        var status = this.dataset.status;
                        this.disabled = true;

                        var clientType = document.querySelector('input[name="servio_client_type"]:checked').value;
                        var fd = new FormData();
                        fd.append('action', 'servio_invoice_save');
                        fd.append('nonce', nonce);
                        fd.append('client_type', clientType);
                        fd.append('client_id', document.getElementById('serviceflow-newinv-client-id').value);
                        fd.append('ext_client_id', document.getElementById('serviceflow-newinv-ext-id').value);
                        fd.append('tax_rate', document.getElementById('serviceflow-newinv-taxrate').value);
                        fd.append('notes', document.getElementById('serviceflow-newinv-notes').value);
                        fd.append('save_status', status);

                        var rows = document.querySelectorAll('.serviceflow-item-row');
                        rows.forEach(function(row, i){
                            fd.append('items['+i+'][description]', row.querySelector('.serviceflow-item-desc').value);
                            fd.append('items['+i+'][quantity]', row.querySelector('.serviceflow-item-qty').value);
                            fd.append('items['+i+'][unit_price]', row.querySelector('.serviceflow-item-price').value);
                        });

                        fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
                        .then(function(r){return r.json();})
                        .then(function(res){
                            btn.disabled = false;
                            if(res.success){
                                window.location.href = '<?php echo esc_url( admin_url( 'admin.php?page=serviceflow-invoices' ) ); ?>';
                            } else {
                                alert(res.data && res.data.message ? res.data.message : 'Erreur');
                            }
                        });
                    });
                });
            })();
            <?php
            wp_add_inline_script( 'servio-invoices-js', ob_get_clean() );
            ?>
        </div>
        <?php
    }

    /* ================================================================
     *  PAGE ADMIN — Modifier facture (brouillon uniquement)
     * ================================================================ */

    public static function render_invoice_edit(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $invoice_id = absint( $_GET['invoice_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin-only render, capability checked above.
        $invoice    = $invoice_id ? self::get_invoice( $invoice_id ) : null;

        if ( ! $invoice || $invoice->status !== self::STATUS_DRAFT ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Seuls les brouillons peuvent être modifiés.', 'servio' ) . '</p></div>';
            return;
        }

        $settings    = self::get_settings();
        $color       = esc_attr( Servio_Admin::get_color() );
        $nonce       = wp_create_nonce( 'servio_nonce' );
        $ext_clients = self::get_all_ext_clients();
        $wp_users    = get_users( [ 'role__not_in' => [ 'administrator' ], 'orderby' => 'display_name', 'number' => 200 ] );
        $items       = json_decode( $invoice->items, true ) ?: [];
        $is_wp       = ! empty( $invoice->client_id );
        ?>
        <div class="wrap serviceflow-dashboard">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
                <span class="dashicons dashicons-edit" style="font-size:28px;width:28px;height:28px;color:<?php echo esc_attr( $color ); ?>"></span>
                <?php
                /* translators: %s: invoice number */
                printf( esc_html__( 'Modifier — %s', 'servio' ), esc_html( $invoice->invoice_number ) ); ?>
            </h1>

            <div id="serviceflow-newinv-form">
                <!-- Client -->
                <div class="serviceflow-newinv-section">
                    <h2><span class="dashicons dashicons-admin-users"></span> <?php esc_html_e( 'Client', 'servio' ); ?></h2>
                    <div class="serviceflow-newinv-radio">
                        <label><input type="radio" name="servio_client_type" value="wp" <?php checked( $is_wp ); ?> /> <?php esc_html_e( 'Utilisateur WordPress', 'servio' ); ?></label>
                        <label><input type="radio" name="servio_client_type" value="ext" <?php checked( ! $is_wp ); ?> /> <?php esc_html_e( 'Client externe', 'servio' ); ?></label>
                    </div>
                    <div class="serviceflow-newinv-field" id="serviceflow-newinv-wp-client" style="<?php echo $is_wp ? '' : 'display:none'; ?>">
                        <label><?php esc_html_e( 'Sélectionner un utilisateur', 'servio' ); ?></label>
                        <select id="serviceflow-newinv-client-id">
                            <option value=""><?php esc_html_e( '— Choisir —', 'servio' ); ?></option>
                            <?php foreach ( $wp_users as $u ) : ?>
                                <option value="<?php echo (int) $u->ID; ?>" <?php selected( (int) $invoice->client_id, $u->ID ); ?>><?php echo esc_html( $u->display_name . ' (' . $u->user_email . ')' ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="serviceflow-newinv-field" id="serviceflow-newinv-ext-client" style="<?php echo $is_wp ? 'display:none' : ''; ?>">
                        <label><?php esc_html_e( 'Sélectionner un client externe', 'servio' ); ?></label>
                        <select id="serviceflow-newinv-ext-id">
                            <option value=""><?php esc_html_e( '— Choisir —', 'servio' ); ?></option>
                            <?php foreach ( $ext_clients as $ec ) : ?>
                                <option value="<?php echo (int) $ec->id; ?>" <?php selected( (int) $invoice->ext_client_id, $ec->id ); ?>><?php echo esc_html( $ec->name . ( $ec->company ? ' — ' . $ec->company : '' ) ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Articles -->
                <div class="serviceflow-newinv-section">
                    <h2><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Articles', 'servio' ); ?></h2>
                    <table class="serviceflow-items-table" id="serviceflow-items-table">
                        <thead>
                            <tr>
                                <th style="width:50%"><?php esc_html_e( 'Description', 'servio' ); ?></th>
                                <th style="width:10%"><?php esc_html_e( 'Qté', 'servio' ); ?></th>
                                <th style="width:20%"><?php esc_html_e( 'Prix unitaire HT', 'servio' ); ?></th>
                                <th style="width:15%"><?php esc_html_e( 'Total HT', 'servio' ); ?></th>
                                <th style="width:5%"></th>
                            </tr>
                        </thead>
                        <tbody id="serviceflow-items-body">
                            <?php foreach ( $items as $item ) : ?>
                            <tr class="serviceflow-item-row">
                                <td><input type="text" class="serviceflow-item-desc" value="<?php echo esc_attr( $item['description'] ?? '' ); ?>" /></td>
                                <td><input type="number" class="serviceflow-item-qty" value="<?php echo esc_attr( $item['quantity'] ?? 1 ); ?>" min="1" step="1" /></td>
                                <td><input type="number" class="serviceflow-item-price" value="<?php echo esc_attr( $item['unit_price'] ?? 0 ); ?>" min="0" step="0.01" /></td>
                                <td class="serviceflow-item-total" style="text-align:right;font-weight:600"><?php echo esc_html( number_format( floatval( $item['total'] ?? 0 ), 2, ',', ' ' ) ); ?> &euro;</td>
                                <td><button type="button" class="serviceflow-items-rm">&times;</button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <button type="button" id="serviceflow-add-item" class="button">+ <?php esc_html_e( 'Ajouter un article', 'servio' ); ?></button>

                    <div class="serviceflow-newinv-totals" style="margin-top:16px">
                        <div><?php esc_html_e( 'Sous-total HT', 'servio' ); ?> : <span id="serviceflow-newinv-subtotal"><?php echo esc_html( number_format( (float) $invoice->subtotal, 2, ',', '' ) ); ?></span> &euro;</div>
                        <div><?php esc_html_e( 'TVA', 'servio' ); ?> (<span id="serviceflow-newinv-taxrate-display"><?php echo esc_html( $invoice->tax_rate ); ?></span>%) : <span id="serviceflow-newinv-tax"><?php echo esc_html( number_format( (float) $invoice->tax_amount, 2, ',', '' ) ); ?></span> &euro;</div>
                        <div><strong><?php esc_html_e( 'Total TTC', 'servio' ); ?> : <span id="serviceflow-newinv-total"><?php echo esc_html( number_format( (float) $invoice->total, 2, ',', '' ) ); ?></span> &euro;</strong></div>
                    </div>
                </div>

                <!-- TVA et notes -->
                <div class="serviceflow-newinv-section">
                    <h2><span class="dashicons dashicons-editor-alignleft"></span> <?php esc_html_e( 'Détails', 'servio' ); ?></h2>
                    <div class="serviceflow-newinv-field" style="max-width:200px">
                        <label><?php esc_html_e( 'Taux TVA (%)', 'servio' ); ?></label>
                        <input type="number" id="serviceflow-newinv-taxrate" value="<?php echo esc_attr( $invoice->tax_rate ); ?>" min="0" max="100" step="0.01" />
                    </div>
                    <div class="serviceflow-newinv-field">
                        <label><?php esc_html_e( 'Notes / Conditions', 'servio' ); ?></label>
                        <textarea id="serviceflow-newinv-notes"><?php echo esc_textarea( $invoice->notes ); ?></textarea>
                    </div>
                </div>

                <!-- Actions -->
                <button type="button" class="button serviceflow-newinv-save" data-status="draft" style="margin-right:8px"><?php esc_html_e( 'Enregistrer en brouillon', 'servio' ); ?></button>
                <button type="button" class="button button-primary serviceflow-newinv-save" data-status="validated" style="background:<?php echo esc_attr( $color ); ?>;border-color:<?php echo esc_attr( $color ); ?>"><?php esc_html_e( 'Enregistrer et valider', 'servio' ); ?></button>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=serviceflow-invoices' ) ); ?>" style="padding:8px 20px;font-size:13px;text-decoration:none;color:#555">&larr; <?php esc_html_e( 'Retour', 'servio' ); ?></a>
            </div>

            <?php
            ob_start();
            ?>
            (function(){
                var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
                var nonce   = '<?php echo esc_js( $nonce ); ?>';
                var invoiceId = <?php echo (int) $invoice->id; ?>;

                document.querySelectorAll('input[name="servio_client_type"]').forEach(function(r){
                    r.addEventListener('change', function(){
                        document.getElementById('serviceflow-newinv-wp-client').style.display = this.value==='wp' ? '' : 'none';
                        document.getElementById('serviceflow-newinv-ext-client').style.display = this.value==='ext' ? '' : 'none';
                    });
                });

                function recalc(){
                    var subtotal = 0;
                    document.querySelectorAll('.serviceflow-item-row').forEach(function(row){
                        var qty   = parseFloat(row.querySelector('.serviceflow-item-qty').value) || 0;
                        var price = parseFloat(row.querySelector('.serviceflow-item-price').value) || 0;
                        var lt    = Math.round(qty * price * 100) / 100;
                        row.querySelector('.serviceflow-item-total').textContent = lt.toFixed(2).replace('.',',') + ' \u20ac';
                        subtotal += lt;
                    });
                    var rate = parseFloat(document.getElementById('serviceflow-newinv-taxrate').value) || 0;
                    var tax  = Math.round(subtotal * rate) / 100;
                    var total = subtotal + tax;
                    document.getElementById('serviceflow-newinv-subtotal').textContent = subtotal.toFixed(2).replace('.',',');
                    document.getElementById('serviceflow-newinv-tax').textContent = tax.toFixed(2).replace('.',',');
                    document.getElementById('serviceflow-newinv-total').textContent = total.toFixed(2).replace('.',',');
                    document.getElementById('serviceflow-newinv-taxrate-display').textContent = rate;
                }

                document.addEventListener('input', function(e){
                    if(e.target.classList.contains('serviceflow-item-qty') || e.target.classList.contains('serviceflow-item-price') || e.target.id === 'serviceflow-newinv-taxrate') recalc();
                });

                document.getElementById('serviceflow-add-item').addEventListener('click', function(){
                    var row = document.createElement('tr');
                    row.className = 'serviceflow-item-row';
                    row.innerHTML = '<td><input type="text" class="serviceflow-item-desc" placeholder="<?php echo esc_js( __( 'Description du service', 'servio' ) ); ?>" /></td>' +
                        '<td><input type="number" class="serviceflow-item-qty" value="1" min="1" step="1" /></td>' +
                        '<td><input type="number" class="serviceflow-item-price" value="0" min="0" step="0.01" /></td>' +
                        '<td class="serviceflow-item-total" style="text-align:right;font-weight:600">0,00 \u20ac</td>' +
                        '<td><button type="button" class="serviceflow-items-rm">&times;</button></td>';
                    document.getElementById('serviceflow-items-body').appendChild(row);
                });

                document.addEventListener('click', function(e){
                    if(e.target.classList.contains('serviceflow-items-rm')){
                        var rows = document.querySelectorAll('.serviceflow-item-row');
                        if(rows.length > 1){ e.target.closest('tr').remove(); recalc(); }
                    }
                });

                document.querySelectorAll('.serviceflow-newinv-save').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        var status = this.dataset.status;
                        this.disabled = true;

                        var clientType = document.querySelector('input[name="servio_client_type"]:checked').value;
                        var fd = new FormData();
                        fd.append('action', 'servio_invoice_update');
                        fd.append('nonce', nonce);
                        fd.append('invoice_id', invoiceId);
                        fd.append('client_type', clientType);
                        fd.append('client_id', document.getElementById('serviceflow-newinv-client-id').value);
                        fd.append('ext_client_id', document.getElementById('serviceflow-newinv-ext-id').value);
                        fd.append('tax_rate', document.getElementById('serviceflow-newinv-taxrate').value);
                        fd.append('notes', document.getElementById('serviceflow-newinv-notes').value);
                        fd.append('save_status', status);

                        var rows = document.querySelectorAll('.serviceflow-item-row');
                        rows.forEach(function(row, i){
                            fd.append('items['+i+'][description]', row.querySelector('.serviceflow-item-desc').value);
                            fd.append('items['+i+'][quantity]', row.querySelector('.serviceflow-item-qty').value);
                            fd.append('items['+i+'][unit_price]', row.querySelector('.serviceflow-item-price').value);
                        });

                        fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
                        .then(function(r){return r.json();})
                        .then(function(res){
                            btn.disabled = false;
                            if(res.success){
                                window.location.href = '<?php echo esc_url( admin_url( 'admin.php?page=serviceflow-invoices' ) ); ?>';
                            } else {
                                alert(res.data && res.data.message ? res.data.message : 'Erreur');
                            }
                        });
                    });
                });
            })();
            <?php
            wp_add_inline_script( 'servio-invoices-js', ob_get_clean() );
            ?>
        </div>
        <?php
    }

    /* ================================================================
     *  PAGE ADMIN — Vue facture (imprimable)
     * ================================================================ */

    public static function render_invoice_view(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $invoice_id = absint( $_GET['invoice_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin-only render, capability checked above.
        if ( ! $invoice_id ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Facture introuvable.', 'servio' ) . '</p></div>';
            return;
        }

        $invoice = self::get_invoice( $invoice_id );
        if ( ! $invoice ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Facture introuvable.', 'servio' ) . '</p></div>';
            return;
        }

        self::render_invoice_html( $invoice, true );
    }

    /* ================================================================
     *  RENDU HTML FACTURE (partagé admin/client)
     * ================================================================ */

    private static function render_invoice_html( object $invoice, bool $is_admin ): void {
        $s           = self::get_settings();
        $color       = esc_attr( Servio_Admin::get_color() );
        $labels      = self::get_status_labels();
        $colors      = self::get_status_colors();
        $items       = json_decode( $invoice->items, true ) ?: [];
        $client_info = self::get_client_info(
            $invoice->client_id ? (int) $invoice->client_id : null,
            $invoice->ext_client_id ? (int) $invoice->ext_client_id : null
        );
        $nonce       = wp_create_nonce( 'servio_nonce' );
        $badge_color = $colors[ $invoice->status ] ?? '#9ca3af';
        $status_text = $labels[ $invoice->status ] ?? $invoice->status;
        ?>
        <div class="serviceflow-invoice-page">
            <!-- Header : logo + entreprise à gauche, infos facture à droite -->
            <div class="serviceflow-inv-header">
                <div class="serviceflow-inv-header-left">
                    <?php if ( ! empty( $s['company_logo'] ) ) : ?>
                        <div class="serviceflow-inv-logo">
                            <img src="<?php echo esc_url( $s['company_logo'] ); ?>" alt="" />
                        </div>
                    <?php else : ?>
                        <div class="serviceflow-inv-company">
                            <strong><?php echo esc_html( $s['company_name'] ); ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="serviceflow-inv-header-right">
                    <h3><?php
                        $invoice_type_val = $invoice->invoice_type ?? 'single';
                        if ( $invoice_type_val === 'acompte' ) {
                            esc_html_e( "Facture d'acompte", 'servio' );
                        } elseif ( $invoice_type_val === 'solde' ) {
                            esc_html_e( 'Facture de solde', 'servio' );
                        } elseif ( $invoice_type_val === 'mensualite' ) {
                            $sched_row = $invoice->schedule_id && class_exists( 'Servio_Payments' )
                                ? Servio_Payments::get_row( (int) $invoice->schedule_id )
                                : null;
                            $n = $sched_row ? (int) $sched_row->installment_no : 0;
                            /* translators: %d: installment number */
                            echo esc_html( sprintf( __( 'Facture — Mensualité %d', 'servio' ), $n ) );
                        } else {
                            esc_html_e( 'Facture', 'servio' );
                        }
                    ?></h3>
                    <p>
                        <strong><?php echo esc_html( $invoice->invoice_number ); ?></strong><br />
                        <?php esc_html_e( 'Date', 'servio' ); ?> : <?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $invoice->created_at ) ) ); ?>
                        <?php if ( $invoice->order_id ) : ?><br /><?php esc_html_e( 'Commande', 'servio' ); ?> : #CMD-<?php echo esc_html( $invoice->order_id ); ?><?php endif; ?>
                        <?php if ( $invoice->paid_at ) : ?><br /><?php esc_html_e( 'Payée le', 'servio' ); ?> : <?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $invoice->paid_at ) ) ); ?><?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Émetteur + Client côte à côte -->
            <div class="serviceflow-inv-parties">
                <div class="serviceflow-inv-emetteur">
                    <h3><?php esc_html_e( 'Émetteur', 'servio' ); ?></h3>
                    <p>
                        <strong><?php echo esc_html( $s['company_name'] ); ?></strong><br />
                        <?php if ( $s['company_address'] ) : ?><?php echo nl2br( esc_html( $s['company_address'] ) ); ?><br /><?php endif; ?>
                        <?php if ( $s['company_postal'] || $s['company_city'] ) : ?><?php echo esc_html( $s['company_postal'] . ' ' . $s['company_city'] ); ?><br /><?php endif; ?>
                        <?php if ( $s['company_country'] ) : ?><?php echo esc_html( $s['company_country'] ); ?><br /><?php endif; ?>
                        <?php if ( $s['company_phone'] ) : ?><?php echo esc_html( $s['company_phone'] ); ?><br /><?php endif; ?>
                        <?php if ( $s['company_email'] ) : ?><?php echo esc_html( $s['company_email'] ); ?><br /><?php endif; ?>
                        <?php if ( $s['vat_number'] ) : ?>TVA : <?php echo esc_html( $s['vat_number'] ); ?><br /><?php endif; ?>
                        <?php if ( $s['siret_ifu'] ) : ?><?php echo esc_html( $s['siret_label'] ?: 'SIRET/IFU' ); ?> : <?php echo esc_html( $s['siret_ifu'] ); ?><?php endif; ?>
                    </p>
                </div>
                <div class="serviceflow-inv-client">
                <h3><?php esc_html_e( 'Client', 'servio' ); ?></h3>
                <?php if ( $client_info ) : ?>
                    <p>
                        <strong><?php echo esc_html( $client_info->name ); ?></strong><br />
                        <?php if ( ! empty( $client_info->company ) ) : ?><?php echo esc_html( $client_info->company ); ?><br /><?php endif; ?>
                        <?php if ( ! empty( $client_info->address ) ) : ?><?php echo nl2br( esc_html( $client_info->address ) ); ?><br /><?php endif; ?>
                        <?php if ( ! empty( $client_info->postal_code ) || ! empty( $client_info->city ) ) : ?><?php echo esc_html( ( $client_info->postal_code ?? '' ) . ' ' . ( $client_info->city ?? '' ) ); ?><br /><?php endif; ?>
                        <?php if ( ! empty( $client_info->email ) ) : ?><?php echo esc_html( $client_info->email ); ?><br /><?php endif; ?>
                        <?php if ( ! empty( $client_info->vat_number ) ) : ?>TVA : <?php echo esc_html( $client_info->vat_number ); ?><?php endif; ?>
                    </p>
                <?php else : ?>
                    <p style="color:#999"><?php esc_html_e( 'Client inconnu', 'servio' ); ?></p>
                <?php endif; ?>
                </div>
            </div>

            <!-- Tableau articles -->
            <table class="serviceflow-inv-items-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Description', 'servio' ); ?></th>
                        <th class="text-right"><?php esc_html_e( 'Qté', 'servio' ); ?></th>
                        <th class="text-right"><?php esc_html_e( 'Prix unitaire HT', 'servio' ); ?></th>
                        <th class="text-right"><?php esc_html_e( 'Total HT', 'servio' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $items as $item ) :
                        $is_info = ! empty( $item['info_only'] );
                    ?>
                        <tr<?php if ( $is_info ) : ?> style="color:#888;font-size:0.92em"<?php endif; ?>>
                            <td>
                                <?php if ( ! empty( $item['service_name'] ) ) : ?>
                                    <strong><?php echo esc_html( $item['service_name'] ); ?></strong> &mdash;
                                <?php endif; ?>
                                <?php echo esc_html( $item['description'] ?? '' ); ?>
                            </td>
                            <td class="text-right"><?php echo esc_html( $item['quantity'] ?? 1 ); ?></td>
                            <?php if ( $is_info ) : ?>
                            <td class="text-right" style="color:#aaa"><?php esc_html_e( 'Inclus', 'servio' ); ?></td>
                            <td class="text-right" style="color:#aaa">—</td>
                            <?php else : ?>
                            <td class="text-right"><?php echo esc_html( number_format( floatval( $item['unit_price'] ?? 0 ), 2, ',', ' ' ) ); ?> &euro;</td>
                            <td class="text-right"><?php echo esc_html( number_format( floatval( $item['total'] ?? 0 ), 2, ',', ' ' ) ); ?> &euro;</td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Totaux -->
            <div class="serviceflow-inv-totals">
                <?php if ( floatval( $invoice->tax_rate ) > 0 ) : ?>
                    <div class="serviceflow-inv-totals-row">
                        <span><?php esc_html_e( 'Sous-total HT', 'servio' ); ?></span>
                        <span><?php echo esc_html( number_format( (float) $invoice->subtotal, 2, ',', ' ' ) ); ?> &euro;</span>
                    </div>
                    <div class="serviceflow-inv-totals-row">
                        <span><?php esc_html_e( 'TVA', 'servio' ); ?> (<?php echo esc_html( $invoice->tax_rate ); ?>%)</span>
                        <span><?php echo esc_html( number_format( (float) $invoice->tax_amount, 2, ',', ' ' ) ); ?> &euro;</span>
                    </div>
                    <div class="serviceflow-inv-totals-row total-row">
                        <span><?php esc_html_e( 'Total TTC', 'servio' ); ?></span>
                        <span><?php echo esc_html( number_format( (float) $invoice->total, 2, ',', ' ' ) ); ?> &euro;</span>
                    </div>
                <?php else : ?>
                    <div class="serviceflow-inv-totals-row total-row">
                        <span><?php esc_html_e( 'Total', 'servio' ); ?></span>
                        <span><?php echo esc_html( number_format( (float) $invoice->total, 2, ',', ' ' ) ); ?> &euro;</span>
                    </div>
                    <?php if ( ! empty( $s['tax_notice'] ) ) : ?>
                        <div class="serviceflow-inv-totals-row" style="font-size:11px;color:#888;font-style:italic;border-top:none;padding-top:4px">
                            <span><?php echo esc_html( $s['tax_notice'] ); ?></span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Notes -->
            <?php if ( ! empty( $invoice->notes ) ) : ?>
                <div class="serviceflow-inv-notes">
                    <strong><?php esc_html_e( 'Conditions', 'servio' ); ?></strong><br />
                    <?php echo nl2br( esc_html( $invoice->notes ) ); ?>
                </div>
            <?php endif; ?>

            <!-- Footer -->
            <?php if ( ! empty( $s['footer_text'] ) ) : ?>
                <div class="serviceflow-inv-footer-text"><?php echo nl2br( esc_html( $s['footer_text'] ) ); ?></div>
            <?php endif; ?>
        </div>

        <!-- Actions (non imprimables) -->
        <div class="serviceflow-inv-actions no-print" style="max-width:800px;margin:0 auto">
            <button onclick="sfPrintInvoice()" style="background:<?php echo esc_attr( $color ); ?>;color:#fff">
                <?php esc_html_e( 'Imprimer', 'servio' ); ?>
            </button>
            <?php if ( $is_admin ) : ?>
                <?php if ( in_array( $invoice->status, [ self::STATUS_DRAFT, self::STATUS_PENDING ], true ) ) : ?>
                    <button class="serviceflow-inv-view-action" data-action="servio_invoice_validate" data-id="<?php echo (int) $invoice->id; ?>" style="background:#10b981;color:#fff">
                        <?php esc_html_e( 'Valider', 'servio' ); ?>
                    </button>
                <?php endif; ?>
                <?php if ( $invoice->status === self::STATUS_VALIDATED ) : ?>
                    <button class="serviceflow-inv-view-action" data-action="servio_invoice_mark_paid" data-id="<?php echo (int) $invoice->id; ?>" style="background:#10b981;color:#fff">
                        <?php esc_html_e( 'Marquer comme payée', 'servio' ); ?>
                    </button>
                <?php endif; ?>
                <?php if ( ! in_array( $invoice->status, [ self::STATUS_PAID, self::STATUS_CANCELLED ], true ) ) : ?>
                    <button class="serviceflow-inv-view-action" data-action="servio_invoice_cancel" data-id="<?php echo (int) $invoice->id; ?>" style="background:#ef4444;color:#fff">
                        <?php esc_html_e( 'Annuler', 'servio' ); ?>
                    </button>
                <?php endif; ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=serviceflow-invoices' ) ); ?>" style="padding:8px 20px;font-size:13px;text-decoration:none;color:#555">&larr; <?php esc_html_e( 'Retour à la liste', 'servio' ); ?></a>
            <?php endif; ?>
        </div>

        <?php
        wp_add_inline_script(
            'servio-invoices-js',
            'function sfPrintInvoice(){' .
            'var content=document.querySelector(".serviceflow-invoice-page");' .
            'if(!content){window.print();return;}' .
            'var styles="";' .
            'document.querySelectorAll("link[rel=\'stylesheet\'],style").forEach(function(el){styles+=el.outerHTML;});' .
            'var win=window.open("","_blank","width=900,height=700");' .
            'if(!win){window.print();return;}' .
            'win.document.write("<!DOCTYPE html><html><head><meta charset=\'utf-8\'>"+styles+"<style>.no-print,.serviceflow-inv-actions{display:none!important}</style></head><body>");' .
            'win.document.write(content.outerHTML);' .
            'win.document.write("</body></html>");' .
            'win.document.close();' .
            'win.focus();' .
            'win.onload=function(){win.print();};' .
            '}'
        );
        if ( $is_admin ) :
            ob_start();
            ?>
            (function(){
                var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
                var nonce   = '<?php echo esc_js( $nonce ); ?>';
                document.querySelectorAll('.serviceflow-inv-view-action').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        if(!confirm('<?php echo esc_js( __( 'Confirmer cette action ?', 'servio' ) ); ?>')) return;
                        btn.disabled = true;
                        var fd = new FormData();
                        fd.append('action', this.dataset.action);
                        fd.append('nonce', nonce);
                        fd.append('invoice_id', this.dataset.id);
                        fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
                        .then(function(r){return r.json();})
                        .then(function(res){ if(res.success) location.reload(); else { btn.disabled=false; alert(res.data.message||'Erreur'); } });
                    });
                });
            })();
            <?php
            wp_add_inline_script( 'servio-invoices-js', ob_get_clean() );
        endif; ?>
        <?php
    }
}
