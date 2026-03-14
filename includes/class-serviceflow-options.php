<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServiceFlow_Options {

    public static function init(): void {
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_box' ] );
        add_action( 'save_post', [ __CLASS__, 'save_meta' ], 10, 2 );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_scripts' ] );
    }

    public static function add_meta_box(): void {
        $cpt = ServiceFlow_Admin::get_post_type();

        add_meta_box(
            'serviceflow_options',
            __( 'Options de service', 'serviceflow' ),
            [ __CLASS__, 'render_meta_box' ],
            $cpt,
            'normal',
            'high'
        );
    }

    /**
     * Compte le nombre de services déjà configurés (avec au moins un pack).
     */
    public static function count_active_services( int $exclude_id = 0 ): int {
        global $wpdb;
        $cpt = ServiceFlow_Admin::get_post_type();
        $sql = $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = %s AND p.post_status IN ('publish','draft')
             AND pm.meta_key = '_serviceflow_packs' AND pm.meta_value != '' AND pm.meta_value != 'a:0:{}'
             AND p.ID != %d",
            $cpt,
            $exclude_id
        );
        return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Query built with wpdb::prepare() above.
    }

    public static function render_meta_box( \WP_Post $post ): void {
        wp_nonce_field( 'serviceflow_options_save', 'serviceflow_options_nonce' );

        $packs   = get_post_meta( $post->ID, '_serviceflow_packs', true );
        $options = get_post_meta( $post->ID, '_serviceflow_options', true );

        // Free = 1 seul service autorisé
        if ( ! serviceflow_is_premium() && empty( $packs ) && self::count_active_services( $post->ID ) >= 1 ) {
            printf(
                '<div style="padding:20px;text-align:center;color:#666"><p>%s</p><a href="%s" class="button button-primary">%s</a></div>',
                esc_html__( 'La version gratuite est limitée à 1 service. Passez à Pro pour des services illimités.', 'serviceflow' ),
                esc_url( admin_url( 'admin.php?page=serviceflow-pricing' ) ),
                esc_html__( 'Passer à Pro', 'serviceflow' )
            );
            return;
        }

        // Migration : ancien format base_offer → packs
        if ( ! is_array( $packs ) || empty( $packs ) ) {
            $base = get_post_meta( $post->ID, '_serviceflow_base_offer', true );
            if ( is_array( $base ) && ! empty( $base['name'] ) ) {
                $packs = [ $base ];
            } else {
                $packs = [];
            }
        }
        if ( ! is_array( $options ) ) {
            $options = [];
        }
        ?>
        <style>
            .serviceflow-meta-section { margin-bottom: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 6px; }
            .serviceflow-meta-section h4 { margin: 0 0 4px; font-size: 14px; }
            .serviceflow-meta-section .serviceflow-section-desc { font-size: 12px; color: #888; margin: 0 0 12px; }
            .serviceflow-meta-row { display: flex; gap: 12px; margin-bottom: 10px; align-items: flex-start; }
            .serviceflow-meta-row label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 4px; color: #555; }
            .serviceflow-meta-row input[type="text"],
            .serviceflow-meta-row input[type="number"],
            .serviceflow-meta-row textarea { width: 100%; }
            .serviceflow-meta-row .serviceflow-field { flex: 1; }
            .serviceflow-meta-row .serviceflow-field-price { flex: 0 0 120px; }
            .serviceflow-pack-item,
            .serviceflow-option-item { padding: 12px; background: #fff; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 8px; position: relative; }
            .serviceflow-pack-remove,
            .serviceflow-option-remove { position: absolute; top: 8px; right: 8px; background: #dc3545; color: #fff; border: none; border-radius: 4px; padding: 4px 10px; cursor: pointer; font-size: 12px; }
            .serviceflow-pack-remove:hover,
            .serviceflow-option-remove:hover { background: #c82333; }
            #serviceflow-add-pack,
            #serviceflow-add-option { margin-top: 8px; }
            .serviceflow-features-section { margin-top: 8px; padding: 8px; background: #f5f5f5; border: 1px solid #e0e0e0; border-radius: 4px; }
            .serviceflow-features-section > label { display: block; font-weight: 600; font-size: 12px; color: #555; margin-bottom: 6px; }
            .serviceflow-feature-item { display: flex; gap: 6px; align-items: center; margin-bottom: 4px; }
            .serviceflow-feature-item input[type="text"] { flex: 1; }
            .serviceflow-feature-rm { background: #dc3545; color: #fff; border: none; border-radius: 3px; padding: 2px 8px; cursor: pointer; font-size: 11px; line-height: 1.6; }
            .serviceflow-feature-rm:hover { background: #c82333; }
            .serviceflow-add-feature { font-size: 12px; cursor: pointer; color: #0073aa; background: none; border: none; padding: 4px 0; }
            .serviceflow-add-feature:hover { text-decoration: underline; }
        </style>

        <!-- Packs -->
        <div class="serviceflow-meta-section">
            <h4><?php esc_html_e( 'Packs', 'serviceflow' ); ?></h4>
            <p class="serviceflow-section-desc"><?php esc_html_e( 'Le client en choisit un seul. Ajoutez au moins un pack.', 'serviceflow' ); ?></p>
            <div id="serviceflow-packs-list">
                <?php foreach ( $packs as $i => $pack ) : ?>
                    <div class="serviceflow-pack-item" data-index="<?php echo $i; ?>">
                        <button type="button" class="serviceflow-pack-remove"><?php esc_html_e( 'Supprimer', 'serviceflow' ); ?></button>
                        <div class="serviceflow-meta-row">
                            <div class="serviceflow-field">
                                <label><?php esc_html_e( 'Nom', 'serviceflow' ); ?></label>
                                <input type="text" name="serviceflow_packs[<?php echo $i; ?>][name]" value="<?php echo esc_attr( $pack['name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Ex: Pack Basique', 'serviceflow' ); ?>" />
                            </div>
                            <div class="serviceflow-field-price">
                                <label><?php esc_html_e( 'Prix (€)', 'serviceflow' ); ?></label>
                                <input type="number" name="serviceflow_packs[<?php echo $i; ?>][price]" value="<?php echo esc_attr( $pack['price'] ?? '' ); ?>" min="0" step="0.01" />
                            </div>
                            <div class="serviceflow-field-price">
                                <label><?php esc_html_e( 'Délai (jours)', 'serviceflow' ); ?></label>
                                <input type="number" name="serviceflow_packs[<?php echo $i; ?>][delay]" value="<?php echo esc_attr( $pack['delay'] ?? '' ); ?>" min="0" step="1" />
                            </div>
                        </div>
                        <div class="serviceflow-meta-row">
                            <div class="serviceflow-field">
                                <label><?php esc_html_e( 'Description (infobulle)', 'serviceflow' ); ?></label>
                                <input type="text" name="serviceflow_packs[<?php echo $i; ?>][description]" value="<?php echo esc_attr( $pack['description'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Texte affiché au survol de l\'icône info', 'serviceflow' ); ?>" />
                            </div>
                        </div>
                        <div class="serviceflow-features-section">
                            <label><?php esc_html_e( 'Caractéristiques du pack', 'serviceflow' ); ?></label>
                            <div class="serviceflow-features-list">
                                <?php
                                $features = $pack['features'] ?? [];
                                if ( is_array( $features ) ) :
                                    foreach ( $features as $fi => $feat ) : ?>
                                        <div class="serviceflow-feature-item">
                                            <input type="text" name="serviceflow_packs[<?php echo $i; ?>][features][]" value="<?php echo esc_attr( $feat ); ?>" placeholder="<?php esc_attr_e( 'Ex: 5 pages incluses', 'serviceflow' ); ?>" />
                                            <button type="button" class="serviceflow-feature-rm">&times;</button>
                                        </div>
                                    <?php endforeach;
                                endif; ?>
                            </div>
                            <button type="button" class="serviceflow-add-feature" data-pack-index="<?php echo $i; ?>">+ <?php esc_html_e( 'Ajouter une caractéristique', 'serviceflow' ); ?></button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="serviceflow-add-pack" class="button"><?php esc_html_e( '+ Ajouter un pack', 'serviceflow' ); ?></button>
        </div>

        <?php
        $extra_pages_on = ServiceFlow_Admin::is_extra_pages_enabled();
        $maintenance_on = ServiceFlow_Admin::is_maintenance_enabled();
        $express_on     = ServiceFlow_Admin::is_express_enabled();

        if ( $extra_pages_on || $maintenance_on || $express_on ) :
            $extra_page_price  = get_post_meta( $post->ID, '_serviceflow_extra_page_price', true );
            $maintenance_price = get_post_meta( $post->ID, '_serviceflow_maintenance_price', true );
            $express_price     = get_post_meta( $post->ID, '_serviceflow_express_price', true );
        ?>
        <div class="serviceflow-meta-section">
            <h4><?php esc_html_e( 'Options avancées', 'serviceflow' ); ?></h4>
            <p class="serviceflow-section-desc"><?php esc_html_e( 'Configurées globalement dans Réglages, prix défini ici par service.', 'serviceflow' ); ?></p>
            <div class="serviceflow-meta-row">
                <?php if ( $extra_pages_on ) : ?>
                <div class="serviceflow-field-price">
                    <label><?php esc_html_e( 'Prix par page supp. (€)', 'serviceflow' ); ?></label>
                    <input type="number" name="serviceflow_extra_page_price" value="<?php echo esc_attr( $extra_page_price ); ?>" min="0" step="0.01" placeholder="0" />
                </div>
                <?php endif; ?>
                <?php if ( $maintenance_on ) : ?>
                <div class="serviceflow-field-price">
                    <label><?php esc_html_e( 'Maintenance / mois (€)', 'serviceflow' ); ?></label>
                    <input type="number" name="serviceflow_maintenance_price" value="<?php echo esc_attr( $maintenance_price ); ?>" min="0" step="0.01" placeholder="0" />
                </div>
                <?php endif; ?>
                <?php if ( $express_on ) : ?>
                <div class="serviceflow-field-price">
                    <label><?php esc_html_e( 'Livraison express (€/jour)', 'serviceflow' ); ?></label>
                    <input type="number" name="serviceflow_express_price" value="<?php echo esc_attr( $express_price ); ?>" min="0" step="0.01" placeholder="0" />
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ( serviceflow_is_premium() && ServiceFlow_Stripe::is_enabled() ) :
            $payment_mode       = get_post_meta( $post->ID, '_serviceflow_payment_mode', true ) ?: ( ServiceFlow_Stripe::get_settings()['default_payment_mode'] ?? 'single' );
            $installments_count = (int) get_post_meta( $post->ID, '_serviceflow_installments_count', true ) ?: 3;
        ?>
        <div class="serviceflow-meta-section">
            <h4><?php esc_html_e( 'Mode de paiement', 'serviceflow' ); ?></h4>
            <div class="serviceflow-meta-row">
                <div class="serviceflow-field">
                    <label><?php esc_html_e( 'Type de paiement', 'serviceflow' ); ?></label>
                    <select name="serviceflow_payment_mode" id="sf-payment-mode-select">
                        <option value="single"       <?php selected( $payment_mode, 'single' ); ?>><?php esc_html_e( 'Paiement unique', 'serviceflow' ); ?></option>
                        <option value="deposit"      <?php selected( $payment_mode, 'deposit' ); ?>><?php esc_html_e( 'Acompte 50% + solde à la livraison', 'serviceflow' ); ?></option>
                        <option value="installments" <?php selected( $payment_mode, 'installments' ); ?>><?php esc_html_e( 'Mensualités (40% initial + N mensualités)', 'serviceflow' ); ?></option>
                        <option value="monthly"      <?php selected( $payment_mode, 'monthly' ); ?>><?php esc_html_e( 'Abonnement mensuel pur (N × tarif mensuel)', 'serviceflow' ); ?></option>
                    </select>
                    <p id="sf-monthly-hint" style="font-size:11px;color:#888;margin:4px 0 0;<?php echo $payment_mode !== 'monthly' ? 'display:none' : ''; ?>"><?php esc_html_e( 'Le prix du pack = tarif mensuel. Le 1er mois est réglé au départ, les suivants sont envoyés manuellement.', 'serviceflow' ); ?></p>
                </div>
                <div class="serviceflow-field-price" id="sf-installments-field" style="<?php echo in_array( $payment_mode, [ 'installments', 'monthly' ], true ) ? '' : 'display:none'; ?>">
                    <label id="sf-installments-label"><?php echo $payment_mode === 'monthly' ? esc_html__( 'Nombre de mois', 'serviceflow' ) : esc_html__( 'Nombre de mensualités', 'serviceflow' ); ?></label>
                    <input type="number" name="serviceflow_installments_count" value="<?php echo esc_attr( $installments_count ); ?>" min="1" max="60" step="1" />
                </div>
            </div>
            <script>
            (function(){
                var sel   = document.getElementById('sf-payment-mode-select');
                var field = document.getElementById('sf-installments-field');
                var lbl   = document.getElementById('sf-installments-label');
                var hint  = document.getElementById('sf-monthly-hint');
                if(sel && field){
                    sel.addEventListener('change', function(){
                        var v = sel.value;
                        field.style.display = (v === 'installments' || v === 'monthly') ? '' : 'none';
                        if(lbl) lbl.textContent = v === 'monthly' ? '<?php echo esc_js( __( 'Nombre de mois', 'serviceflow' ) ); ?>' : '<?php echo esc_js( __( 'Nombre de mensualités', 'serviceflow' ) ); ?>';
                        if(hint) hint.style.display = v === 'monthly' ? '' : 'none';
                    });
                }
            })();
            </script>
        </div>
        <?php endif; ?>

        <!-- Options supplémentaires -->
        <div class="serviceflow-meta-section">
            <h4><?php esc_html_e( 'Options supplémentaires', 'serviceflow' ); ?></h4>
            <p class="serviceflow-section-desc"><?php esc_html_e( 'Cumulables avec le pack choisi.', 'serviceflow' ); ?></p>
            <div id="serviceflow-options-list">
                <?php foreach ( $options as $i => $opt ) : ?>
                    <div class="serviceflow-option-item" data-index="<?php echo $i; ?>">
                        <button type="button" class="serviceflow-option-remove"><?php esc_html_e( 'Supprimer', 'serviceflow' ); ?></button>
                        <div class="serviceflow-meta-row">
                            <div class="serviceflow-field">
                                <label><?php esc_html_e( 'Nom', 'serviceflow' ); ?></label>
                                <input type="text" name="serviceflow_opts[<?php echo $i; ?>][name]" value="<?php echo esc_attr( $opt['name'] ?? '' ); ?>" />
                            </div>
                            <div class="serviceflow-field-price">
                                <label><?php esc_html_e( 'Prix (€)', 'serviceflow' ); ?></label>
                                <input type="number" name="serviceflow_opts[<?php echo $i; ?>][price]" value="<?php echo esc_attr( $opt['price'] ?? '' ); ?>" min="0" step="0.01" />
                            </div>
                            <div class="serviceflow-field-price">
                                <label><?php esc_html_e( 'Délai (jours)', 'serviceflow' ); ?></label>
                                <input type="number" name="serviceflow_opts[<?php echo $i; ?>][delay]" value="<?php echo esc_attr( $opt['delay'] ?? '' ); ?>" min="0" step="1" />
                            </div>
                        </div>
                        <div class="serviceflow-meta-row">
                            <div class="serviceflow-field">
                                <label><?php esc_html_e( 'Description', 'serviceflow' ); ?></label>
                                <input type="text" name="serviceflow_opts[<?php echo $i; ?>][description]" value="<?php echo esc_attr( $opt['description'] ?? '' ); ?>" />
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="serviceflow-add-option" class="button"><?php esc_html_e( '+ Ajouter une option', 'serviceflow' ); ?></button>
        </div>
        <?php
    }

    public static function admin_scripts( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== ServiceFlow_Admin::get_post_type() ) {
            return;
        }

        wp_add_inline_script( 'jquery-core', "
            jQuery(function($){
                /* ── Packs repeater ── */
                var packIdx = $('#serviceflow-packs-list .serviceflow-pack-item').length;

                $('#serviceflow-add-pack').on('click', function(){
                    var html = '<div class=\"serviceflow-pack-item\" data-index=\"'+packIdx+'\">' +
                        '<button type=\"button\" class=\"serviceflow-pack-remove\">" . esc_js( __( 'Supprimer', 'serviceflow' ) ) . "</button>' +
                        '<div class=\"serviceflow-meta-row\">' +
                            '<div class=\"serviceflow-field\"><label>" . esc_js( __( 'Nom', 'serviceflow' ) ) . "</label><input type=\"text\" name=\"serviceflow_packs['+packIdx+'][name]\" placeholder=\"" . esc_js( __( 'Ex: Pack Basique', 'serviceflow' ) ) . "\" /></div>' +
                            '<div class=\"serviceflow-field-price\"><label>" . esc_js( __( 'Prix (€)', 'serviceflow' ) ) . "</label><input type=\"number\" name=\"serviceflow_packs['+packIdx+'][price]\" min=\"0\" step=\"0.01\" /></div>' +
                            '<div class=\"serviceflow-field-price\"><label>" . esc_js( __( 'Délai (jours)', 'serviceflow' ) ) . "</label><input type=\"number\" name=\"serviceflow_packs['+packIdx+'][delay]\" min=\"0\" step=\"1\" /></div>' +
                        '</div>' +
                        '<div class=\"serviceflow-meta-row\">' +
                            '<div class=\"serviceflow-field\"><label>" . esc_js( __( 'Description (infobulle)', 'serviceflow' ) ) . "</label><input type=\"text\" name=\"serviceflow_packs['+packIdx+'][description]\" placeholder=\"" . esc_js( __( "Texte affiché au survol de l'icône info", 'serviceflow' ) ) . "\" /></div>' +
                        '</div>' +
                        '<div class=\"serviceflow-features-section\">' +
                            '<label>" . esc_js( __( 'Caractéristiques du pack', 'serviceflow' ) ) . "</label>' +
                            '<div class=\"serviceflow-features-list\"></div>' +
                            '<button type=\"button\" class=\"serviceflow-add-feature\" data-pack-index=\"'+packIdx+'\">+ " . esc_js( __( 'Ajouter une caractéristique', 'serviceflow' ) ) . "</button>' +
                        '</div>' +
                    '</div>';
                    $('#serviceflow-packs-list').append(html);
                    packIdx++;
                });

                $(document).on('click', '.serviceflow-pack-remove', function(){
                    $(this).closest('.serviceflow-pack-item').remove();
                });

                /* ── Features repeater ── */
                $(document).on('click', '.serviceflow-add-feature', function(){
                    var idx = $(this).closest('.serviceflow-pack-item').data('index');
                    var html = '<div class=\"serviceflow-feature-item\">' +
                        '<input type=\"text\" name=\"serviceflow_packs['+idx+'][features][]\" placeholder=\"" . esc_js( __( 'Ex: 5 pages incluses', 'serviceflow' ) ) . "\" />' +
                        '<button type=\"button\" class=\"serviceflow-feature-rm\">&times;</button>' +
                    '</div>';
                    $(this).siblings('.serviceflow-features-list').append(html);
                });

                $(document).on('click', '.serviceflow-feature-rm', function(){
                    $(this).closest('.serviceflow-feature-item').remove();
                });

                /* ── Options repeater ── */
                var optIdx = $('#serviceflow-options-list .serviceflow-option-item').length;

                $('#serviceflow-add-option').on('click', function(){
                    var html = '<div class=\"serviceflow-option-item\" data-index=\"'+optIdx+'\">' +
                        '<button type=\"button\" class=\"serviceflow-option-remove\">" . esc_js( __( 'Supprimer', 'serviceflow' ) ) . "</button>' +
                        '<div class=\"serviceflow-meta-row\">' +
                            '<div class=\"serviceflow-field\"><label>" . esc_js( __( 'Nom', 'serviceflow' ) ) . "</label><input type=\"text\" name=\"serviceflow_opts['+optIdx+'][name]\" /></div>' +
                            '<div class=\"serviceflow-field-price\"><label>" . esc_js( __( 'Prix (€)', 'serviceflow' ) ) . "</label><input type=\"number\" name=\"serviceflow_opts['+optIdx+'][price]\" min=\"0\" step=\"0.01\" /></div>' +
                            '<div class=\"serviceflow-field-price\"><label>" . esc_js( __( 'Délai (jours)', 'serviceflow' ) ) . "</label><input type=\"number\" name=\"serviceflow_opts['+optIdx+'][delay]\" min=\"0\" step=\"1\" /></div>' +
                        '</div>' +
                        '<div class=\"serviceflow-meta-row\">' +
                            '<div class=\"serviceflow-field\"><label>" . esc_js( __( 'Description', 'serviceflow' ) ) . "</label><input type=\"text\" name=\"serviceflow_opts['+optIdx+'][description]\" /></div>' +
                        '</div>' +
                    '</div>';
                    $('#serviceflow-options-list').append(html);
                    optIdx++;
                });

                $(document).on('click', '.serviceflow-option-remove', function(){
                    $(this).closest('.serviceflow-option-item').remove();
                });
            });
        " );
    }

    public static function save_meta( int $post_id, \WP_Post $post ): void {
        if ( ! isset( $_POST['serviceflow_options_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( wp_unslash( $_POST['serviceflow_options_nonce'] ), 'serviceflow_options_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( $post->post_type !== ServiceFlow_Admin::get_post_type() ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Free = 1 seul service autorisé
        if ( ! serviceflow_is_premium() ) {
            $existing_packs = get_post_meta( $post_id, '_serviceflow_packs', true );
            if ( empty( $existing_packs ) && self::count_active_services( $post_id ) >= 1 ) {
                return;
            }
        }

        // Packs
        $packs_raw = wp_unslash( $_POST['serviceflow_packs'] ?? [] );
        $packs = [];
        if ( is_array( $packs_raw ) ) {
            foreach ( $packs_raw as $pack ) {
                $name = sanitize_text_field( $pack['name'] ?? '' );
                if ( empty( $name ) ) {
                    continue;
                }
                $features = [];
                if ( ! empty( $pack['features'] ) && is_array( $pack['features'] ) ) {
                    $features = array_values( array_filter( array_map( 'sanitize_text_field', $pack['features'] ) ) );
                }
                $packs[] = [
                    'name'        => $name,
                    'price'       => floatval( $pack['price'] ?? 0 ),
                    'description' => sanitize_text_field( $pack['description'] ?? '' ),
                    'delay'       => absint( $pack['delay'] ?? 0 ),
                    'features'    => $features,
                ];
            }
        }
        update_post_meta( $post_id, '_serviceflow_packs', $packs );

        // Options supplémentaires
        $opts_raw = wp_unslash( $_POST['serviceflow_opts'] ?? [] );
        $opts = [];
        if ( is_array( $opts_raw ) ) {
            foreach ( $opts_raw as $opt ) {
                $name = sanitize_text_field( $opt['name'] ?? '' );
                if ( empty( $name ) ) {
                    continue;
                }
                $opts[] = [
                    'name'        => $name,
                    'price'       => floatval( $opt['price'] ?? 0 ),
                    'description' => sanitize_text_field( $opt['description'] ?? '' ),
                    'delay'       => absint( $opt['delay'] ?? 0 ),
                ];
            }
        }
        update_post_meta( $post_id, '_serviceflow_options', $opts );

        // Options avancées
        if ( isset( $_POST['serviceflow_extra_page_price'] ) ) {
            update_post_meta( $post_id, '_serviceflow_extra_page_price', floatval( wp_unslash( $_POST['serviceflow_extra_page_price'] ) ) );
        }
        if ( isset( $_POST['serviceflow_maintenance_price'] ) ) {
            update_post_meta( $post_id, '_serviceflow_maintenance_price', floatval( wp_unslash( $_POST['serviceflow_maintenance_price'] ) ) );
        }
        if ( isset( $_POST['serviceflow_express_price'] ) ) {
            update_post_meta( $post_id, '_serviceflow_express_price', floatval( wp_unslash( $_POST['serviceflow_express_price'] ) ) );
        }

        // Mode de paiement
        if ( isset( $_POST['serviceflow_payment_mode'] ) ) {
            $mode = sanitize_text_field( wp_unslash( $_POST['serviceflow_payment_mode'] ) );
            if ( in_array( $mode, [ 'single', 'deposit', 'installments', 'monthly' ], true ) ) {
                update_post_meta( $post_id, '_serviceflow_payment_mode', $mode );
            }
        }
        if ( isset( $_POST['serviceflow_installments_count'] ) ) {
            update_post_meta( $post_id, '_serviceflow_installments_count', absint( wp_unslash( $_POST['serviceflow_installments_count'] ) ) );
        }
    }

    public static function get_payment_mode( int $post_id ): string {
        if ( ! serviceflow_is_premium() ) {
            return 'single';
        }
        $meta = get_post_meta( $post_id, '_serviceflow_payment_mode', true );
        if ( in_array( $meta, [ 'single', 'deposit', 'installments', 'monthly' ], true ) ) {
            return $meta;
        }
        return ServiceFlow_Stripe::get_settings()['default_payment_mode'] ?? 'single';
    }

    public static function get_installments_count( int $post_id ): int {
        return max( 1, (int) get_post_meta( $post_id, '_serviceflow_installments_count', true ) ?: 3 );
    }

    /**
     * Récupère les packs d'un post (avec migration depuis l'ancien format).
     */
    public static function get_packs( int $post_id ): array {
        $packs = get_post_meta( $post_id, '_serviceflow_packs', true );
        if ( is_array( $packs ) && ! empty( $packs ) ) {
            return $packs;
        }

        // Migration : ancien format base_offer → pack unique
        $base = get_post_meta( $post_id, '_serviceflow_base_offer', true );
        if ( is_array( $base ) && ! empty( $base['name'] ) ) {
            return [ $base ];
        }

        return [];
    }

    /**
     * Récupère les options supplémentaires d'un post.
     */
    public static function get_options( int $post_id ): array {
        $opts = get_post_meta( $post_id, '_serviceflow_options', true );
        return is_array( $opts ) ? $opts : [];
    }
}
