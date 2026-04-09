<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Scavio_Elementor {

    public static function init(): void {
        add_action( 'elementor/dynamic_tags/register', [ __CLASS__, 'register_dynamic_tags' ] );
    }

    public static function register_dynamic_tags( $dynamic_tags_manager ): void {
        // Groupe Scavio
        if ( method_exists( $dynamic_tags_manager, 'register_group' ) ) {
            $dynamic_tags_manager->register_group( 'scavio', [
                'title' => 'Scavio',
            ] );
        }

        // Tags individuels
        $dynamic_tags_manager->register( new Scavio_Tag_Pack_Name() );
        $dynamic_tags_manager->register( new Scavio_Tag_Pack_Price() );
        $dynamic_tags_manager->register( new Scavio_Tag_Pack_Starting_Price() );
        $dynamic_tags_manager->register( new Scavio_Tag_Pack_Delay() );
        $dynamic_tags_manager->register( new Scavio_Tag_Pack_Description() );
        $dynamic_tags_manager->register( new Scavio_Tag_Pack_Features() );
        $dynamic_tags_manager->register( new Scavio_Tag_Pack_Count() );
        $dynamic_tags_manager->register( new Scavio_Tag_Option_Name() );
        $dynamic_tags_manager->register( new Scavio_Tag_Option_Price() );
        $dynamic_tags_manager->register( new Scavio_Tag_Option_Delay() );
        $dynamic_tags_manager->register( new Scavio_Tag_Option_Description() );
        $dynamic_tags_manager->register( new Scavio_Tag_Option_Count() );
        $dynamic_tags_manager->register( new Scavio_Tag_Extra_Page_Price() );
        $dynamic_tags_manager->register( new Scavio_Tag_Maintenance_Price() );
        $dynamic_tags_manager->register( new Scavio_Tag_Express_Price() );
    }
}

/* ================================================================
 *  BASE TAG
 * ================================================================ */

abstract class Scavio_Tag_Base extends \Elementor\Core\DynamicTags\Tag {

    public function get_group(): string {
        return 'scavio';
    }

    public function get_categories(): array {
        return [ 'text' ];
    }

    protected function get_post_id(): int {
        return get_the_ID() ?: get_queried_object_id();
    }

    protected function get_packs(): array {
        return Scavio_Options::get_packs( $this->get_post_id() ) ?: [];
    }

    protected function get_options_list(): array {
        return Scavio_Options::get_options( $this->get_post_id() ) ?: [];
    }

    protected function register_pack_index_control(): void {
        $this->add_control( 'pack_index', [
            'label'   => __( 'N° du pack (0 = premier)', 'scavio' ),
            'type'    => \Elementor\Controls_Manager::NUMBER,
            'default' => 0,
            'min'     => 0,
        ] );
    }

    protected function register_option_index_control(): void {
        $this->add_control( 'option_index', [
            'label'   => __( 'N° de l\'option (0 = première)', 'scavio' ),
            'type'    => \Elementor\Controls_Manager::NUMBER,
            'default' => 0,
            'min'     => 0,
        ] );
    }

