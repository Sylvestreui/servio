<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Servio_Admin {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
    }

    public static function add_menu(): void {
        add_submenu_page(
            'servio',
            __( 'Servio - Réglages', 'servio' ),
            __( 'Réglages', 'servio' ),
            'manage_options',
            'serviceflow-settings',
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    public static function register_settings(): void {
        // CPT
        register_setting( 'servio_settings', 'servio_post_type', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'annonce',
        ] );

        // Couleur principale
        register_setting( 'servio_settings', 'servio_color', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#0073aa',
        ] );

        // Position du bouton
        register_setting( 'servio_settings', 'servio_position', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'bottom-right',
        ] );

        // Section générale
        add_settings_section(
            'servio_main_section',
            __( 'Configuration générale', 'servio' ),
            null,
            'serviceflow-settings'
        );

        add_settings_field(
            'servio_post_type',
            __( 'Custom Post Type', 'servio' ),
            [ __CLASS__, 'render_post_type_field' ],
            'serviceflow-settings',
            'servio_main_section'
        );

        // Section apparence
        add_settings_section(
            'servio_style_section',
            __( 'Apparence', 'servio' ),
            null,
            'serviceflow-settings'
        );

        add_settings_field(
            'servio_color',
            __( 'Couleur du chat', 'servio' ),
            [ __CLASS__, 'render_color_field' ],
            'serviceflow-settings',
            'servio_style_section'
        );

        add_settings_field(
            'servio_position',
            __( 'Position du bouton', 'servio' ),
            [ __CLASS__, 'render_position_field' ],
            'serviceflow-settings',
            'servio_style_section'
        );

    }

    public static function render_post_type_field(): void {
        $current    = get_option( 'servio_post_type', 'annonce' );
        $post_types = get_post_types( [ 'public' => true ], 'objects' );

        echo '<select name="servio_post_type" id="servio_post_type">';
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
        echo '<p class="description">' . esc_html__( 'Sélectionnez le CPT sur lequel activer le chat.', 'servio' ) . '</p>';
    }

    public static function render_color_field(): void {
        $color = get_option( 'servio_color', '#0073aa' );
        printf(
            '<input type="color" name="servio_color" id="servio_color" value="%s" />',
            esc_attr( $color )
        );
        echo '<p class="description">' . esc_html__( 'Couleur principale du bouton et du header du chat.', 'servio' ) . '</p>';
    }

    public static function render_position_field(): void {
        $current = get_option( 'servio_position', 'bottom-right' );

        $positions = [
            'bottom-right' => __( 'Bas droite', 'servio' ),
            'bottom-left'  => __( 'Bas gauche', 'servio' ),
            'top-right'    => __( 'Haut droite', 'servio' ),
            'top-left'     => __( 'Haut gauche', 'servio' ),
        ];

        echo '<select name="servio_position" id="servio_position">';
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
                settings_fields( 'servio_settings' );
                do_settings_sections( 'serviceflow-settings' );
                submit_button( __( 'Enregistrer', 'servio' ) );
                ?>
            </form>
        </div>
        <?php
    }

    public static function is_extra_pages_enabled( int $post_id = 0 ): bool {
        if ( ! $post_id ) {
            return false;
        }
        return get_post_meta( $post_id, '_servio_extra_pages_enabled', true ) === '1';
    }

    public static function is_maintenance_enabled( int $post_id = 0 ): bool {
        if ( ! $post_id ) {
            return false;
        }
        return get_post_meta( $post_id, '_servio_maintenance_enabled', true ) === '1';
    }

    public static function is_express_enabled( int $post_id = 0 ): bool {
        if ( ! $post_id ) {
            return false;
        }
        return get_post_meta( $post_id, '_servio_express_enabled', true ) === '1';
    }

    public static function get_extra_pages_label( int $post_id = 0 ): string {
        $label = $post_id ? get_post_meta( $post_id, '_servio_extra_pages_label', true ) : '';
        return $label ?: __( 'Pages supplémentaires', 'servio' );
    }

    public static function get_maintenance_label( int $post_id = 0 ): string {
        $label = $post_id ? get_post_meta( $post_id, '_servio_maintenance_label', true ) : '';
        return $label ?: __( 'Maintenance mensuelle', 'servio' );
    }

    public static function get_express_label( int $post_id = 0 ): string {
        $label = $post_id ? get_post_meta( $post_id, '_servio_express_label', true ) : '';
        return $label ?: __( 'Livraison express', 'servio' );
    }

    public static function get_post_type(): string {
        return get_option( 'servio_post_type', 'annonce' );
    }

    public static function get_color(): string {
        return get_option( 'servio_color', '#0073aa' );
    }

    public static function get_position(): string {
        return get_option( 'servio_position', 'bottom-right' );
    }
}
