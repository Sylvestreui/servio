<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Servio_Elementor_Widgets {

    public static function init(): void {
        add_action( 'elementor/elements/categories_registered', [ __CLASS__, 'add_category' ] );
        add_action( 'elementor/widgets/register', [ __CLASS__, 'register_widgets' ] );
    }

    public static function add_category( $elements_manager ): void {
        $elements_manager->add_category( 'servio', [
            'title' => 'Servio',
            'icon'  => 'eicon-plug',
        ] );
    }

    public static function register_widgets( $widgets_manager ): void {
        $widgets_manager->register( new Servio_Widget_Pack() );
        $widgets_manager->register( new Servio_Widget_Option() );
        $widgets_manager->register( new Servio_Widget_Advanced_Price() );
    }
}

/* ================================================================
 *  BASE WIDGET
 * ================================================================ */

abstract class Servio_Widget_Base extends \Elementor\Widget_Base {

    public function get_categories(): array {
        return [ 'servio' ];
    }

    protected function get_post_id(): int {
        return get_the_ID() ?: get_queried_object_id();
    }

    protected function register_html_tag_control(): void {
        $this->add_control( 'html_tag', [
            'label'   => __( 'Balise HTML', 'servio' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'span',
            'options' => [
                'span' => 'span',
                'p'    => 'p',
                'div'  => 'div',
                'h1'   => 'h1',
                'h2'   => 'h2',
                'h3'   => 'h3',
                'h4'   => 'h4',
                'h5'   => 'h5',
                'h6'   => 'h6',
            ],
        ] );
    }

    protected function register_style_section(): void {
        $this->start_controls_section( 'section_style', [
            'label' => __( 'Style', 'servio' ),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_control( 'text_color', [
            'label'     => __( 'Couleur', 'servio' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .sf-widget-text' => 'color: {{VALUE}};' ],
        ] );

        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [ 'name' => 'typography', 'selector' => '{{WRAPPER}} .sf-widget-text' ]
        );

        $this->add_responsive_control( 'text_align', [
            'label'     => __( 'Alignement', 'servio' ),
            'type'      => \Elementor\Controls_Manager::CHOOSE,
            'options'   => [
                'left'   => [ 'title' => __( 'Gauche', 'servio' ),  'icon' => 'eicon-text-align-left' ],
                'center' => [ 'title' => __( 'Centre', 'servio' ),  'icon' => 'eicon-text-align-center' ],
                'right'  => [ 'title' => __( 'Droite', 'servio' ),  'icon' => 'eicon-text-align-right' ],
            ],
            'selectors' => [ '{{WRAPPER}} .sf-widget-text' => 'text-align: {{VALUE}};' ],
        ] );

        $this->end_controls_section();
    }

    protected function render_text( string $content ): void {
        $tag = $this->get_settings_for_display( 'html_tag' ) ?: 'span';
        $allowed = [ 'span', 'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ];
        $tag = in_array( $tag, $allowed, true ) ? $tag : 'span';
        $safe_tag = esc_attr( $tag );
        echo '<' . $safe_tag . ' class="sf-widget-text">' . wp_kses_post( $content ) . '</' . $safe_tag . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $tag is validated against an allowlist above; $content is processed by Elementor's get_settings_for_display().
    }
}

/* ================================================================
 *  WIDGET PACK
 * ================================================================ */

class Servio_Widget_Pack extends Servio_Widget_Base {

    public function get_name(): string  { return 'sf_pack'; }
    public function get_title(): string { return __( 'SF — Pack', 'servio' ); }
    public function get_icon(): string  { return 'eicon-archive'; }

    protected function register_controls(): void {

        /* ── Contenu ── */
        $this->start_controls_section( 'section_content', [
            'label' => __( 'Contenu', 'servio' ),
        ] );

        $this->add_control( 'field', [
            'label'   => __( 'Champ', 'servio' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'name',
            'options' => [
                'name'          => __( 'Nom', 'servio' ),
                'price'         => __( 'Prix', 'servio' ),
                'starting_price'=> __( 'Prix de départ (min)', 'servio' ),
                'delay'         => __( 'Délai de livraison', 'servio' ),
                'description'   => __( 'Description', 'servio' ),
                'features'      => __( 'Caractéristiques', 'servio' ),
                'count'         => __( 'Nombre de packs', 'servio' ),
            ],
        ] );

        $this->add_control( 'pack_index', [
            'label'     => __( 'N° du pack (0 = premier)', 'servio' ),
            'type'      => \Elementor\Controls_Manager::NUMBER,
            'default'   => 0,
            'min'       => 0,
            'condition' => [ 'field!' => [ 'starting_price', 'count' ] ],
        ] );

        $this->add_control( 'currency', [
            'label'     => __( 'Devise', 'servio' ),
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => '€',
            'condition' => [ 'field' => [ 'price', 'starting_price' ] ],
        ] );

        $this->add_control( 'delay_suffix', [
            'label'     => __( 'Suffixe', 'servio' ),
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => ' jour(s)',
            'condition' => [ 'field' => 'delay' ],
        ] );

        $this->add_control( 'features_format', [
            'label'     => __( 'Format', 'servio' ),
            'type'      => \Elementor\Controls_Manager::SELECT,
            'default'   => 'list',
            'options'   => [
                'list'   => __( 'Liste (ul/li)', 'servio' ),
                'inline' => __( 'En ligne', 'servio' ),
            ],
            'condition' => [ 'field' => 'features' ],
        ] );

        $this->add_control( 'features_separator', [
            'label'     => __( 'Séparateur', 'servio' ),
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => ', ',
            'condition' => [ 'field' => 'features', 'features_format' => 'inline' ],
        ] );

        $this->register_html_tag_control();

        $this->end_controls_section();

        /* ── Style texte ── */
        $this->register_style_section();

        /* ── Style liste (features uniquement) ── */
        $this->start_controls_section( 'section_style_list', [
            'label'     => __( 'Style liste', 'servio' ),
            'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
            'condition' => [ 'field' => 'features' ],
        ] );

        $this->add_control( 'list_color', [
            'label'     => __( 'Couleur texte', 'servio' ),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .sf-features-list li' => 'color: {{VALUE}};' ],
        ] );

        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [ 'name' => 'list_typography', 'selector' => '{{WRAPPER}} .sf-features-list li' ]
        );

        $this->add_responsive_control( 'list_gap', [
            'label'      => __( 'Espacement items', 'servio' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 40 ] ],
            'default'    => [ 'size' => 6, 'unit' => 'px' ],
            'selectors'  => [ '{{WRAPPER}} .sf-features-list li + li' => 'margin-top: {{SIZE}}{{UNIT}};' ],
        ] );

        $this->add_control( 'list_padding', [
            'label'      => __( 'Retrait liste', 'servio' ),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 40 ] ],
            'default'    => [ 'size' => 20, 'unit' => 'px' ],
            'selectors'  => [ '{{WRAPPER}} .sf-features-list' => 'padding-left: {{SIZE}}{{UNIT}};' ],
        ] );

        $this->end_controls_section();
    }

    protected function render(): void {
        $s       = $this->get_settings_for_display();
        $packs   = Servio_Options::get_packs( $this->get_post_id() ) ?: [];
        $idx     = (int) ( $s['pack_index'] ?? 0 );
        $field   = $s['field'] ?? 'name';
        $currency = esc_html( $s['currency'] ?? '€' );

        switch ( $field ) {
            case 'name':
                $this->render_text( esc_html( $packs[ $idx ]['name'] ?? '' ) );
                break;

            case 'price':
                $price = (float) ( $packs[ $idx ]['price'] ?? 0 );
                $this->render_text( esc_html( number_format( $price, 2, ',', ' ' ) . ' ' . $currency ) );
                break;

            case 'starting_price':
                if ( empty( $packs ) ) break;
                $prices = array_map( fn( $p ) => (float) ( $p['price'] ?? 0 ), $packs );
                $this->render_text( esc_html( number_format( min( $prices ), 2, ',', ' ' ) . ' ' . $currency ) );
                break;

            case 'delay':
                $delay  = $packs[ $idx ]['delay'] ?? 0;
                $suffix = $s['delay_suffix'] ?? ' jour(s)';
                $this->render_text( esc_html( $delay . $suffix ) );
                break;

            case 'description':
                $this->render_text( esc_html( $packs[ $idx ]['description'] ?? '' ) );
                break;

            case 'features':
                $features = $packs[ $idx ]['features'] ?? [];
                if ( empty( $features ) || ! is_array( $features ) ) break;
                if ( ( $s['features_format'] ?? 'list' ) === 'list' ) {
                    echo '<ul class="sf-features-list">';
                    foreach ( $features as $f ) {
                        echo '<li>' . esc_html( $f ) . '</li>';
                    }
                    echo '</ul>';
                } else {
                    $sep = $s['features_separator'] ?? ', ';
                    $this->render_text( esc_html( implode( $sep, $features ) ) );
                }
                break;

            case 'count':
                $this->render_text( esc_html( (string) count( $packs ) ) );
                break;
        }
    }
}

/* ================================================================
 *  WIDGET OPTION
 * ================================================================ */

class Servio_Widget_Option extends Servio_Widget_Base {

