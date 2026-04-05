<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WpServio_Admin {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
    }

    public static function add_menu(): void {
        add_submenu_page(
            'wpservio',
            __( 'WpServio - Réglages', 'wpservio' ),
            __( 'Réglages', 'wpservio' ),
            'manage_options',
            'serviceflow-settings',
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    public static function register_settings(): void {
        // CPT
        register_setting( 'wpservio_settings', 'wpservio_post_type', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'annonce',
        ] );

        // Couleur principale
        register_setting( 'wpservio_settings', 'wpservio_color', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#0073aa',
        ] );

        // Position du bouton
        register_setting( 'wpservio_settings', 'wpservio_position', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'bottom-right',
        ] );

        // Section générale
        add_settings_section(
            'wpservio_main_section',
            __( 'Configuration générale', 'wpservio' ),
            null,
            'serviceflow-settings'
        );

        add_settings_field(
            'wpservio_post_type',
            __( 'Custom Post Type', 'wpservio' ),
            [ __CLASS__, 'render_post_type_field' ],
            'serviceflow-settings',
            'wpservio_main_section'
        );

        // Section apparence
        add_settings_section(
            'wpservio_style_section',
            __( 'Apparence', 'wpservio' ),
            null,
            'serviceflow-settings'
        );

        add_settings_field(
            'wpservio_color',
            __( 'Couleur du chat', 'wpservio' ),
            [ __CLASS__, 'render_color_field' ],
            'serviceflow-settings',
            'wpservio_style_section'
        );

        add_settings_field(
            'wpservio_position',
            __( 'Position du bouton', 'wpservio' ),
            [ __CLASS__, 'render_position_field' ],
            'serviceflow-settings',
            'wpservio_style_section'
        );

    }

    public static function render_post_type_field(): void {
        $current    = get_option( 'wpservio_post_type', 'annonce' );
        $post_types = get_post_types( [ 'public' => true ], 'objects' );

        echo '<select name="wpservio_post_type" id="wpservio_post_type">';
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
        echo '<p class="description">' . esc_html__( 'Sélectionnez le CPT sur lequel activer le chat.', 'wpservio' ) . '</p>';
    }

    public static function render_color_field(): void {
        $color = get_option( 'wpservio_color', '#0073aa' );
        printf(
            '<input type="color" name="wpservio_color" id="wpservio_color" value="%s" />',
            esc_attr( $color )
        );
        echo '<p class="description">' . esc_html__( 'Couleur principale du bouton et du header du chat.', 'wpservio' ) . '</p>';
    }

    public static function render_position_field(): void {
        $current = get_option( 'wpservio_position', 'bottom-right' );

        $positions = [
            'bottom-right' => __( 'Bas droite', 'wpservio' ),
            'bottom-left'  => __( 'Bas gauche', 'wpservio' ),
            'top-right'    => __( 'Haut droite', 'wpservio' ),
            'top-left'     => __( 'Haut gauche', 'wpservio' ),
        ];

        echo '<select name="wpservio_position" id="wpservio_position">';
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
                settings_fields( 'wpservio_settings' );
                do_settings_sections( 'serviceflow-settings' );
                submit_button( __( 'Enregistrer', 'wpservio' ) );
                ?>
            </form>
        </div>
        <?php
    }

    public static function is_extra_pages_enabled( int $post_id = 0 ): bool {
        if ( ! $post_id ) {
            return false;
        }
        return get_post_meta( $post_id, '_wpservio_extra_pages_enabled', true ) === '1';
    }

    public static function is_maintenance_enabled( int $post_id = 0 ): bool {
        if ( ! $post_id ) {
            return false;
        }
        return get_post_meta( $post_id, '_wpservio_maintenance_enabled', true ) === '1';
    }

    public static function is_express_enabled( int $post_id = 0 ): bool {
        if ( ! $post_id ) {
            return false;
        }
        return get_post_meta( $post_id, '_wpservio_express_enabled', true ) === '1';
    }

    public static function get_extra_pages_label( int $post_id = 0 ): string {
        $label = $post_id ? get_post_meta( $post_id, '_wpservio_extra_pages_label', true ) : '';
        return $label ?: __( 'Pages supplémentaires', 'wpservio' );
    }

    public static function get_maintenance_label( int $post_id = 0 ): string {
        $label = $post_id ? get_post_meta( $post_id, '_wpservio_maintenance_label', true ) : '';
        return $label ?: __( 'Maintenance mensuelle', 'wpservio' );
    }

    public static function get_express_label( int $post_id = 0 ): string {
        $label = $post_id ? get_post_meta( $post_id, '_wpservio_express_label', true ) : '';
        return $label ?: __( 'Livraison express', 'wpservio' );
    }

    public static function get_post_type(): string {
        return get_option( 'wpservio_post_type', 'annonce' );
    }

    public static function get_color(): string {
        return get_option( 'wpservio_color', '#0073aa' );
    }

    public static function get_position(): string {
        return get_option( 'wpservio_position', 'bottom-right' );
    }
}
