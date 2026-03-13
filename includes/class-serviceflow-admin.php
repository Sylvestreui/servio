<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServiceFlow_Admin {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
    }

    public static function add_menu(): void {
        add_submenu_page(
            'serviceflow',
            __( 'ServiceFlow - Réglages', 'serviceflow' ),
            __( 'Réglages', 'serviceflow' ),
            'manage_options',
            'serviceflow-settings',
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    public static function register_settings(): void {
        // CPT
        register_setting( 'serviceflow_settings', 'serviceflow_post_type', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'annonce',
        ] );

        // Couleur principale
        register_setting( 'serviceflow_settings', 'serviceflow_color', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#0073aa',
        ] );

        // Position du bouton
        register_setting( 'serviceflow_settings', 'serviceflow_position', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'bottom-right',
        ] );

        // Pages supplémentaires
        register_setting( 'serviceflow_settings', 'serviceflow_extra_pages', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '0',
        ] );

        // Maintenance mensuelle
        register_setting( 'serviceflow_settings', 'serviceflow_maintenance', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '0',
        ] );

        // Livraison express
        register_setting( 'serviceflow_settings', 'serviceflow_express', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '0',
        ] );

        // Section générale
        add_settings_section(
            'serviceflow_main_section',
            __( 'Configuration générale', 'serviceflow' ),
            null,
            'serviceflow-settings'
        );

        add_settings_field(
            'serviceflow_post_type',
            __( 'Custom Post Type', 'serviceflow' ),
            [ __CLASS__, 'render_post_type_field' ],
            'serviceflow-settings',
            'serviceflow_main_section'
        );

        // Section apparence
        add_settings_section(
            'serviceflow_style_section',
            __( 'Apparence', 'serviceflow' ),
            null,
            'serviceflow-settings'
        );

        add_settings_field(
            'serviceflow_color',
            __( 'Couleur du chat', 'serviceflow' ),
            [ __CLASS__, 'render_color_field' ],
            'serviceflow-settings',
            'serviceflow_style_section'
        );

        add_settings_field(
            'serviceflow_position',
            __( 'Position du bouton', 'serviceflow' ),
            [ __CLASS__, 'render_position_field' ],
            'serviceflow-settings',
            'serviceflow_style_section'
        );

        // Section options avancées
        if ( serviceflow_is_premium() ) {
            add_settings_section(
                'serviceflow_addons_section',
                __( 'Options avancées', 'serviceflow' ),
                null,
                'serviceflow-settings'
            );

            add_settings_field(
                'serviceflow_extra_pages',
                __( 'Pages supplémentaires', 'serviceflow' ),
                [ __CLASS__, 'render_extra_pages_field' ],
                'serviceflow-settings',
                'serviceflow_addons_section'
            );

            add_settings_field(
                'serviceflow_maintenance',
                __( 'Maintenance mensuelle', 'serviceflow' ),
                [ __CLASS__, 'render_maintenance_field' ],
                'serviceflow-settings',
                'serviceflow_addons_section'
            );

            add_settings_field(
                'serviceflow_express',
                __( 'Livraison express', 'serviceflow' ),
                [ __CLASS__, 'render_express_field' ],
                'serviceflow-settings',
                'serviceflow_addons_section'
            );
        }
    }

    public static function render_post_type_field(): void {
        $current    = get_option( 'serviceflow_post_type', 'annonce' );
        $post_types = get_post_types( [ 'public' => true ], 'objects' );

        echo '<select name="serviceflow_post_type" id="serviceflow_post_type">';
        foreach ( $post_types as $pt ) {
            printf(
                '<option value="%s" %s>%s (%s)</option>',
                esc_attr( $pt->name ),
                selected( $current, $pt->name, false ),
                esc_html( $pt->labels->singular_name ),
                esc_html( $pt->name )
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Sélectionnez le CPT sur lequel activer le chat.', 'serviceflow' ) . '</p>';
    }

    public static function render_color_field(): void {
        $color = get_option( 'serviceflow_color', '#0073aa' );
        printf(
            '<input type="color" name="serviceflow_color" id="serviceflow_color" value="%s" />',
            esc_attr( $color )
        );
        echo '<p class="description">' . esc_html__( 'Couleur principale du bouton et du header du chat.', 'serviceflow' ) . '</p>';
    }

    public static function render_position_field(): void {
        $current = get_option( 'serviceflow_position', 'bottom-right' );

        $positions = [
            'bottom-right' => __( 'Bas droite', 'serviceflow' ),
            'bottom-left'  => __( 'Bas gauche', 'serviceflow' ),
            'top-right'    => __( 'Haut droite', 'serviceflow' ),
            'top-left'     => __( 'Haut gauche', 'serviceflow' ),
        ];

        echo '<select name="serviceflow_position" id="serviceflow_position">';
        foreach ( $positions as $value => $label ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $value ),
                selected( $current, $value, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
    }

    public static function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'serviceflow_settings' );
                do_settings_sections( 'serviceflow-settings' );
                submit_button( __( 'Enregistrer', 'serviceflow' ) );
                ?>
            </form>
        </div>
        <?php
    }

    public static function render_extra_pages_field(): void {
        $enabled = get_option( 'serviceflow_extra_pages', '0' );
        printf(
            '<label><input type="checkbox" name="serviceflow_extra_pages" value="1" %s /> %s</label>',
            checked( $enabled, '1', false ),
            esc_html__( 'Activer', 'serviceflow' )
        );
        echo '<p class="description">' . esc_html__( 'Permet au client de commander des pages supplémentaires. Le prix unitaire est défini par service dans la métabox.', 'serviceflow' ) . '</p>';
    }

    public static function render_maintenance_field(): void {
        $enabled = get_option( 'serviceflow_maintenance', '0' );
        printf(
            '<label><input type="checkbox" name="serviceflow_maintenance" value="1" %s /> %s</label>',
            checked( $enabled, '1', false ),
            esc_html__( 'Activer', 'serviceflow' )
        );
        echo '<p class="description">' . esc_html__( 'Propose un abonnement maintenance mensuel. Le prix est défini par service dans la métabox.', 'serviceflow' ) . '</p>';
    }

    public static function render_express_field(): void {
        $enabled = get_option( 'serviceflow_express', '0' );
        printf(
            '<label><input type="checkbox" name="serviceflow_express" value="1" %s /> %s</label>',
            checked( $enabled, '1', false ),
            esc_html__( 'Activer', 'serviceflow' )
        );
        echo '<p class="description">' . esc_html__( 'Permet au client de réduire le délai de livraison moyennant un supplément par jour. Le prix par jour est défini par service dans la métabox.', 'serviceflow' ) . '</p>';
    }

    public static function is_express_enabled(): bool {
        return get_option( 'serviceflow_express', '0' ) === '1';
    }

    public static function is_extra_pages_enabled(): bool {
        return get_option( 'serviceflow_extra_pages', '0' ) === '1';
    }

    public static function is_maintenance_enabled(): bool {
        return get_option( 'serviceflow_maintenance', '0' ) === '1';
    }

    public static function get_post_type(): string {
        return get_option( 'serviceflow_post_type', 'annonce' );
    }

    public static function get_color(): string {
        return get_option( 'serviceflow_color', '#0073aa' );
    }

    public static function get_position(): string {
        return get_option( 'serviceflow_position', 'bottom-right' );
    }
}
