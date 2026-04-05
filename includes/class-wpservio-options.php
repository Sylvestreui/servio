<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WpServio_Options {

    public static function init(): void {
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_box' ] );
        add_action( 'save_post', [ __CLASS__, 'save_meta' ], 10, 2 );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_scripts' ] );
    }

    public static function add_meta_box(): void {
        $cpt = WpServio_Admin::get_post_type();

        add_meta_box(
            'wpservio_options',
            __( 'Options de service', 'wpservio' ),
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
        $cpt = WpServio_Admin::get_post_type();
        $sql = $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = %s AND p.post_status IN ('publish','draft')
             AND pm.meta_key = '_wpservio_packs' AND pm.meta_value != '' AND pm.meta_value != 'a:0:{}'
             AND p.ID != %d",
            $cpt,
            $exclude_id
        );
        return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Query built with wpdb::prepare() above.
    }

    public static function render_meta_box( \WP_Post $post ): void {
        wp_nonce_field( 'wpservio_options_save', 'wpservio_options_nonce' );

        $packs   = get_post_meta( $post->ID, '_wpservio_packs', true );
        $options = get_post_meta( $post->ID, '_wpservio_options', true );

        // Free = 1 seul service autorisé
        if ( ! wpservio_is_premium() && empty( $packs ) && self::count_active_services( $post->ID ) >= 1 ) {
            printf(
                '<div style="padding:20px;text-align:center;color:#666"><p>%s</p><a href="%s" class="button button-primary">%s</a></div>',
                esc_html__( 'La version gratuite est limitée à 1 service. Passez à Pro pour des services illimités.', 'wpservio' ),
                esc_url( admin_url( 'admin.php?page=serviceflow-pricing' ) ),
                esc_html__( 'Passer à Pro', 'wpservio' )
            );
            return;
        }

        // Migration : ancien format base_offer → packs
        if ( ! is_array( $packs ) || empty( $packs ) ) {
            $base = get_post_meta( $post->ID, '_wpservio_base_offer', true );
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
        <!-- Packs -->
        <div class="serviceflow-meta-section">
            <h4><?php esc_html_e( 'Packs', 'wpservio' ); ?></h4>
            <p class="serviceflow-section-desc"><?php esc_html_e( 'Le client en choisit un seul. Ajoutez au moins un pack.', 'wpservio' ); ?></p>
            <div id="serviceflow-packs-list">
                <?php foreach ( $packs as $i => $pack ) : ?>
                    <div class="serviceflow-pack-item" data-index="<?php echo absint( $i ); ?>">
                        <button type="button" class="serviceflow-pack-remove"><?php esc_html_e( 'Supprimer', 'wpservio' ); ?></button>
                        <div class="serviceflow-meta-row">
                            <div class="serviceflow-field">
                                <label><?php esc_html_e( 'Nom', 'wpservio' ); ?></label>
                                <input type="text" name="wpservio_packs[<?php echo absint( $i ); ?>][name]" value="<?php echo esc_attr( $pack['name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Ex: Pack Basique', 'wpservio' ); ?>" />
                            </div>
                            <div class="serviceflow-field-price">
                                <label><?php esc_html_e( 'Prix (€)', 'wpservio' ); ?></label>
                                <input type="number" name="wpservio_packs[<?php echo absint( $i ); ?>][price]" value="<?php echo esc_attr( $pack['price'] ?? '' ); ?>" min="0" step="0.01" />
                            </div>
                            <div class="serviceflow-field-price">
                                <label><?php esc_html_e( 'Délai (jours)', 'wpservio' ); ?></label>
                                <input type="number" name="wpservio_packs[<?php echo absint( $i ); ?>][delay]" value="<?php echo esc_attr( $pack['delay'] ?? '' ); ?>" min="0" step="1" />
                            </div>
                        </div>
                        <div class="serviceflow-meta-row">
                            <div class="serviceflow-field">
                                <label><?php esc_html_e( 'Description (infobulle)', 'wpservio' ); ?></label>
                                <input type="text" name="wpservio_packs[<?php echo absint( $i ); ?>][description]" value="<?php echo esc_attr( $pack['description'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Texte affiché au survol de l\'icône info', 'wpservio' ); ?>" />
                            </div>
                        </div>
                        <div class="serviceflow-features-section">
                            <label><?php esc_html_e( 'Caractéristiques du pack', 'wpservio' ); ?></label>
                            <div class="serviceflow-features-list">
                                <?php
                                $features = $pack['features'] ?? [];
                                if ( is_array( $features ) ) :
                                    foreach ( $features as $fi => $feat ) : ?>
                                        <div class="serviceflow-feature-item">
                                            <input type="text" name="wpservio_packs[<?php echo absint( $i ); ?>][features][]" value="<?php echo esc_attr( $feat ); ?>" placeholder="<?php esc_attr_e( 'Ex: 5 pages incluses', 'wpservio' ); ?>" />
                                            <button type="button" class="serviceflow-feature-rm">&times;</button>
                                        </div>
                                    <?php endforeach;
                                endif; ?>
                            </div>
                            <button type="button" class="serviceflow-add-feature" data-pack-index="<?php echo absint( $i ); ?>">+ <?php esc_html_e( 'Ajouter une caractéristique', 'wpservio' ); ?></button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="serviceflow-add-pack" class="button"><?php esc_html_e( '+ Ajouter un pack', 'wpservio' ); ?></button>
        </div>

        <?php if ( wpservio_is_premium() ) :
            $adv_opts_raw = get_post_meta( $post->ID, '_wpservio_advanced_options', true );
            $adv_opts     = $adv_opts_raw ? json_decode( $adv_opts_raw, true ) : [];
            if ( ! is_array( $adv_opts ) ) {
                $adv_opts = [];
            }
            $mode_labels = [
                'unit'    => __( 'Par unité', 'wpservio' ),
                'monthly' => __( 'Par mois', 'wpservio' ),
                'fixed'   => __( 'Forfait', 'wpservio' ),
                'daily'   => __( 'Express (jours)', 'wpservio' ),
            ];
        ?>
        <div class="serviceflow-meta-section">
            <h4><?php esc_html_e( 'Options avancées', 'wpservio' ); ?></h4>
            <p class="serviceflow-section-desc"><?php esc_html_e( 'Ajoutez des options avec tarification flexible. Chaque option est affichée sur le widget de commande.', 'wpservio' ); ?></p>

            <div id="sf-adv-opts-list">
                <?php foreach ( $adv_opts as $i => $opt ) :
                    $opt_label      = sanitize_text_field( $opt['label'] ?? '' );
                    $opt_price      = floatval( $opt['price'] ?? 0 );
                    $opt_mode       = in_array( $opt['mode'] ?? '', [ 'unit', 'monthly', 'fixed', 'daily' ], true ) ? $opt['mode'] : 'unit';
                    $opt_unit_label = sanitize_text_field( $opt['unit_label'] ?? '' );
                ?>
                <div class="sf-adv-opt-row">
                    <div class="sf-adv-opt-handle" style="cursor:grab;color:#aaa;padding:0 6px;font-size:18px;line-height:36px">&#8942;&#8942;</div>
                    <div class="sf-adv-opt-fields" style="flex:1;display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:8px;align-items:end">
                        <div>
                            <label style="font-size:11px;color:#666;display:block;margin-bottom:3px"><?php esc_html_e( 'Libellé', 'wpservio' ); ?></label>
                            <input type="text" name="sf_adv_opt_label[]" value="<?php echo esc_attr( $opt_label ); ?>" placeholder="<?php esc_attr_e( 'Nom de l\'option', 'wpservio' ); ?>" style="width:100%" required />
                        </div>
                        <div>
                            <label style="font-size:11px;color:#666;display:block;margin-bottom:3px"><?php esc_html_e( 'Mode', 'wpservio' ); ?></label>
                            <select name="sf_adv_opt_mode[]" class="sf-adv-opt-mode" style="width:100%">
                                <?php foreach ( $mode_labels as $mval => $mlabel ) : ?>
                                <option value="<?php echo esc_attr( $mval ); ?>" <?php selected( $opt_mode, $mval ); ?>><?php echo esc_html( $mlabel ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sf-adv-opt-unit-wrap" <?php if ( in_array( $opt_mode, [ 'monthly', 'fixed' ], true ) ) : ?>style="visibility:hidden"<?php endif; ?>>
                            <label style="font-size:11px;color:#666;display:block;margin-bottom:3px"><?php esc_html_e( 'Libellé unité', 'wpservio' ); ?></label>
                            <input type="text" name="sf_adv_opt_unit[]" value="<?php echo esc_attr( $opt_unit_label ); ?>" placeholder="<?php esc_attr_e( 'ex. page, heure...', 'wpservio' ); ?>" style="width:100%" />
                        </div>
                        <div>
                            <label style="font-size:11px;color:#666;display:block;margin-bottom:3px"><?php esc_html_e( 'Prix (€)', 'wpservio' ); ?></label>
                            <input type="number" name="sf_adv_opt_price[]" value="<?php echo esc_attr( $opt_price ); ?>" min="0" step="0.01" placeholder="0" style="width:100%" />
                        </div>
                    </div>
                    <button type="button" class="sf-adv-opt-remove button-link" style="color:#a00;margin-left:8px;padding:0 6px;line-height:36px;font-size:18px" title="<?php esc_attr_e( 'Supprimer', 'wpservio' ); ?>">&times;</button>
                </div>
                <?php endforeach; ?>
            </div>

            <template id="sf-adv-opt-tpl">
                <div class="sf-adv-opt-row">
                    <div class="sf-adv-opt-handle" style="cursor:grab;color:#aaa;padding:0 6px;font-size:18px;line-height:36px">&#8942;&#8942;</div>
                    <div class="sf-adv-opt-fields" style="flex:1;display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:8px;align-items:end">
                        <div>
                            <label style="font-size:11px;color:#666;display:block;margin-bottom:3px"><?php esc_html_e( 'Libellé', 'wpservio' ); ?></label>
                            <input type="text" name="sf_adv_opt_label[]" value="" placeholder="<?php esc_attr_e( 'Nom de l\'option', 'wpservio' ); ?>" style="width:100%" required />
                        </div>
                        <div>
                            <label style="font-size:11px;color:#666;display:block;margin-bottom:3px"><?php esc_html_e( 'Mode', 'wpservio' ); ?></label>
                            <select name="sf_adv_opt_mode[]" class="sf-adv-opt-mode" style="width:100%">
                                <option value="unit"><?php esc_html_e( 'Par unité', 'wpservio' ); ?></option>
                                <option value="monthly"><?php esc_html_e( 'Par mois', 'wpservio' ); ?></option>
                                <option value="fixed"><?php esc_html_e( 'Forfait', 'wpservio' ); ?></option>
                                <option value="daily"><?php esc_html_e( 'Express (jours)', 'wpservio' ); ?></option>
                            </select>
                        </div>
                        <div class="sf-adv-opt-unit-wrap">
                            <label style="font-size:11px;color:#666;display:block;margin-bottom:3px"><?php esc_html_e( 'Libellé unité', 'wpservio' ); ?></label>
                            <input type="text" name="sf_adv_opt_unit[]" value="" placeholder="<?php esc_attr_e( 'ex. page, heure...', 'wpservio' ); ?>" style="width:100%" />
                        </div>
                        <div>
                            <label style="font-size:11px;color:#666;display:block;margin-bottom:3px"><?php esc_html_e( 'Prix (€)', 'wpservio' ); ?></label>
                            <input type="number" name="sf_adv_opt_price[]" value="" min="0" step="0.01" placeholder="0" style="width:100%" />
                        </div>
                    </div>
                    <button type="button" class="sf-adv-opt-remove button-link" style="color:#a00;margin-left:8px;padding:0 6px;line-height:36px;font-size:18px" title="<?php esc_attr_e( 'Supprimer', 'wpservio' ); ?>">&times;</button>
                </div>
            </template>

            <button type="button" id="sf-adv-opts-add" class="button" style="margin-top:10px">
                + <?php esc_html_e( 'Ajouter une option', 'wpservio' ); ?>
            </button>
        </div>

        <?php endif; ?>

        <?php if ( wpservio_is_premium() && WpServio_Stripe::is_enabled() ) :
            $payment_mode       = get_post_meta( $post->ID, '_wpservio_payment_mode', true ) ?: ( WpServio_Stripe::get_settings()['default_payment_mode'] ?? 'single' );
            $installments_count = (int) get_post_meta( $post->ID, '_wpservio_installments_count', true ) ?: 3;
        ?>
        <div class="serviceflow-meta-section">
            <h4><?php esc_html_e( 'Mode de paiement', 'wpservio' ); ?></h4>
            <div class="serviceflow-meta-row">
                <div class="serviceflow-field">
                    <label><?php esc_html_e( 'Type de paiement', 'wpservio' ); ?></label>
                    <select name="wpservio_payment_mode" id="sf-payment-mode-select">
                        <option value="single"       <?php selected( $payment_mode, 'single' ); ?>><?php esc_html_e( 'Paiement unique', 'wpservio' ); ?></option>
                        <option value="deposit"      <?php selected( $payment_mode, 'deposit' ); ?>><?php esc_html_e( 'Acompte 50% + solde à la livraison', 'wpservio' ); ?></option>
                        <option value="installments" <?php selected( $payment_mode, 'installments' ); ?>><?php esc_html_e( 'Mensualités (40% initial + N mensualités)', 'wpservio' ); ?></option>
                        <option value="monthly"      <?php selected( $payment_mode, 'monthly' ); ?>><?php esc_html_e( 'Abonnement mensuel pur (N × tarif mensuel)', 'wpservio' ); ?></option>
                    </select>
                    <p id="sf-monthly-hint" style="font-size:11px;color:#888;margin:4px 0 0;<?php echo $payment_mode !== 'monthly' ? 'display:none' : ''; ?>"><?php esc_html_e( 'Le prix du pack = tarif mensuel. Le 1er mois est réglé au départ, les suivants sont envoyés manuellement.', 'wpservio' ); ?></p>
                </div>
                <div class="serviceflow-field-price" id="sf-installments-field" style="<?php echo in_array( $payment_mode, [ 'installments', 'monthly' ], true ) ? '' : 'display:none'; ?>">
                    <label id="sf-installments-label"><?php echo $payment_mode === 'monthly' ? esc_html__( 'Nombre de mois', 'wpservio' ) : esc_html__( 'Nombre de mensualités', 'wpservio' ); ?></label>
                    <input type="number" name="wpservio_installments_count" value="<?php echo esc_attr( $installments_count ); ?>" min="1" max="60" step="1" />
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Options supplémentaires -->
        <div class="serviceflow-meta-section">
            <h4><?php esc_html_e( 'Options supplémentaires', 'wpservio' ); ?></h4>
            <p class="serviceflow-section-desc"><?php esc_html_e( 'Cumulables avec le pack choisi.', 'wpservio' ); ?></p>
            <div id="serviceflow-options-list">
                <?php foreach ( $options as $i => $opt ) : ?>
                    <div class="serviceflow-option-item" data-index="<?php echo absint( $i ); ?>">
                        <button type="button" class="serviceflow-option-remove"><?php esc_html_e( 'Supprimer', 'wpservio' ); ?></button>
                        <div class="serviceflow-meta-row">
                            <div class="serviceflow-field">
                                <label><?php esc_html_e( 'Nom', 'wpservio' ); ?></label>
                                <input type="text" name="wpservio_opts[<?php echo absint( $i ); ?>][name]" value="<?php echo esc_attr( $opt['name'] ?? '' ); ?>" />
                            </div>
                            <div class="serviceflow-field-price">
                                <label><?php esc_html_e( 'Prix (€)', 'wpservio' ); ?></label>
                                <input type="number" name="wpservio_opts[<?php echo absint( $i ); ?>][price]" value="<?php echo esc_attr( $opt['price'] ?? '' ); ?>" min="0" step="0.01" />
                            </div>
                            <div class="serviceflow-field-price">
                                <label><?php esc_html_e( 'Délai (jours)', 'wpservio' ); ?></label>
                                <input type="number" name="wpservio_opts[<?php echo absint( $i ); ?>][delay]" value="<?php echo esc_attr( $opt['delay'] ?? '' ); ?>" min="0" step="1" />
                            </div>
                        </div>
                        <div class="serviceflow-meta-row">
                            <div class="serviceflow-field">
                                <label><?php esc_html_e( 'Description', 'wpservio' ); ?></label>
                                <input type="text" name="wpservio_opts[<?php echo absint( $i ); ?>][description]" value="<?php echo esc_attr( $opt['description'] ?? '' ); ?>" />
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="serviceflow-add-option" class="button"><?php esc_html_e( '+ Ajouter une option', 'wpservio' ); ?></button>
        </div>
        <?php
    }

    public static function admin_scripts( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== WpServio_Admin::get_post_type() ) {
            return;
        }

        wp_enqueue_script( 'jquery-ui-sortable' );

        wp_add_inline_style( 'wp-admin',
            '.serviceflow-meta-section { margin-bottom: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 6px; }' .
            '.serviceflow-meta-section h4 { margin: 0 0 4px; font-size: 14px; }' .
            '.serviceflow-meta-section .serviceflow-section-desc { font-size: 12px; color: #888; margin: 0 0 12px; }' .
            '.serviceflow-meta-row { display: flex; gap: 12px; margin-bottom: 10px; align-items: flex-start; }' .
            '.serviceflow-meta-row label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 4px; color: #555; }' .
            '.serviceflow-meta-row input[type="text"],.serviceflow-meta-row input[type="number"],.serviceflow-meta-row textarea { width: 100%; }' .
            '.serviceflow-meta-row .serviceflow-field { flex: 1; }' .
            '.serviceflow-meta-row .serviceflow-field-price { flex: 0 0 120px; }' .
            '.serviceflow-pack-item,.serviceflow-option-item { padding: 12px; background: #fff; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 8px; position: relative; }' .
            '.serviceflow-pack-remove,.serviceflow-option-remove { position: absolute; top: 8px; right: 8px; background: #dc3545; color: #fff; border: none; border-radius: 4px; padding: 4px 10px; cursor: pointer; font-size: 12px; }' .
            '.serviceflow-pack-remove:hover,.serviceflow-option-remove:hover { background: #c82333; }' .
            '#serviceflow-add-pack,#serviceflow-add-option { margin-top: 8px; }' .
            '.serviceflow-features-section { margin-top: 8px; padding: 8px; background: #f5f5f5; border: 1px solid #e0e0e0; border-radius: 4px; }' .
            '.serviceflow-features-section > label { display: block; font-weight: 600; font-size: 12px; color: #555; margin-bottom: 6px; }' .
            '.serviceflow-feature-item { display: flex; gap: 6px; align-items: center; margin-bottom: 4px; }' .
            '.serviceflow-feature-item input[type="text"] { flex: 1; }' .
            '.serviceflow-feature-rm { background: #dc3545; color: #fff; border: none; border-radius: 3px; padding: 2px 8px; cursor: pointer; font-size: 11px; line-height: 1.6; }' .
            '.serviceflow-feature-rm:hover { background: #c82333; }' .
            '.serviceflow-add-feature { font-size: 12px; cursor: pointer; color: #0073aa; background: none; border: none; padding: 4px 0; }' .
            '.serviceflow-add-feature:hover { text-decoration: underline; }' .
            '.sf-adv-opt-row{display:flex;align-items:center;background:#f9f9f9;border:1px solid #e0e0e0;border-radius:4px;padding:8px;margin-bottom:6px}' .
            '.sf-adv-opt-handle{cursor:grab}.sf-adv-opt-handle:active{cursor:grabbing}' .
            '.sf-adv-opt-placeholder{background:#e8f0fe;border:2px dashed #4a90d9;border-radius:4px;margin-bottom:6px}' .
            '#sf-adv-opts-list:empty::before{content:"' . esc_js( __( 'Aucune option. Cliquez sur Ajouter.', 'wpservio' ) ) . '";color:#999;font-style:italic;font-size:12px;display:block;padding:8px 0}'
        );

        wp_add_inline_script( 'jquery-ui-sortable', '
            jQuery(function($){
                $("#sf-adv-opts-list").sortable({
                    handle: ".sf-adv-opt-handle",
                    axis: "y",
                    tolerance: "pointer",
                    placeholder: "sf-adv-opt-placeholder",
                    forcePlaceholderSize: true,
                    start: function(e,ui){ ui.item.css("opacity","0.6"); },
                    stop:  function(e,ui){ ui.item.css("opacity","1"); }
                });
                function bindRow(row){
                    var modeEl=row.querySelector(".sf-adv-opt-mode");
                    var unitWrap=row.querySelector(".sf-adv-opt-unit-wrap");
                    function toggleUnit(){ var m=modeEl?modeEl.value:"unit"; if(unitWrap) unitWrap.style.visibility=(m==="monthly"||m==="fixed")?"hidden":""; }
                    if(modeEl){ modeEl.addEventListener("change",toggleUnit); toggleUnit(); }
                    var rmBtn=row.querySelector(".sf-adv-opt-remove");
                    if(rmBtn){ rmBtn.addEventListener("click",function(){ row.remove(); }); }
                }
                document.querySelectorAll(".sf-adv-opt-row").forEach(bindRow);
                var addBtn=document.getElementById("sf-adv-opts-add");
                var tpl=document.getElementById("sf-adv-opt-tpl");
                if(addBtn&&tpl){
                    addBtn.addEventListener("click",function(){
                        var clone=tpl.content.cloneNode(true);
                        var list=document.getElementById("sf-adv-opts-list");
                        list.appendChild(clone);
                        var rows=list.querySelectorAll(".sf-adv-opt-row");
                        bindRow(rows[rows.length-1]);
                    });
                }
            });
        ' );

        wp_add_inline_script( 'jquery-core',
            '(function(){
                var sel=document.getElementById("sf-payment-mode-select");
                var field=document.getElementById("sf-installments-field");
                var lbl=document.getElementById("sf-installments-label");
                var hint=document.getElementById("sf-monthly-hint");
                if(sel&&field){
                    sel.addEventListener("change",function(){
                        var v=sel.value;
                        field.style.display=(v==="installments"||v==="monthly")?"":"none";
                        if(lbl) lbl.textContent=v==="monthly"?"' . esc_js( __( 'Nombre de mois', 'wpservio' ) ) . '":"' . esc_js( __( 'Nombre de mensualités', 'wpservio' ) ) . '";
                        if(hint) hint.style.display=v==="monthly"?"":"none";
                    });
                }
            })();',
            'after'
        );

        wp_add_inline_script( 'jquery-core', "
            jQuery(function($){
                /* ── Packs repeater ── */
                var packIdx = $('#serviceflow-packs-list .serviceflow-pack-item').length;

                $('#serviceflow-add-pack').on('click', function(){
                    var html = '<div class=\"serviceflow-pack-item\" data-index=\"'+packIdx+'\">' +
                        '<button type=\"button\" class=\"serviceflow-pack-remove\">" . esc_js( __( 'Supprimer', 'wpservio' ) ) . "</button>' +
                        '<div class=\"serviceflow-meta-row\">' +
                            '<div class=\"serviceflow-field\"><label>" . esc_js( __( 'Nom', 'wpservio' ) ) . "</label><input type=\"text\" name=\"wpservio_packs['+packIdx+'][name]\" placeholder=\"" . esc_js( __( 'Ex: Pack Basique', 'wpservio' ) ) . "\" /></div>' +
                            '<div class=\"serviceflow-field-price\"><label>" . esc_js( __( 'Prix (€)', 'wpservio' ) ) . "</label><input type=\"number\" name=\"wpservio_packs['+packIdx+'][price]\" min=\"0\" step=\"0.01\" /></div>' +
                            '<div class=\"serviceflow-field-price\"><label>" . esc_js( __( 'Délai (jours)', 'wpservio' ) ) . "</label><input type=\"number\" name=\"wpservio_packs['+packIdx+'][delay]\" min=\"0\" step=\"1\" /></div>' +
                        '</div>' +
                        '<div class=\"serviceflow-meta-row\">' +
                            '<div class=\"serviceflow-field\"><label>" . esc_js( __( 'Description (infobulle)', 'wpservio' ) ) . "</label><input type=\"text\" name=\"wpservio_packs['+packIdx+'][description]\" placeholder=\"" . esc_js( __( "Texte affiché au survol de l'icône info", 'wpservio' ) ) . "\" /></div>' +
                        '</div>' +
                        '<div class=\"serviceflow-features-section\">' +
                            '<label>" . esc_js( __( 'Caractéristiques du pack', 'wpservio' ) ) . "</label>' +
                            '<div class=\"serviceflow-features-list\"></div>' +
                            '<button type=\"button\" class=\"serviceflow-add-feature\" data-pack-index=\"'+packIdx+'\">+ " . esc_js( __( 'Ajouter une caractéristique', 'wpservio' ) ) . "</button>' +
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
                        '<input type=\"text\" name=\"wpservio_packs['+idx+'][features][]\" placeholder=\"" . esc_js( __( 'Ex: 5 pages incluses', 'wpservio' ) ) . "\" />' +
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
                        '<button type=\"button\" class=\"serviceflow-option-remove\">" . esc_js( __( 'Supprimer', 'wpservio' ) ) . "</button>' +
                        '<div class=\"serviceflow-meta-row\">' +
                            '<div class=\"serviceflow-field\"><label>" . esc_js( __( 'Nom', 'wpservio' ) ) . "</label><input type=\"text\" name=\"wpservio_opts['+optIdx+'][name]\" /></div>' +
                            '<div class=\"serviceflow-field-price\"><label>" . esc_js( __( 'Prix (€)', 'wpservio' ) ) . "</label><input type=\"number\" name=\"wpservio_opts['+optIdx+'][price]\" min=\"0\" step=\"0.01\" /></div>' +
                            '<div class=\"serviceflow-field-price\"><label>" . esc_js( __( 'Délai (jours)', 'wpservio' ) ) . "</label><input type=\"number\" name=\"wpservio_opts['+optIdx+'][delay]\" min=\"0\" step=\"1\" /></div>' +
                        '</div>' +
                        '<div class=\"serviceflow-meta-row\">' +
                            '<div class=\"serviceflow-field\"><label>" . esc_js( __( 'Description', 'wpservio' ) ) . "</label><input type=\"text\" name=\"wpservio_opts['+optIdx+'][description]\" /></div>' +
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
        if ( ! isset( $_POST['wpservio_options_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpservio_options_nonce'] ) ), 'wpservio_options_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( $post->post_type !== WpServio_Admin::get_post_type() ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Free = 1 seul service autorisé
        if ( ! wpservio_is_premium() ) {
            $existing_packs = get_post_meta( $post_id, '_wpservio_packs', true );
            if ( empty( $existing_packs ) && self::count_active_services( $post_id ) >= 1 ) {
                return;
            }
        }

        // Packs
        $packs_raw = wp_unslash( $_POST['wpservio_packs'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized individually in the loop below.
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
        update_post_meta( $post_id, '_wpservio_packs', $packs );

        // Options supplémentaires
        $opts_raw = wp_unslash( $_POST['wpservio_opts'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized individually in the loop below.
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
        update_post_meta( $post_id, '_wpservio_options', $opts );

        // Options avancées dynamiques
        $adv_opts   = [];
        $adv_labels = isset( $_POST['sf_adv_opt_label'] ) ? (array) wp_unslash( $_POST['sf_adv_opt_label'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per item below
        $adv_prices = isset( $_POST['sf_adv_opt_price'] ) ? (array) wp_unslash( $_POST['sf_adv_opt_price'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $adv_modes  = isset( $_POST['sf_adv_opt_mode'] )  ? (array) wp_unslash( $_POST['sf_adv_opt_mode'] )  : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $adv_units  = isset( $_POST['sf_adv_opt_unit'] )  ? (array) wp_unslash( $_POST['sf_adv_opt_unit'] )  : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $allowed_modes = [ 'unit', 'monthly', 'fixed', 'daily' ];
        foreach ( $adv_labels as $i => $raw_label ) {
            $label = sanitize_text_field( $raw_label );
            if ( empty( $label ) ) {
                continue;
            }
            $adv_opts[] = [
                'label'      => $label,
                'price'      => floatval( $adv_prices[ $i ] ?? 0 ),
                'mode'       => in_array( $adv_modes[ $i ] ?? '', $allowed_modes, true ) ? $adv_modes[ $i ] : 'unit',
                'unit_label' => sanitize_text_field( $adv_units[ $i ] ?? '' ),
            ];
        }
        update_post_meta( $post_id, '_wpservio_advanced_options', wp_json_encode( $adv_opts, JSON_UNESCAPED_UNICODE ) );

        // Mode de paiement
        if ( isset( $_POST['wpservio_payment_mode'] ) ) {
            $mode = sanitize_text_field( wp_unslash( $_POST['wpservio_payment_mode'] ) );
            if ( in_array( $mode, [ 'single', 'deposit', 'installments', 'monthly' ], true ) ) {
                update_post_meta( $post_id, '_wpservio_payment_mode', $mode );
            }
        }
        if ( isset( $_POST['wpservio_installments_count'] ) ) {
            update_post_meta( $post_id, '_wpservio_installments_count', absint( wp_unslash( $_POST['wpservio_installments_count'] ) ) );
        }
    }

    public static function get_payment_mode( int $post_id ): string {
        if ( ! wpservio_is_premium() ) {
            return 'single';
        }
        $meta = get_post_meta( $post_id, '_wpservio_payment_mode', true );
        if ( in_array( $meta, [ 'single', 'deposit', 'installments', 'monthly' ], true ) ) {
            return $meta;
        }
        return WpServio_Stripe::get_settings()['default_payment_mode'] ?? 'single';
    }

    public static function get_installments_count( int $post_id ): int {
        return max( 1, (int) get_post_meta( $post_id, '_wpservio_installments_count', true ) ?: 3 );
    }

    /**
     * Récupère les packs d'un post (avec migration depuis l'ancien format).
     */
    public static function get_packs( int $post_id ): array {
        $packs = get_post_meta( $post_id, '_wpservio_packs', true );
        if ( is_array( $packs ) && ! empty( $packs ) ) {
            return $packs;
        }

        // Migration : ancien format base_offer → pack unique
        $base = get_post_meta( $post_id, '_wpservio_base_offer', true );
        if ( is_array( $base ) && ! empty( $base['name'] ) ) {
            return [ $base ];
        }

        return [];
    }

    /**
     * Récupère les options supplémentaires d'un post.
     */
    public static function get_options( int $post_id ): array {
        $opts = get_post_meta( $post_id, '_wpservio_options', true );
        return is_array( $opts ) ? $opts : [];
    }
}
