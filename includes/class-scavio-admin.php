<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Scavio_Admin {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
    }

    public static function add_menu(): void {
        add_submenu_page(
            'scavio',
            __( 'Scavio - Réglages', 'scavio' ),
            __( 'Réglages', 'scavio' ),
            'manage_options',
            'serviceflow-settings',
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    public static function register_settings(): void {
        // CPT
        register_setting( 'scavio_settings', 'scavio_post_type', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'annonce',
        ] );

        // Couleur principale
        register_setting( 'scavio_settings', 'scavio_color', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#0073aa',
        ] );

        // Position du bouton
        register_setting( 'scavio_settings', 'scavio_position', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'bottom-right',
        ] );

        // Section générale
        add_settings_section(
            'scavio_main_section',
            __( 'Configuration générale', 'scavio' ),
            null,
            'serviceflow-settings'
        );

        add_settings_field(
            'scavio_post_type',
            __( 'Custom Post Type', 'scavio' ),
            [ __CLASS__, 'render_post_type_field' ],
            'serviceflow-settings',
            'scavio_main_section'
        );

        // Section apparence
        add_settings_section(
            'scavio_style_section',
            __( 'Apparence', 'scavio' ),
            null,
            'serviceflow-settings'
        );

        add_settings_field(
            'scavio_color',
            __( 'Couleur du chat', 'scavio' ),
            [ __CLASS__, 'render_color_field' ],
            'serviceflow-settings',
            'scavio_style_section'
        );

        add_settings_field(
            'scavio_position',
            __( 'Position du bouton', 'scavio' ),
            [ __CLASS__, 'render_position_field' ],
            'serviceflow-settings',
            'scavio_style_section'
        );

    }

    public static function render_post_type_field(): void {
        $current    = get_option( 'scavio_post_type', 'annonce' );
        $post_types = get_post_types( [ 'public' => true ], 'objects' );

        echo '<select name="scavio_post_type" id="scavio_post_type">';
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
        echo '<p class="description">' . esc_html__( 'Sélectionnez le CPT sur lequel activer le chat.', 'scavio' ) . '</p>';
    }

    public static function render_color_field(): void {
        $color = get_option( 'scavio_color', '#0073aa' );
        printf(
            '<input type="color" name="scavio_color" id="scavio_color" value="%s" />',
            esc_attr( $color )
        );
        echo '<p class="description">' . esc_html__( 'Couleur principale du bouton et du header du chat.', 'scavio' ) . '</p>';
    }

    public static function render_position_field(): void {
        $current = get_option( 'scavio_position', 'bottom-right' );

        $positions = [
            'bottom-right' => __( 'Bas droite', 'scavio' ),
            'bottom-left'  => __( 'Bas gauche', 'scavio' ),
            'top-right'    => __( 'Haut droite', 'scavio' ),
            'top-left'     => __( 'Haut gauche', 'scavio' ),
        ];

        echo '<select name="scavio_position" id="scavio_position">';
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
                settings_fields( 'scavio_settings' );
                do_settings_sections( 'serviceflow-settings' );
                submit_button( __( 'Enregistrer', 'scavio' ) );
                ?>
            </form>
        </div>
        <?php
    }

    public static function is_extra_pages_enabled( int $post_id = 0 ): bool {
        if ( ! $post_id ) {
            return false;
        }
        return get_post_meta( $post_id, '_scavio_extra_pages_enabled', true ) === '1';
    }

    public static function is_maintenance_enabled( int $post_id = 0 ): bool {
        if ( ! $post_id ) {
            return false;
        }
        return get_post_meta( $post_id, '_scavio_maintenance_enabled', true ) === '1';
    }

    public static function is_express_enabled( int $post_id = 0 ): bool {
        if ( ! $post_id ) {
            return false;
        }
        return get_post_meta( $post_id, '_scavio_express_enabled', true ) === '1';
    }

    public static function get_extra_pages_label( int $post_id = 0 ): string {
        $label = $post_id ? get_post_meta( $post_id, '_scavio_extra_pages_label', true ) : '';
        return $label ?: __( 'Pages supplémentaires', 'scavio' );
    }

    public static function get_maintenance_label( int $post_id = 0 ): string {
        $label = $post_id ? get_post_meta( $post_id, '_scavio_maintenance_label', true ) : '';
        return $label ?: __( 'Maintenance mensuelle', 'scavio' );
    }

    public static function get_express_label( int $post_id = 0 ): string {
        $label = $post_id ? get_post_meta( $post_id, '_scavio_express_label', true ) : '';
        return $label ?: __( 'Livraison express', 'scavio' );
    }

    public static function get_post_type(): string {
        return get_option( 'scavio_post_type', 'annonce' );
    }

    public static function get_color(): string {
        return get_option( 'scavio_color', '#0073aa' );
    }

    public static function get_position(): string {
        return get_option( 'scavio_position', 'bottom-right' );
    }
}