    public function get_name(): string  { return 'sf_option'; }
    public function get_title(): string { return __( 'SF — Option', 'servio' ); }
    public function get_icon(): string  { return 'eicon-plus-circle'; }

    protected function register_controls(): void {

        $this->start_controls_section( 'section_content', [
            'label' => __( 'Contenu', 'servio' ),
        ] );

        $this->add_control( 'field', [
            'label'   => __( 'Champ', 'servio' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'name',
            'options' => [
                'name'        => __( 'Nom', 'servio' ),
                'price'       => __( 'Prix', 'servio' ),
                'delay'       => __( 'Délai', 'servio' ),
                'description' => __( 'Description', 'servio' ),
                'count'       => __( 'Nombre d\'options', 'servio' ),
            ],
        ] );

        $this->add_control( 'option_index', [
            'label'     => __( 'N° de l\'option (0 = première)', 'servio' ),
            'type'      => \Elementor\Controls_Manager::NUMBER,
            'default'   => 0,
            'min'       => 0,
            'condition' => [ 'field!' => 'count' ],
        ] );

        $this->add_control( 'currency', [
            'label'     => __( 'Devise', 'servio' ),
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => '€',
            'condition' => [ 'field' => 'price' ],
        ] );

        $this->add_control( 'delay_suffix', [
            'label'     => __( 'Suffixe', 'servio' ),
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => ' jour(s)',
            'condition' => [ 'field' => 'delay' ],
        ] );

        $this->register_html_tag_control();

        $this->end_controls_section();

        $this->register_style_section();
    }

    protected function render(): void {
        $s        = $this->get_settings_for_display();
        $opts     = Servio_Options::get_options( $this->get_post_id() ) ?: [];
        $idx      = (int) ( $s['option_index'] ?? 0 );
        $field    = $s['field'] ?? 'name';
        $currency = esc_html( $s['currency'] ?? '€' );

        switch ( $field ) {
            case 'name':
                $this->render_text( esc_html( $opts[ $idx ]['name'] ?? '' ) );
                break;

            case 'price':
                $price = (float) ( $opts[ $idx ]['price'] ?? 0 );
                $this->render_text( esc_html( number_format( $price, 2, ',', ' ' ) . ' ' . $currency ) );
                break;

            case 'delay':
                $delay  = $opts[ $idx ]['delay'] ?? 0;
                $suffix = $s['delay_suffix'] ?? ' jour(s)';
                $this->render_text( esc_html( $delay . $suffix ) );
                break;

            case 'description':
                $this->render_text( esc_html( $opts[ $idx ]['description'] ?? '' ) );
                break;

            case 'count':
                $this->render_text( esc_html( (string) count( $opts ) ) );
                break;
        }
    }
}

/* ================================================================
 *  WIDGET PRIX AVANCÉS (Premium)
 * ================================================================ */

class Servio_Widget_Advanced_Price extends Servio_Widget_Base {