    protected function register_separator_control(): void {
        $this->add_control( 'separator', [
            'label'   => __( 'Séparateur', 'scavio' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => ', ',
        ] );
    }

    protected function register_currency_control(): void {
        $this->add_control( 'currency', [
            'label'   => __( 'Devise', 'scavio' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '€',
        ] );
    }
}

/* ================================================================
 *  PACK TAGS
 * ================================================================ */

class Scavio_Tag_Pack_Name extends Scavio_Tag_Base {
    public function get_name(): string { return 'scavio_pack_name'; }
    public function get_title(): string { return __( 'Pack — Nom', 'scavio' ); }

    protected function register_controls(): void {
        $this->register_pack_index_control();
    }

    public function render(): void {
        $packs = $this->get_packs();
        $idx   = (int) $this->get_settings( 'pack_index' );
        echo esc_html( $packs[ $idx ]['name'] ?? '' );
    }
}

class Scavio_Tag_Pack_Price extends Scavio_Tag_Base {
    public function get_name(): string { return 'scavio_pack_price'; }
    public function get_title(): string { return __( 'Pack — Prix', 'scavio' ); }

    protected function register_controls(): void {
        $this->register_pack_index_control();
        $this->register_currency_control();
    }

    public function render(): void {
        $packs    = $this->get_packs();
        $idx      = (int) $this->get_settings( 'pack_index' );
        $currency = $this->get_settings( 'currency' );
        $price    = $packs[ $idx ]['price'] ?? 0;
        echo esc_html( number_format( (float) $price, 2, ',', ' ' ) . ' ' . $currency );
    }
}

class Scavio_Tag_Pack_Starting_Price extends Scavio_Tag_Base {
    public function get_name(): string { return 'scavio_pack_starting_price'; }
    public function get_title(): string { return __( 'Pack — Prix de départ', 'scavio' ); }

    protected function register_controls(): void {
        $this->register_currency_control();
    }

    public function render(): void {
        $packs    = $this->get_packs();
        $currency = $this->get_settings( 'currency' );
        if ( empty( $packs ) ) {
            return;
        }
        $prices = array_map( function ( $p ) { return (float) ( $p['price'] ?? 0 ); }, $packs );
        $min    = min( $prices );
        echo esc_html( number_format( $min, 2, ',', ' ' ) . ' ' . $currency );
    }
}

class Scavio_Tag_Pack_Delay extends Scavio_Tag_Base {
    public function get_name(): string { return 'scavio_pack_delay'; }
    public function get_title(): string { return __( 'Pack — Délai', 'scavio' ); }

    protected function register_controls(): void {
        $this->register_pack_index_control();
        $this->add_control( 'suffix', [
            'label'   => __( 'Suffixe', 'scavio' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => ' jour(s)',
        ] );
    }

    public function render(): void {
        $packs  = $this->get_packs();
        $idx    = (int) $this->get_settings( 'pack_index' );
        $suffix = $this->get_settings( 'suffix' );
        $delay  = $packs[ $idx ]['delay'] ?? 0;
        echo esc_html( $delay . $suffix );
    }
}

class Scavio_Tag_Pack_Description extends Scavio_Tag_Base {
    public function get_name(): string { return 'scavio_pack_description'; }
    public function get_title(): string { return __( 'Pack — Description', 'scavio' ); }

    protected function register_controls(): void {
        $this->register_pack_index_control();
    }

    public function render(): void {
        $packs = $this->get_packs();
        $idx   = (int) $this->get_settings( 'pack_index' );
        echo esc_html( $packs[ $idx ]['description'] ?? '' );
    }
}

class Scavio_Tag_Pack_Features extends Scavio_Tag_Base {
    public function get_name(): string { return 'scavio_pack_features'; }
    public function get_title(): string { return __( 'Pack — Features', 'scavio' ); }

    protected function register_controls(): void {
        $this->register_pack_index_control();
        $this->register_separator_control();
        $this->add_control( 'format', [
            'label'   => __( 'Format', 'scavio' ),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'list',
            'options' => [
                'list'   => __( 'Liste (ul)', 'scavio' ),
                'inline' => __( 'En ligne (séparateur)', 'scavio' ),
            ],
        ] );
    }

    public function render(): void {
        $packs    = $this->get_packs();
        $idx      = (int) $this->get_settings( 'pack_index' );
        $format   = $this->get_settings( 'format' );
        $sep      = $this->get_settings( 'separator' );
        $features = $packs[ $idx ]['features'] ?? [];

        if ( empty( $features ) ) {
            return;
        }

        if ( $format === 'list' ) {
            echo '<ul class="serviceflow-features-list">';
            foreach ( $features as $f ) {
                echo '<li>' . esc_html( $f ) . '</li>';
            }
            echo '</ul>';
        } else {
            echo esc_html( implode( $sep, $features ) );
        }
    }
}

class Scavio_Tag_Pack_Count extends Scavio_Tag_Base {
    public function get_name(): string { return 'scavio_pack_count'; }
    public function get_title(): string { return __( 'Pack — Nombre', 'scavio' ); }

    public function render(): void {
        echo esc_html( count( $this->get_packs() ) );
    }
}

/* ================================================================
 *  OPTION TAGS
 * ================================================================ */

class Scavio_Tag_Option_Name extends Scavio_Tag_Base {
    public function get_name(): string { return 'scavio_option_name'; }
    public function get_title(): string { return __( 'Option — Nom', 'scavio' ); }

    protected function register_controls(): void {
        $this->register_option_index_control();
    }

    public function render(): void {
        $opts = $this->get_options_list();
        $idx  = (int) $this->get_settings( 'option_index' );
        echo esc_html( $opts[ $idx ]['name'] ?? '' );
    }
}

class Scavio_Tag_Option_Price extends Scavio_Tag_Base {
    public function get_name(): string { return 'scavio_option_price'; }
    public function get_title(): string { return __( 'Option — Prix', 'scavio' ); }

    protected function register_controls(): void {
        $this->register_option_index_control();
        $this->register_currency_control();
    }

    public function render(): void {
        $opts     = $this->get_options_list();
        $idx      = (int) $this->get_settings( 'option_index' );
        $currency = $this->get_settings( 'currency' );
        $price    = $opts[ $idx ]['price'] ?? 0;
        echo esc_html( number_format( (float) $price, 2, ',', ' ' ) . ' ' . $currency );
    }
}

class Scavio_Tag_Option_Delay extends Scavio_Tag_Base {
    public function get_name(): string { return 'scavio_option_delay'; }
    public function get_title(): string { return __( 'Option — Délai', 'scavio' ); }

    protected function register_controls(): void {
        $this->register_option_index_control();
        $this->add_control( 'suffix', [
            'label'   => __( 'Suffixe', 'scavio' ),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => ' jour(s)',
        ] );
    }

    public function render(): void {
        $opts   = $this->get_options_list();
        $idx    = (int) $this->get_settings( 'option_index' );
        $suffix = $this->get_settings( 'suffix' );
        $delay  = $opts[ $idx ]['delay'] ?? 0;
        echo esc_html( $delay . $suffix );
    }
}

class Scavio_Tag_Option_Description extends Scavio_Tag_Base {
    public function get_name(): string { return 'scavio_option_description'; }
    public function get_title(): string { return __( 'Option — Description', 'scavio' ); }

    protected function register_controls(): void {
        $this->register_option_index_control();
    }

    public function render(): void {
        $opts = $this->get_options_list();
        $idx  = (int) $this->get_settings( 'option_index' );
        echo esc_html( $opts[ $idx ]['description'] ?? '' );
    }
}

class Scavio_Tag_Option_Count extends Scavio_Tag_Base {
    public function get_name(): string { return 'scavio_option_count'; }
    public function get_title(): string { return __( 'Option — Nombre', 'scavio' ); }

    public function render(): void {
        echo esc_html( count( $this->get_options_list() ) );
    }
}

/* ================================================================
 *  PRICING TAGS
 * ================================================================ */

class Scavio_Tag_Extra_Page_Price extends Scavio_Tag_Base {
    public function get_name(): string { return 'scavio_extra_page_price'; }
    public function get_title(): string { return __( 'Prix — Page supplémentaire', 'scavio' ); }

    protected function register_controls(): void {
        $this->register_currency_control();
    }

    public function render(): void {
        $post_id  = $this->get_post_id();
        $currency = $this->get_settings( 'currency' );
        $price    = (float) get_post_meta( $post_id, '_scavio_extra_page_price', true );
        echo esc_html( number_format( $price, 2, ',', ' ' ) . ' ' . $currency );
    }
}

class Scavio_Tag_Maintenance_Price extends Scavio_Tag_Base {
    public function get_name(): string { return 'scavio_maintenance_price'; }
    public function get_title(): string { return __( 'Prix — Maintenance mensuelle', 'scavio' ); }

    protected function register_controls(): void {
        $this->register_currency_control();
    }

    public function render(): void {
        $post_id  = $this->get_post_id();
        $currency = $this->get_settings( 'currency' );
        $price    = (float) get_post_meta( $post_id, '_scavio_maintenance_price', true );
        echo esc_html( number_format( $price, 2, ',', ' ' ) . ' ' . $currency );
    }
}

class Scavio_Tag_Express_Price extends Scavio_Tag_Base {
    public function get_name(): string { return 'scavio_express_price'; }
    public function get_title(): string { return __( 'Prix — Livraison express', 'scavio' ); }

    protected function register_controls(): void {
        $this->register_currency_control();
    }

    public function render(): void {
        $post_id  = $this->get_post_id();
        $currency = $this->get_settings( 'currency' );
        $price    = (float) get_post_meta( $post_id, '_scavio_express_price', true );
        echo esc_html( number_format( $price, 2, ',', ' ' ) . ' ' . $currency );
    }
}