    public function get_name(): string  { return 'sf_advanced_price'; }
    public function get_title(): string { return __( 'SF — Prix avancé', 'servio' ); }
    public function get_icon(): string  { return 'eicon-price-table'; }

    protected function register_controls(): void {

        $this->start_controls_section( 'section_content', [
            'label' => __( 'Contenu', 'servio' ),
        ] );

        $this->add_control( 'field', [
            'label'   => __( 'Champ', 'servio' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'extra_page',
            'options' => [
                'extra_page'  => __( 'Page supplémentaire', 'servio' ),
                'maintenance' => __( 'Maintenance mensuelle', 'servio' ),
                'express'     => __( 'Livraison express (par jour)', 'servio' ),
            ],
        ] );

        $this->add_control( 'currency', [
            'label'   => __( 'Devise', 'servio' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '€',
        ] );

        $this->register_html_tag_control();

        $this->end_controls_section();

        $this->register_style_section();
    }

    protected function render(): void {
        $s        = $this->get_settings_for_display();
        $post_id  = $this->get_post_id();
        $currency = esc_html( $s['currency'] ?? '€' );

        $meta_keys = [
            'extra_page'  => '_servio_extra_page_price',
            'maintenance' => '_servio_maintenance_price',
            'express'     => '_servio_express_price',
        ];

        $key   = $meta_keys[ $s['field'] ?? 'extra_page' ] ?? '_servio_extra_page_price';
        $price = (float) get_post_meta( $post_id, $key, true );

        $this->render_text( esc_html( number_format( $price, 2, ',', ' ' ) . ' ' . $currency ) );
    }
}
