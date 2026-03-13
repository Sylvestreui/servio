<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServiceFlow_Front {

    public static function init(): void {
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_footer', [ __CLASS__, 'render_chat' ], 9999 );
        add_shortcode( 'serviceflow_options', [ __CLASS__, 'shortcode_options' ] );
    }

    public static function enqueue_assets(): void {
        if ( ! self::is_chat_page() ) {
            return;
        }

        wp_enqueue_style(
            'serviceflow-style',
            SERVICEFLOW_PLUGIN_URL . 'assets/css/serviceflow.css',
            [],
            SERVICEFLOW_VERSION
        );
    }

    /**
     * Shortcode [serviceflow_options] — affiche packs + options de service.
     */
    public static function shortcode_options(): string {
        if ( ! self::is_chat_page() ) {
            return '';
        }

        $post_id = get_queried_object_id();
        $packs   = ServiceFlow_Options::get_packs( $post_id );
        $options = ServiceFlow_Options::get_options( $post_id );
        $color   = esc_attr( ServiceFlow_Admin::get_color() );

        if ( empty( $packs ) ) {
            return '';
        }

        $c              = $color;
        $first_price    = floatval( $packs[0]['price'] ?? 0 );
        $first_delay    = absint( $packs[0]['delay'] ?? 0 );
        $tax_rate       = serviceflow_is_premium() ? floatval( ServiceFlow_Invoices::get_settings()['tax_rate'] ?? 0 ) : 0;
        $payment_mode   = ServiceFlow_Options::get_payment_mode( $post_id );
        $payment_mode_labels = [
            'single'       => __( 'Paiement unique', 'serviceflow' ),
            'deposit'      => __( 'Acompte 50% + solde à la livraison', 'serviceflow' ),
            'installments' => __( 'Mensualités', 'serviceflow' ),
            'monthly'      => __( 'Abonnement mensuel', 'serviceflow' ),
        ];
        $payment_mode_label = $payment_mode_labels[ $payment_mode ] ?? __( 'Paiement unique', 'serviceflow' );

        ob_start();
        ?>
        <?php
        // Calcul de la luminance pour déterminer la couleur de texte lisible sur le fond du tooltip
        $hex = ltrim( $c, '#' );
        $r   = hexdec( substr( $hex, 0, 2 ) ) / 255;
        $g   = hexdec( substr( $hex, 2, 2 ) ) / 255;
        $b   = hexdec( substr( $hex, 4, 2 ) ) / 255;
        $lum = 0.299 * $r + 0.587 * $g + 0.114 * $b;
        $tip_text = $lum > 0.5 ? '#222' : '#fff';

        // Variante adoucie de la couleur pour les éléments secondaires (features, bordures actives)
        $r_int = hexdec( substr( $hex, 0, 2 ) );
        $g_int = hexdec( substr( $hex, 2, 2 ) );
        $b_int = hexdec( substr( $hex, 4, 2 ) );
        $c_light = sprintf( 'rgba(%d,%d,%d,0.12)', $r_int, $g_int, $b_int );
        $c_muted = sprintf( 'rgba(%d,%d,%d,0.7)', $r_int, $g_int, $b_int );
        ?>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            .serviceflow-info-tip{position:relative !important;cursor:help !important;display:inline-flex !important;align-items:center !important;color:#aaa !important}
            .serviceflow-info-tip .serviceflow-tip-text{visibility:hidden !important;opacity:0 !important;position:absolute !important;left:50% !important;bottom:calc(100% + 8px) !important;transform:translateX(-50%) !important;background:#333 !important;color:#fff !important;font-size:12px !important;font-weight:400 !important;line-height:1.4 !important;padding:8px 12px !important;border-radius:6px !important;max-width:350px !important;width:max-content !important;z-index:100 !important;pointer-events:none !important;transition:opacity .15s !important;box-shadow:0 2px 8px rgba(0,0,0,0.15) !important;text-align:left !important}
            .serviceflow-info-tip .serviceflow-tip-text::after{content:'' !important;position:absolute !important;top:100% !important;left:50% !important;transform:translateX(-50%) !important;border:5px solid transparent !important;border-top-color:#333 !important}
            .serviceflow-info-tip:hover .serviceflow-tip-text{visibility:visible !important;opacity:1 !important}
            /* Checkbox custom avec la couleur du plugin */
            .serviceflow-sc-check{-webkit-appearance:none !important;-moz-appearance:none !important;appearance:none !important;width:18px !important;height:18px !important;min-width:18px !important;border:2px solid #ccc !important;border-radius:4px !important;background:#fff !important;cursor:pointer !important;position:relative !important;transition:all .15s !important;margin:2px 0 0 0 !important;flex-shrink:0 !important}
            .serviceflow-sc-check:checked{background:<?php echo $c; ?> !important;border-color:<?php echo $c; ?> !important}
            .serviceflow-sc-check:checked::after{content:'' !important;position:absolute !important;left:5px !important;top:1px !important;width:5px !important;height:10px !important;border:solid #fff !important;border-width:0 2px 2px 0 !important;transform:rotate(45deg) !important}
            /* Scrollbar fine et discrète */
            #serviceflow-sc-card{scrollbar-width:none !important}
            #serviceflow-sc-card::-webkit-scrollbar{width:1px !important}
            #serviceflow-sc-card::-webkit-scrollbar-track{background:transparent !important}
            #serviceflow-sc-card::-webkit-scrollbar-thumb{background:rgba(0,0,0,0.15) !important;border-radius:1px !important}
            #serviceflow-sc-card:hover::-webkit-scrollbar{width:2px !important}
            #serviceflow-sc-card:hover::-webkit-scrollbar-thumb{background:<?php echo $c_muted; ?> !important}
        </style>
        <div id="serviceflow-sc-card" style="font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif !important;border:1px solid #e0e0e0 !important;border-radius:12px !important;overflow-x:hidden !important;overflow-y:auto !important;max-height:calc(100vh - 80px) !important;background:#fff !important;box-shadow:0 2px 8px rgba(0,0,0,0.06) !important;max-width:100% !important;padding:0 !important;margin:0 0 20px 0 !important">
            <div style="display:flex !important;align-items:center !important;gap:8px !important;padding:12px 16px !important;background:<?php echo $c; ?> !important;color:#fff !important;font-size:14px !important;font-weight:600 !important;margin:0 !important;border-radius:0 !important;position:sticky !important;top:0 !important;z-index:2 !important">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"></path><rect x="9" y="3" width="6" height="4" rx="1"></rect></svg>
                <span style="color:#fff !important;font-size:14px !important;font-weight:600 !important"><?php esc_html_e( 'Options de service', 'serviceflow' ); ?></span>
            </div>

            <!-- Packs -->
            <div style="padding:12px 16px 2px !important;font-size:12px !important;font-weight:600 !important;text-transform:uppercase !important;letter-spacing:0.5px !important;color:#888 !important;margin:0 !important"><?php esc_html_e( 'Choisissez votre pack', 'serviceflow' ); ?></div>
            <div style="padding:0 16px 8px !important;margin:0 !important">
                <span style="display:inline-flex !important;align-items:center !important;gap:4px !important;font-size:11px !important;font-weight:500 !important;color:#fff !important;background:<?php echo $c; ?> !important;padding:2px 8px !important;border-radius:20px !important;opacity:0.85 !important">&#128179; <?php echo esc_html( $payment_mode_label ); ?></span>
            </div>

            <div id="serviceflow-sc-packs" data-color="<?php echo $c; ?>" style="display:flex !important;flex-direction:column !important;gap:10px !important;padding:8px 16px 16px !important;margin:0 !important">
                <?php foreach ( $packs as $i => $pack ) :
                    $pack_delay = absint( $pack['delay'] ?? 0 );
                    $is_sel     = ( $i === 0 );
                    $brd        = $is_sel ? $c_muted : '#e0e0e0';
                    $bg         = $is_sel ? $c_light : '#fff';
                    $dot_bg     = $is_sel ? $c : 'transparent';
                    $dot_brd    = $is_sel ? $c : '#ccc';
                    $features   = $pack['features'] ?? [];
                ?>
                <div class="serviceflow-sc-pack" data-index="<?php echo $i; ?>" data-price="<?php echo esc_attr( $pack['price'] ); ?>" data-delay="<?php echo esc_attr( $pack_delay ); ?>" <?php echo $is_sel ? 'data-selected="true"' : ''; ?>
                     style="border:2px solid <?php echo $brd; ?> !important;border-radius:10px !important;padding:12px !important;cursor:pointer !important;background:<?php echo $bg; ?> !important;position:relative !important;box-sizing:border-box !important;margin:0 !important;transition:border-color .15s,background .15s !important">
                    <div class="serviceflow-pack-dot" style="position:absolute !important;top:10px !important;right:10px !important;width:18px !important;height:18px !important;border-radius:50% !important;border:2px solid <?php echo $dot_brd; ?> !important;background:<?php echo $dot_bg; ?> !important;display:flex !important;align-items:center !important;justify-content:center !important"><?php if ( $is_sel ) : ?><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><path d="M20 6L9 17l-5-5"></path></svg><?php endif; ?></div>
                    <div style="display:flex !important;align-items:center !important;gap:6px !important;margin:0 0 4px 0 !important;padding-right:28px !important">
                        <span style="font-size:14px !important;font-weight:700 !important;color:#222 !important"><?php echo esc_html( $pack['name'] ); ?></span>
                        <?php if ( ! empty( $pack['description'] ) ) : ?>
                            <span class="serviceflow-info-tip">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                                <span class="serviceflow-tip-text"><?php echo esc_html( $pack['description'] ); ?></span>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex !important;align-items:baseline !important;gap:8px !important;margin:0 0 4px 0 !important">
                        <span style="font-size:14px !important;font-weight:800 !important;color:<?php echo $c_muted; ?> !important"><?php echo esc_html( number_format( $pack['price'], 2, ',', ' ' ) ); ?> &euro;</span>
                        <?php if ( $pack_delay > 0 ) : ?>
                            <span style="font-size:11px !important;color:#888 !important">&#9201; <?php echo esc_html( $pack_delay ); ?> <?php esc_html_e( 'jour(s)', 'serviceflow' ); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ( is_array( $features ) && ! empty( $features ) ) : ?>
                        <div style="margin:6px 0 0 0 !important;padding:0 !important">
                            <?php foreach ( $features as $feat ) : ?>
                                <div style="display:flex !important;align-items:center !important;gap:6px !important;font-size:12px !important;color:#555 !important;line-height:1.6 !important;margin:0 !important;padding:0 !important">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="<?php echo $c_muted; ?>" stroke-width="2.5" style="flex-shrink:0 !important"><path d="M20 6L9 17l-5-5"></path></svg>
                                    <span style="font-size:12px !important;color:#555 !important"><?php echo esc_html( $feat ); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ( ! empty( $options ) ) : ?>
            <div style="height:0 !important;border-top:1px solid #e8e8e8 !important;margin:0 !important;padding:0 !important"></div>
            <div style="padding:12px 16px 4px !important;font-size:12px !important;font-weight:600 !important;text-transform:uppercase !important;letter-spacing:0.5px !important;color:#888 !important;margin:0 !important"><?php esc_html_e( 'Options supplémentaires', 'serviceflow' ); ?></div>

            <?php foreach ( $options as $i => $opt ) :
                $opt_delay = absint( $opt['delay'] ?? 0 );
            ?>
            <div class="serviceflow-sc-opt-wrap" style="display:flex !important;align-items:flex-start !important;gap:10px !important;padding:10px 16px !important;cursor:pointer !important;border-bottom:1px solid #f5f5f5 !important;margin:0 !important">
                <input type="checkbox" class="serviceflow-sc-check" data-index="<?php echo $i; ?>" data-price="<?php echo esc_attr( $opt['price'] ); ?>" data-delay="<?php echo esc_attr( $opt_delay ); ?>" style="width:18px !important;height:18px !important;min-width:18px !important;margin:2px 0 0 0 !important;flex-shrink:0 !important;cursor:pointer !important" />
                <div style="flex:1 !important;min-width:0 !important">
                    <div style="display:flex !important;justify-content:space-between !important;align-items:baseline !important;gap:8px !important;margin:0 !important;padding:0 !important">
                        <span style="font-size:13px !important;font-weight:500 !important;color:#333 !important"><?php echo esc_html( $opt['name'] ); ?></span>
                        <span style="font-size:13px !important;font-weight:600 !important;color:#555 !important;white-space:nowrap !important">+<?php echo esc_html( number_format( $opt['price'], 2, ',', ' ' ) ); ?> &euro;<?php if ( $opt_delay > 0 ) : ?> <span style="font-size:11px !important;color:#999 !important">+<?php echo esc_html( $opt_delay ); ?>j</span><?php endif; ?></span>
                    </div>
                    <?php if ( ! empty( $opt['description'] ) ) : ?>
                        <div style="font-size:11px !important;color:#999 !important;line-height:1.3 !important;margin:2px 0 0 0 !important;padding:0 !important"><?php echo esc_html( $opt['description'] ); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php
            $extra_page_price  = 0;
            $maintenance_price = 0;
            $express_price     = 0;
            if ( serviceflow_is_premium() && ServiceFlow_Admin::is_extra_pages_enabled() ) {
                $extra_page_price = floatval( get_post_meta( $post_id, '_serviceflow_extra_page_price', true ) );
            }
            if ( serviceflow_is_premium() && ServiceFlow_Admin::is_maintenance_enabled() ) {
                $maintenance_price = floatval( get_post_meta( $post_id, '_serviceflow_maintenance_price', true ) );
            }
            if ( serviceflow_is_premium() && ServiceFlow_Admin::is_express_enabled() ) {
                $express_price = floatval( get_post_meta( $post_id, '_serviceflow_express_price', true ) );
            }
            ?>

            <?php if ( $extra_page_price > 0 || $maintenance_price > 0 || $express_price > 0 ) : ?>
            <div style="height:0 !important;border-top:1px solid #e8e8e8 !important;margin:0 !important;padding:0 !important"></div>
            <?php endif; ?>

            <?php if ( $extra_page_price > 0 ) : ?>
            <div style="display:flex !important;align-items:center !important;gap:10px !important;padding:10px 16px !important;margin:0 !important">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="<?php echo $c; ?>" stroke-width="2" style="flex-shrink:0 !important"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <div style="flex:1 !important;min-width:0 !important">
                    <div style="font-size:13px !important;font-weight:500 !important;color:#333 !important"><?php esc_html_e( 'Pages supplémentaires', 'serviceflow' ); ?></div>
                    <div style="font-size:11px !important;color:#999 !important"><?php echo esc_html( number_format( $extra_page_price, 2, ',', ' ' ) ); ?> &euro; / <?php esc_html_e( 'page', 'serviceflow' ); ?></div>
                </div>
                <div style="display:flex !important;align-items:center !important;gap:6px !important">
                    <button type="button" id="serviceflow-pages-minus" style="width:28px !important;height:28px !important;border:1px solid #ddd !important;border-radius:6px !important;background:#fff !important;cursor:pointer !important;font-size:16px !important;line-height:1 !important;color:#555 !important;display:flex !important;align-items:center !important;justify-content:center !important">-</button>
                    <input type="number" id="serviceflow-pages-qty" value="0" min="0" max="99" style="width:44px !important;text-align:center !important;border:1px solid #ddd !important;border-radius:6px !important;padding:4px !important;font-size:13px !important;font-weight:600 !important" />
                    <button type="button" id="serviceflow-pages-plus" style="width:28px !important;height:28px !important;border:1px solid #ddd !important;border-radius:6px !important;background:#fff !important;cursor:pointer !important;font-size:16px !important;line-height:1 !important;color:#555 !important;display:flex !important;align-items:center !important;justify-content:center !important">+</button>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( $express_price > 0 ) : ?>
            <div style="display:flex !important;align-items:center !important;gap:10px !important;padding:10px 16px !important;margin:0 !important">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="<?php echo $c; ?>" stroke-width="2" style="flex-shrink:0 !important"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <div style="flex:1 !important;min-width:0 !important">
                    <div style="font-size:13px !important;font-weight:500 !important;color:#333 !important"><?php esc_html_e( 'Livraison express', 'serviceflow' ); ?></div>
                    <div style="font-size:11px !important;color:#999 !important"><?php echo esc_html( number_format( $express_price, 2, ',', ' ' ) ); ?> &euro; / <?php esc_html_e( 'jour retiré', 'serviceflow' ); ?></div>
                </div>
                <div style="display:flex !important;align-items:center !important;gap:6px !important">
                    <button type="button" id="serviceflow-express-minus" style="width:28px !important;height:28px !important;border:1px solid #ddd !important;border-radius:6px !important;background:#fff !important;cursor:pointer !important;font-size:16px !important;line-height:1 !important;color:#555 !important;display:flex !important;align-items:center !important;justify-content:center !important">-</button>
                    <input type="number" id="serviceflow-express-days" value="0" min="0" max="99" style="width:44px !important;text-align:center !important;border:1px solid #ddd !important;border-radius:6px !important;padding:4px !important;font-size:13px !important;font-weight:600 !important" />
                    <button type="button" id="serviceflow-express-plus" style="width:28px !important;height:28px !important;border:1px solid #ddd !important;border-radius:6px !important;background:#fff !important;cursor:pointer !important;font-size:16px !important;line-height:1 !important;color:#555 !important;display:flex !important;align-items:center !important;justify-content:center !important">+</button>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( $maintenance_price > 0 ) : ?>
            <div style="display:flex !important;align-items:center !important;gap:10px !important;padding:10px 16px !important;margin:0 !important">
                <input type="checkbox" id="serviceflow-maintenance-check" class="serviceflow-sc-check" style="width:18px !important;height:18px !important;min-width:18px !important;margin:0 !important;flex-shrink:0 !important;cursor:pointer !important" />
                <div style="flex:1 !important;min-width:0 !important">
                    <div style="font-size:13px !important;font-weight:500 !important;color:#333 !important"><?php esc_html_e( 'Maintenance', 'serviceflow' ); ?></div>
                    <div style="font-size:11px !important;color:#999 !important"><?php echo esc_html( number_format( $maintenance_price, 2, ',', ' ' ) ); ?> &euro; / <?php esc_html_e( 'mois', 'serviceflow' ); ?></div>
                </div>
                <span style="font-size:13px !important;font-weight:600 !important;color:#555 !important;white-space:nowrap !important"><?php echo esc_html( number_format( $maintenance_price, 2, ',', ' ' ) ); ?> &euro;/<?php esc_html_e( 'mois', 'serviceflow' ); ?></span>
            </div>
            <?php endif; ?>

            <!-- Total + Délai + Commander (masqué pour admin) -->
            <?php if ( ! current_user_can( 'manage_options' ) ) : ?>
            <?php
                $first_tva   = $tax_rate > 0 ? round( $first_price * $tax_rate / 100, 2 ) : 0;
                $first_total = round( $first_price + $first_tva, 2 );
            ?>
            <div style="padding:14px 16px !important;border-top:2px solid #e8e8e8 !important;background:#fafafa !important;margin:0 !important;position:sticky !important;bottom:0 !important;z-index:10 !important;border-radius:0 0 12px 12px !important">
                <div style="display:flex !important;justify-content:space-between !important;align-items:center !important;font-size:12px !important;color:#888 !important;margin:0 0 2px 0 !important;padding:0 !important">
                    <span><?php esc_html_e( 'Sous-total', 'serviceflow' ); ?></span>
                    <span id="serviceflow-sc-subtotal-val"><?php echo esc_html( number_format( $first_price, 2, ',', ' ' ) ); ?> &euro;</span>
                </div>
                <div style="display:flex !important;justify-content:space-between !important;align-items:center !important;font-size:12px !important;color:#888 !important;margin:0 0 6px 0 !important;padding:0 !important">
                    <?php if ( $tax_rate > 0 ) : ?>
                        <span><?php esc_html_e( 'TVA', 'serviceflow' ); ?> (<?php echo esc_html( $tax_rate ); ?>%)</span>
                        <span id="serviceflow-sc-tva-val"><?php echo esc_html( number_format( $first_tva, 2, ',', ' ' ) ); ?> &euro;</span>
                    <?php else : ?>
                        <span id="serviceflow-sc-tva-val" style="font-style:italic !important"><?php esc_html_e( 'TVA : 0% (non applicable)', 'serviceflow' ); ?></span>
                        <span></span>
                    <?php endif; ?>
                </div>
                <div style="display:flex !important;justify-content:space-between !important;align-items:center !important;font-size:16px !important;font-weight:700 !important;color:#222 !important;margin:0 0 4px 0 !important;padding:0 !important;border-top:1px solid #e8e8e8 !important;padding-top:6px !important">
                    <span style="font-size:16px !important;font-weight:700 !important;color:#222 !important"><?php esc_html_e( 'Total', 'serviceflow' ); ?></span>
                    <span id="serviceflow-sc-total-val" style="font-size:16px !important;font-weight:700 !important;color:<?php echo $c_muted; ?> !important"><?php echo esc_html( number_format( $first_total, 2, ',', ' ' ) ); ?> &euro;</span>
                </div>
                <div id="serviceflow-sc-delay-row" style="display:flex !important;justify-content:space-between !important;align-items:center !important;font-size:13px !important;color:#888 !important;margin:0 0 12px 0 !important;padding:0 !important">
                    <span style="font-size:13px !important;color:#888 !important">&#9201; <?php esc_html_e( 'Délai estimé', 'serviceflow' ); ?></span>
                    <span id="serviceflow-sc-delay-val" style="font-size:13px !important;font-weight:600 !important;color:#555 !important"><?php echo esc_html( $first_delay ); ?> <?php esc_html_e( 'jour(s)', 'serviceflow' ); ?></span>
                </div>
                <button type="button" id="serviceflow-sc-order" style="display:flex !important;align-items:center !important;justify-content:center !important;gap:8px !important;width:100% !important;padding:12px !important;border:none !important;border-radius:8px !important;background:<?php echo $c; ?> !important;color:#fff !important;font-size:14px !important;font-weight:600 !important;cursor:pointer !important;font-family:inherit !important;margin:0 !important;line-height:1.4 !important">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                    <?php echo ( serviceflow_is_premium() && ServiceFlow_Stripe::is_enabled() ) ? esc_html__( 'Payer et commander', 'serviceflow' ) : esc_html__( 'Commander via le chat', 'serviceflow' ); ?>
                </button>
            </div>
            <?php endif; ?>
        </div>

        <?php if ( ! current_user_can( 'manage_options' ) ) : ?>
        <!-- Barre sticky mobile : visible uniquement quand la carte options n'est pas à l'écran -->
        <div id="serviceflow-mobile-bar" style="display:none;position:fixed;bottom:0;left:0;right:0;z-index:2147483645;background:#fff;box-shadow:0 -2px 12px rgba(0,0,0,0.12);padding:12px 16px;font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
                <div style="flex-shrink:0">
                    <div style="font-size:11px;color:#888;text-transform:uppercase;font-weight:600;letter-spacing:0.3px"><?php esc_html_e( 'À partir de', 'serviceflow' ); ?></div>
                    <div id="serviceflow-mobile-price" style="font-size:16px;font-weight:600;color:<?php echo $c; ?>"><?php echo esc_html( number_format( $first_price, 2, ',', ' ' ) ); ?> &euro;</div>
                </div>
                <button type="button" id="serviceflow-mobile-cta" style="flex:1;max-width:240px;padding:12px 16px;border:none;border-radius:8px;background:<?php echo $c; ?>;color:#fff;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;line-height:1.4">
                    <?php esc_html_e( 'Voir les offres', 'serviceflow' ); ?>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <script>
        (function(){
            /* Options : click sur la ligne toggle la checkbox */
            var wraps = document.querySelectorAll('.serviceflow-sc-opt-wrap');
            wraps.forEach(function(w){
                w.addEventListener('click', function(e){
                    if(e.target.type !== 'checkbox'){
                        var cb = w.querySelector('input[type="checkbox"]');
                        if(cb && !cb.disabled){ cb.checked = !cb.checked; cb.dispatchEvent(new Event('change')); }
                    }
                });
            });

            /* Packs : sélection radio */
            var packs = document.querySelectorAll('.serviceflow-sc-pack');
            var packBox = document.getElementById('serviceflow-sc-packs');
            var pColor = packBox ? packBox.dataset.color : '#3b82f6';
            /* Extraire les composantes RGB pour créer des variantes nuancées en JS */
            var hexToRgb = function(h){ h = h.replace('#',''); return { r:parseInt(h.substring(0,2),16), g:parseInt(h.substring(2,4),16), b:parseInt(h.substring(4,6),16) }; };
            var rgb = hexToRgb(pColor);
            var pColorMuted = 'rgba('+rgb.r+','+rgb.g+','+rgb.b+',0.7)';
            var pColorLight = 'rgba('+rgb.r+','+rgb.g+','+rgb.b+',0.12)';
            var checkSvg = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><path d="M20 6L9 17l-5-5"></path></svg>';

            packs.forEach(function(p){
                p.addEventListener('click', function(){
                    if(packBox && packBox.hasAttribute('data-frozen')) return;
                    packs.forEach(function(pp){
                        pp.removeAttribute('data-selected');
                        pp.style.setProperty('border-color', '#e0e0e0', 'important');
                        pp.style.setProperty('background', '#fff', 'important');
                        var d = pp.querySelector('.serviceflow-pack-dot');
                        if(d){ d.style.setProperty('border-color','#ccc','important'); d.style.setProperty('background','transparent','important'); d.innerHTML=''; }
                    });
                    p.setAttribute('data-selected', 'true');
                    p.style.setProperty('border-color', pColorMuted, 'important');
                    p.style.setProperty('background', pColorLight, 'important');
                    var d = p.querySelector('.serviceflow-pack-dot');
                    if(d){ d.style.setProperty('border-color',pColor,'important'); d.style.setProperty('background',pColor,'important'); d.innerHTML=checkSvg; }
                    document.dispatchEvent(new Event('serviceflow_pack_changed'));
                });
            });

            /* Barre sticky mobile : afficher/masquer selon la visibilité de la carte */
            var mobileBar = document.getElementById('serviceflow-mobile-bar');
            var scCard    = document.getElementById('serviceflow-sc-card');
            var mobileCta = document.getElementById('serviceflow-mobile-cta');
            var mobilePrice = document.getElementById('serviceflow-mobile-price');

            var chatBtn = document.getElementById('serviceflow-toggle');

            if (mobileBar && scCard) {
                var isMobile = function(){ return window.innerWidth <= 768; };

                var adjustChatBtn = function(barVisible){
                    if (!chatBtn) return;
                    if (barVisible && isMobile()) {
                        chatBtn.style.setProperty('bottom', '80px', 'important');
                    } else {
                        chatBtn.style.setProperty('bottom', '24px', 'important');
                    }
                };

                var observer = new IntersectionObserver(function(entries){
                    if (!isMobile()) { mobileBar.style.display = 'none'; adjustChatBtn(false); return; }
                    var visible = entries[0].isIntersecting;
                    mobileBar.style.display = visible ? 'none' : 'block';
                    adjustChatBtn(!visible);
                }, { threshold: 0.1 });

                observer.observe(scCard);

                window.addEventListener('resize', function(){
                    if (!isMobile()) { mobileBar.style.display = 'none'; adjustChatBtn(false); }
                });

                if (mobileCta) {
                    mobileCta.addEventListener('click', function(){
                        scCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    });
                }

                /* Synchroniser le prix de la barre mobile avec le total de la carte */
                if (mobilePrice) {
                    var syncPrice = function(){
                        var totalEl = document.getElementById('serviceflow-sc-total-val');
                        if (totalEl) mobilePrice.textContent = totalEl.textContent;
                    };
                    document.addEventListener('serviceflow_pack_changed', syncPrice);
                    var checks = document.querySelectorAll('.serviceflow-sc-check');
                    checks.forEach(function(cb){ cb.addEventListener('change', function(){ setTimeout(syncPrice, 50); }); });
                }
            }

            /* Pages supplémentaires : boutons +/- */
            var pagesQty   = document.getElementById('serviceflow-pages-qty');
            var pagesMinus = document.getElementById('serviceflow-pages-minus');
            var pagesPlus  = document.getElementById('serviceflow-pages-plus');
            if(pagesMinus) pagesMinus.addEventListener('click',function(e){
                e.preventDefault(); e.stopPropagation();
                if(pagesQty && parseInt(pagesQty.value)>0){
                    pagesQty.value = parseInt(pagesQty.value) - 1;
                    document.dispatchEvent(new Event('serviceflow_pack_changed'));
                }
            });
            if(pagesPlus) pagesPlus.addEventListener('click',function(e){
                e.preventDefault(); e.stopPropagation();
                if(pagesQty){
                    pagesQty.value = parseInt(pagesQty.value) + 1;
                    document.dispatchEvent(new Event('serviceflow_pack_changed'));
                }
            });
            if(pagesQty) pagesQty.addEventListener('change',function(){
                if(parseInt(pagesQty.value)<0) pagesQty.value = 0;
                document.dispatchEvent(new Event('serviceflow_pack_changed'));
            });

            /* Maintenance : checkbox */
            var maintCheck = document.getElementById('serviceflow-maintenance-check');
            if(maintCheck) maintCheck.addEventListener('change', function(){
                document.dispatchEvent(new Event('serviceflow_pack_changed'));
            });

            /* Livraison express : boutons +/- jours */
            var expressDays  = document.getElementById('serviceflow-express-days');
            var expressMinus = document.getElementById('serviceflow-express-minus');
            var expressPlus  = document.getElementById('serviceflow-express-plus');
            if(expressMinus) expressMinus.addEventListener('click',function(e){
                e.preventDefault(); e.stopPropagation();
                if(expressDays && parseInt(expressDays.value)>0){
                    expressDays.value = parseInt(expressDays.value) - 1;
                    document.dispatchEvent(new Event('serviceflow_pack_changed'));
                }
            });
            if(expressPlus) expressPlus.addEventListener('click',function(e){
                e.preventDefault(); e.stopPropagation();
                if(expressDays){
                    expressDays.value = parseInt(expressDays.value) + 1;
                    document.dispatchEvent(new Event('serviceflow_pack_changed'));
                }
            });
            if(expressDays) expressDays.addEventListener('change',function(){
                if(parseInt(expressDays.value)<0) expressDays.value = 0;
                document.dispatchEvent(new Event('serviceflow_pack_changed'));
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Popup chat (bouton flottant + panneau messages).
     */
    public static function render_chat(): void {
        if ( ! self::is_chat_page() ) {
            return;
        }

        $color       = esc_attr( ServiceFlow_Admin::get_color() );
        $position    = ServiceFlow_Admin::get_position();
        $pos_parts   = explode( '-', $position );
        $vertical    = $pos_parts[0] ?? '';
        $horizontal  = $pos_parts[1] ?? '';
        $is_logged   = is_user_logged_in();
        $post_id     = get_queried_object_id();

        if ( empty( $vertical ) || ! in_array( $vertical, [ 'top', 'bottom' ], true ) ) {
            $vertical = 'bottom';
        }
        if ( empty( $horizontal ) || ! in_array( $horizontal, [ 'left', 'right' ], true ) ) {
            $horizontal = 'right';
        }

        $btn_style = implode( ';', [
            'position:fixed !important',
            'z-index:2147483647 !important',
            'width:60px !important',
            'height:60px !important',
            'border:none !important',
            'border-radius:50% !important',
            'background:' . $color . ' !important',
            'color:#fff !important',
            'cursor:pointer',
            'display:flex !important',
            'align-items:center',
            'justify-content:center',
            'box-shadow:0 4px 16px rgba(0,0,0,0.25)',
            'padding:0 !important',
            'margin:0 !important',
            'line-height:1',
            'visibility:visible !important',
            'opacity:1 !important',
            $vertical . ':24px !important',
            $horizontal . ':24px !important',
        ] );

        $popup_v = $vertical === 'bottom' ? 'bottom:100px' : 'top:100px';
        $popup_style = implode( ';', [
            'position:fixed !important',
            'z-index:2147483646 !important',
            'width:380px',
            'max-width:calc(100vw - 24px)',
            'max-height:520px',
            'border-radius:16px',
            'overflow:hidden',
            'background:#fff !important',
            'box-shadow:0 8px 32px rgba(0,0,0,0.18)',
            'flex-direction:column',
            'font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif',
            'display:none',
            $popup_v . ' !important',
            $horizontal . ':24px !important',
        ] );

        $packs   = ServiceFlow_Options::get_packs( $post_id );
        $options = ServiceFlow_Options::get_options( $post_id );

        $is_admin = current_user_can( 'manage_options' );

        $active_order = null;
        if ( $is_logged ) {
            $active_order = ServiceFlow_Orders::build_order_response( $post_id );
        }

        // Prix options avancées (aussi utilisés par le shortcode)
        $extra_page_price  = 0;
        $maintenance_price = 0;
        if ( ServiceFlow_Admin::is_extra_pages_enabled() ) {
            $extra_page_price = floatval( get_post_meta( $post_id, '_serviceflow_extra_page_price', true ) );
        }
        if ( ServiceFlow_Admin::is_maintenance_enabled() ) {
            $maintenance_price = floatval( get_post_meta( $post_id, '_serviceflow_maintenance_price', true ) );
        }
        $express_price = 0;
        if ( ServiceFlow_Admin::is_express_enabled() ) {
            $express_price = floatval( get_post_meta( $post_id, '_serviceflow_express_price', true ) );
        }
        $tax_rate = serviceflow_is_premium() ? floatval( ServiceFlow_Invoices::get_settings()['tax_rate'] ?? 0 ) : 0;

        $js_config = wp_json_encode( [
            'ajax_url'     => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'serviceflow_nonce' ),
            'post_id'      => $post_id,
            'post_title'   => get_the_title( $post_id ),
            'user_id'      => get_current_user_id(),
            'color'        => $color,
            'is_admin'     => $is_admin,
            'is_logged'    => $is_logged,
            'login_url'    => wp_login_url( get_permalink( $post_id ) ),
            'active_order' => $active_order,
            'packs'        => ! empty( $packs ) ? $packs : [],
            'options'      => ! empty( $options ) ? $options : [],
            'extra_page_price'        => $extra_page_price,
            'maintenance_price'       => $maintenance_price,
            'express_price'           => $express_price,
            'tax_rate'                => $tax_rate,
            'is_premium'              => serviceflow_is_premium(),
            'stripe_enabled'          => ServiceFlow_Stripe::is_enabled(),
            'stripe_checkout_action'  => 'serviceflow_stripe_checkout',
            'i18n'         => [
                'error'             => __( 'Erreur lors de l\'envoi.', 'serviceflow' ),
                'empty'             => __( 'Pas encore de message — posez votre première question ! 👋', 'serviceflow' ),
                'order_btn'         => ServiceFlow_Stripe::is_enabled()
                    ? __( 'Payer et commander', 'serviceflow' )
                    : __( 'Commander via le chat', 'serviceflow' ),
                'order_modify_btn'  => __( 'Modifier la commande', 'serviceflow' ),
                'order_locked'      => __( 'Commande en cours...', 'serviceflow' ),
                'order_replace'     => __( 'Vous avez déjà envoyé une commande. Voulez-vous la remplacer par cette nouvelle sélection ?', 'serviceflow' ),
                'order_modify'      => __( 'Modification de commande', 'serviceflow' ),
                'days'              => __( 'jour(s)', 'serviceflow' ),
                'estimated'         => __( 'Livraison estimée', 'serviceflow' ),
                'start_order'       => __( 'Démarrer', 'serviceflow' ),
                'complete_order'    => __( 'Terminer', 'serviceflow' ),
                'request_revision'  => __( 'Demander une retouche', 'serviceflow' ),
                'accept_delivery'   => __( 'Accepter la livraison', 'serviceflow' ),
                'validate_order'    => __( 'Valider', 'serviceflow' ),
                'validate_revision' => __( 'Valider la retouche', 'serviceflow' ),
                'revision_delay_placeholder' => __( 'Délai (jours)', 'serviceflow' ),
                'status_pending'    => __( 'En attente', 'serviceflow' ),
                'status_paid'       => __( 'Payée', 'serviceflow' ),
                'status_started'    => __( 'En cours', 'serviceflow' ),
                'status_completed'  => __( 'Terminée', 'serviceflow' ),
                'status_revision'   => __( 'Retouche demandée', 'serviceflow' ),
                'status_accepted'   => __( 'Acceptée', 'serviceflow' ),
                'client'            => __( 'Client', 'serviceflow' ),
                'service'           => __( 'Service', 'serviceflow' ),
                'delay_total'       => __( 'Délai total', 'serviceflow' ),
                'order_error'       => __( 'Erreur lors de la création de la commande.', 'serviceflow' ),
                'order_no_modify'   => __( 'La commande ne peut plus être modifiée.', 'serviceflow' ),
                'conversations'     => __( 'Conversations', 'serviceflow' ),
                'no_conversations'  => __( 'Aucune conversation.', 'serviceflow' ),
                'chat'              => __( 'Chat', 'serviceflow' ),
                'payment_redirect'  => __( 'Redirection vers le paiement...', 'serviceflow' ),
                'payment_error'     => __( 'Erreur lors de la création du paiement.', 'serviceflow' ),
                'login_required'    => __( 'Connectez-vous pour commander.', 'serviceflow' ),
                'progress'          => __( 'Progression', 'serviceflow' ),
                'add_note'          => __( 'Ajouter une note (optionnel)', 'serviceflow' ),
                'todo_note'         => __( 'Note', 'serviceflow' ),
            ],
        ] );
        ?>

        <!-- ServiceFlow : Bouton flottant -->
        <button id="serviceflow-toggle" style="<?php echo $btn_style; ?>" aria-label="Chat">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
            <span id="serviceflow-badge" style="position:absolute;top:-2px;right:-2px;min-width:20px;height:20px;background:#e53e3e;color:#fff;border-radius:50%;font-size:11px;font-weight:700;align-items:center;justify-content:center;line-height:1;display:none"></span>
        </button>

        <style>
        @media (min-width: 768px) {
            #serviceflow-container { min-height: 520px !important; max-height: 680px !important; }
        }
        </style>

        <!-- ServiceFlow : Popup -->
        <div id="serviceflow-container" style="<?php echo $popup_style; ?>">
            <!-- Header avec bouton retour -->
            <div id="serviceflow-header" style="background:<?php echo $color; ?> !important;color:#fff !important;padding:14px 20px !important;flex-shrink:0 !important;display:flex !important;align-items:center !important;gap:10px !important;margin:0 !important">
                <button id="serviceflow-back" type="button" style="display:none !important;background:none !important;border:none !important;color:#fff !important;cursor:pointer !important;padding:0 !important;margin:0 !important;flex-shrink:0 !important;line-height:1 !important">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5"></path><path d="M12 19l-7-7 7-7"></path></svg>
                </button>
                <h3 id="serviceflow-header-title" style="margin:0 !important;font-size:16px !important;font-weight:600 !important;color:#fff !important;flex:1 !important"><?php esc_html_e( 'Chat', 'serviceflow' ); ?></h3>
            </div>

            <!-- Liste de conversations (admin uniquement) -->
            <div id="serviceflow-client-list" style="display:none !important;flex:1 !important;overflow-y:auto !important;background:#f9fafb !important;padding:0 !important"></div>

            <div id="serviceflow-order-bar" style="display:none;padding:10px 16px;background:#f0f4ff;border-bottom:1px solid #d0d9e8;font-size:13px;flex-shrink:0"></div>
            <div id="serviceflow-todo-bar" style="display:none;padding:10px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:12px;flex-shrink:0;max-height:200px;overflow-y:auto"></div>

            <div id="serviceflow-messages" class="serviceflow-messages">
                <div class="serviceflow-loading"><?php esc_html_e( 'Chargement...', 'serviceflow' ); ?></div>
            </div>

            <?php if ( $is_logged ) : ?>
                <form id="serviceflow-form" class="serviceflow-form" onsubmit="return false;">
                    <div class="serviceflow-input-wrapper">
                        <textarea id="serviceflow-input" class="serviceflow-input" placeholder="<?php esc_attr_e( 'Votre message...', 'serviceflow' ); ?>" rows="1" maxlength="1000"></textarea>
                        <button type="button" id="serviceflow-send" class="serviceflow-send" style="background:<?php echo $color; ?>">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"></path><path d="M22 2L15 22L11 13L2 9L22 2Z"></path></svg>
                        </button>
                    </div>
                </form>
            <?php else : ?>
                <div class="serviceflow-login-notice">
                    <p><?php
                        printf(
                            esc_html__( '%s pour participer au chat.', 'serviceflow' ),
                            '<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Connectez-vous', 'serviceflow' ) . '</a>'
                        );
                    ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- ServiceFlow : Script -->
        <script>
        (function(){
            var C = <?php echo $js_config; ?>;
            var POLL = 5000;
            var lsKey = 'sf_last_seen_' + C.post_id + '_' + C.user_id;
            var lastId = parseInt(localStorage.getItem(lsKey) || '0', 10);
            var hasLoaded = false, unread = 0;

            var btn         = document.getElementById('serviceflow-toggle');
            var box         = document.getElementById('serviceflow-container');
            var msgs        = document.getElementById('serviceflow-messages');
            var input       = document.getElementById('serviceflow-input');
            var send        = document.getElementById('serviceflow-send');
            var badge       = document.getElementById('serviceflow-badge');
            var orderBar    = document.getElementById('serviceflow-order-bar');
            var todoBar     = document.getElementById('serviceflow-todo-bar');
            var clientList  = document.getElementById('serviceflow-client-list');
            var backBtn     = document.getElementById('serviceflow-back');
            var headerTitle = document.getElementById('serviceflow-header-title');
            var chatForm    = document.getElementById('serviceflow-form');

            if(!btn || !box || !msgs) return;

            /* ── Admin : état conversation ────────────── */
            var selectedClientId = 0;
            var sfOrderCollapsed = false;
            var sfTodoCollapsed  = false;

            /* ── Toggle popup ────────────────────────── */
            function markSeen(){
                if(lastId > 0) localStorage.setItem(lsKey, lastId);
            }

            function openChat(){
                box.style.display = 'flex';
                unread = 0; updBadge(); markSeen();

                if(C.is_admin){
                    showClientList();
                } else {
                    if(!hasLoaded){ hasLoaded = true; loadMsgs(); }
                    else { scrollEnd(); }
                    renderOrderBar();
                    if(input) input.focus();
                }
            }

            function closeChat(){ box.style.display = 'none'; }

            btn.addEventListener('click', function(e){
                e.preventDefault(); e.stopPropagation();
                box.style.display === 'flex' ? closeChat() : openChat();
            });

            document.addEventListener('click', function(e){
                if(box.style.display==='flex' && !box.contains(e.target) && !btn.contains(e.target)) closeChat();
            });

            /* ── Retour Stripe (sf_payment_success) ──── */
            (function(){
                var urlParams = new URLSearchParams(window.location.search);
                var schedId   = parseInt(urlParams.get('schedule_id') || '0', 10);
                if(!urlParams.get('sf_payment_success') || !schedId || C.is_admin) return;

                // Vérifier si le paiement est confirmé (fallback si webhook pas encore reçu)
                fetch(C.ajax_url + '?action=serviceflow_schedule_check&schedule_id='+schedId+'&nonce='+C.nonce)
                .then(function(r){ return r.json(); })
                .then(function(res){
                    // Ouvrir le chat dans tous les cas pour que le client voit l'état
                    openChat();
                    // Nettoyer l'URL
                    var clean = window.location.pathname + window.location.search
                        .replace(/[?&]sf_payment_success=1/, '')
                        .replace(/[?&]schedule_id=\d+/, '');
                    if(clean !== window.location.pathname + window.location.search){
                        history.replaceState(null, '', clean || window.location.pathname);
                    }
                })
                .catch(function(){ openChat(); });
            })();

            /* ── Admin : liste de conversations ──────── */
            function showClientList(){
                selectedClientId = 0;
                clientList.style.display = 'block';
                clientList.style.setProperty('display','block','important');
                msgs.style.display = 'none';
                orderBar.style.display = 'none';
                if(chatForm) chatForm.style.display = 'none';
                if(backBtn) backBtn.style.setProperty('display','none','important');
                if(headerTitle) headerTitle.textContent = C.i18n.conversations;
                loadClientList();
            }

            function loadClientList(){
                if(!C.is_admin) return;
                clientList.innerHTML = '<div style="padding:30px 20px !important;text-align:center !important;color:#999 !important;font-size:14px !important">Chargement...</div>';
                fetch(C.ajax_url+'?'+new URLSearchParams({action:'serviceflow_get_clients',post_id:C.post_id,nonce:C.nonce}))
                .then(function(r){return r.json();})
                .then(function(res){
                    if(!res.success||!res.data||!res.data.length){
                        clientList.innerHTML='<div style="padding:30px 20px !important;text-align:center !important;color:#999 !important;font-size:14px !important">'+esc(C.i18n.no_conversations)+'</div>';
                        return;
                    }
                    var html = '';
                    res.data.forEach(function(cl){
                        var dotColors = {pending:'#f59e0b',paid:'#8b5cf6',started:'#3b82f6',completed:'#10b981',revision:'#ef4444',accepted:'#6b7280'};
                        var statusDot = '';
                        if(cl.has_order && cl.order_status){
                            var dc = dotColors[cl.order_status]||'#ccc';
                            statusDot = '<span style="display:inline-block !important;width:8px !important;height:8px !important;border-radius:50% !important;background:'+dc+' !important;flex-shrink:0 !important"></span>';
                        }
                        var orderLabel = cl.has_order ? '<span style="font-size:11px !important;color:#888 !important">#CMD-'+cl.order_id+'</span>' : '';
                        html += '<div class="serviceflow-client-row" data-client-id="'+cl.client_id+'" data-client-name="'+esc(cl.display_name)+'" style="display:flex !important;align-items:center !important;gap:10px !important;padding:12px 16px !important;border-bottom:1px solid #eee !important;cursor:pointer !important;background:#fff !important;transition:background 0.15s !important">'
                            + '<img src="'+esc(cl.avatar)+'" style="width:36px !important;height:36px !important;border-radius:50% !important;object-fit:cover !important;flex-shrink:0 !important" />'
                            + '<div style="flex:1 !important;min-width:0 !important">'
                            + '<div style="font-size:14px !important;font-weight:600 !important;color:#222 !important">'+esc(cl.display_name)+'</div>'
                            + orderLabel
                            + '</div>'
                            + statusDot
                            + '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#999" stroke-width="2" style="flex-shrink:0 !important"><path d="M9 18l6-6-6-6"></path></svg>'
                            + '</div>';
                    });
                    clientList.innerHTML = html;

                    clientList.querySelectorAll('.serviceflow-client-row').forEach(function(row){
                        row.addEventListener('click', function(){
                            selectClient(parseInt(row.dataset.clientId), row.dataset.clientName);
                        });
                    });
                });
            }

            function selectClient(clientId, clientName){
                selectedClientId = clientId;
                clientList.style.setProperty('display','none','important');
                msgs.style.display = 'flex';
                if(chatForm) chatForm.style.display = 'block';
                if(backBtn) backBtn.style.setProperty('display','inline-flex','important');
                if(headerTitle) headerTitle.textContent = clientName || 'Chat';

                hasLoaded = false;
                lastId = 0;
                msgs.innerHTML = '<div class="serviceflow-loading">Chargement...</div>';
                loadMsgs();
                renderOrderBar();
                if(input) input.focus();
            }

            if(backBtn){
                backBtn.addEventListener('click', function(e){
                    e.preventDefault(); e.stopPropagation();
                    showClientList();
                });
            }

            /* ── Shortcode elements ──────────────────── */
            var scOrder   = document.getElementById('serviceflow-sc-order');
            var scChecks  = document.querySelectorAll('.serviceflow-sc-check');
            var scPacks   = document.querySelectorAll('.serviceflow-sc-pack');
            var scTotal    = document.getElementById('serviceflow-sc-total-val');
            var scSubtotal = document.getElementById('serviceflow-sc-subtotal-val');
            var scTva      = document.getElementById('serviceflow-sc-tva-val');
            var scDelay    = document.getElementById('serviceflow-sc-delay-val');
            var packBox   = document.getElementById('serviceflow-sc-packs');
            var scOrigBg  = C.color;

            var selectedPackIdx = 0;
            var orderSent   = false;
            var isEditing   = false;
            var orderFrozen = false;
            var lastOrderStateKey = null;

            /* Options avancées : gérées dans le premier script (shortcode) via serviceflow_pack_changed */
            var pagesQty    = document.getElementById('serviceflow-pages-qty');
            var maintCheck  = document.getElementById('serviceflow-maintenance-check');
            var expressDays = document.getElementById('serviceflow-express-days');

            var svgChat = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>';
            var svgEdit = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';

            /* Pack sélection — écouter l'événement du shortcode */
            document.addEventListener('serviceflow_pack_changed', function(){
                var sel = document.querySelector('.serviceflow-sc-pack[data-selected]');
                if(sel) selectedPackIdx = parseInt(sel.dataset.index);
                calcScTotal();
            });

            function getSelectedPack(){
                return C.packs[selectedPackIdx] || C.packs[0] || {name:'',price:0,delay:0};
            }

            function setOrderBtnText(text, icon){
                if(!scOrder) return;
                scOrder.innerHTML = icon + ' ' + esc(text);
            }

            function lockCard(){
                orderSent = true;
                isEditing = false;
                scChecks.forEach(function(cb){ cb.disabled = true; cb.style.opacity = '0.5'; cb.style.cursor = 'default'; });
                document.querySelectorAll('.serviceflow-sc-opt-wrap').forEach(function(w){ w.style.cursor = 'default'; w.style.opacity = '0.7'; });
                scPacks.forEach(function(p){ p.style.setProperty('opacity','0.7','important'); p.style.setProperty('cursor','default','important'); });
                if(packBox) packBox.setAttribute('data-frozen','true');
                setOrderBtnText(C.i18n.order_modify_btn, svgEdit);
                if(scOrder){
                    scOrder.style.setProperty('background', scOrigBg, 'important');
                    scOrder.style.setProperty('opacity', '1', 'important');
                    scOrder.style.setProperty('cursor', 'pointer', 'important');
                    scOrder.disabled = false;
                }
            }

            function unlockCard(){
                isEditing = true;
                scChecks.forEach(function(cb){ cb.disabled = false; cb.style.opacity = '1'; cb.style.cursor = 'pointer'; });
                document.querySelectorAll('.serviceflow-sc-opt-wrap').forEach(function(w){ w.style.cursor = 'pointer'; w.style.opacity = '1'; });
                scPacks.forEach(function(p){ p.style.setProperty('opacity','1','important'); p.style.setProperty('cursor','pointer','important'); });
                if(packBox) packBox.removeAttribute('data-frozen');
                setOrderBtnText(C.i18n.order_btn, svgChat);
                if(scOrder){
                    scOrder.style.setProperty('background', scOrigBg, 'important');
                    scOrder.style.setProperty('opacity', '1', 'important');
                    scOrder.style.setProperty('cursor', 'pointer', 'important');
                    scOrder.disabled = false;
                }
            }

            function freezeCard(){
                orderFrozen = true;
                orderSent = true;
                isEditing = false;
                scChecks.forEach(function(cb){ cb.disabled = true; cb.style.opacity = '0.5'; cb.style.cursor = 'default'; });
                document.querySelectorAll('.serviceflow-sc-opt-wrap').forEach(function(w){ w.style.cursor = 'default'; w.style.opacity = '0.7'; });
                scPacks.forEach(function(p){ p.style.setProperty('opacity','0.7','important'); p.style.setProperty('cursor','default','important'); });
                if(packBox) packBox.setAttribute('data-frozen','true');
                setOrderBtnText(C.i18n.order_locked, svgChat);
                if(scOrder){
                    scOrder.style.setProperty('background', '#6c757d', 'important');
                    scOrder.style.setProperty('opacity', '0.6', 'important');
                    scOrder.style.setProperty('cursor', 'not-allowed', 'important');
                    scOrder.disabled = true;
                }
            }

            function resetCard(){
                orderSent = false;
                isEditing = false;
                orderFrozen = false;
                scChecks.forEach(function(cb){ cb.disabled = false; cb.style.opacity = '1'; cb.style.cursor = 'pointer'; cb.checked = false; });
                document.querySelectorAll('.serviceflow-sc-opt-wrap').forEach(function(w){ w.style.cursor = 'pointer'; w.style.opacity = '1'; });
                scPacks.forEach(function(p){ p.style.setProperty('opacity','1','important'); p.style.setProperty('cursor','pointer','important'); });
                if(packBox) packBox.removeAttribute('data-frozen');
                setOrderBtnText(C.i18n.order_btn, svgChat);
                if(scOrder){
                    scOrder.style.setProperty('background', scOrigBg, 'important');
                    scOrder.style.setProperty('opacity', '1', 'important');
                    scOrder.style.setProperty('cursor', 'pointer', 'important');
                    scOrder.disabled = false;
                }
                calcScTotal();
            }

            function syncCardState(){
                if(C.is_admin) return;
                var o = C.active_order;
                if(!o || !o.id) return;
                if(o.status === 'accepted'){
                    resetCard();
                } else if(o.status === 'pending'){
                    lockCard();
                } else {
                    freezeCard();
                }
            }
            syncCardState();

            function buildOrderMsg(isMod){
                var pack = getSelectedPack();
                var prefix = isMod ? '\ud83d\udd04 ' + C.i18n.order_modify + ' :\n' : '';
                var lines = [prefix + '\ud83d\udccb Ma s\u00e9lection :'];
                var pDelay = parseInt(pack.delay)||0;
                var pDelayStr = pDelay > 0 ? ' (\u23f1 ' + pDelay + 'j)' : '';
                lines.push('\ud83d\udce6 ' + pack.name + ' \u2014 ' + fmtPrice(pack.price) + pDelayStr);

                var totalDelay = pDelay;
                scChecks.forEach(function(cb){
                    if(cb.checked){
                        var opt = C.options[parseInt(cb.dataset.index)];
                        if(opt){
                            var d = parseInt(cb.dataset.delay)||0;
                            var ds = d > 0 ? ' (+' + d + 'j)' : '';
                            lines.push('\u2705 ' + opt.name + ' \u2014 ' + fmtPrice(opt.price) + ds);
                            totalDelay += d;
                        }
                    }
                });

                var total = parseFloat(pack.price)||0;
                scChecks.forEach(function(cb){ if(cb.checked) total += parseFloat(cb.dataset.price)||0; });

                var epQty = getExtraPagesQty();
                if(epQty > 0){
                    var epTotal = epQty * (parseFloat(C.extra_page_price)||0);
                    lines.push('\ud83d\udcc4 Pages suppl\u00e9mentaires (' + epQty + ') \u2014 ' + fmtPrice(epTotal));
                    total += epTotal;
                }

                var exDays = getExpressDays();
                if(exDays > 0){
                    var minD = Math.ceil(totalDelay * 0.45);
                    var maxOff = totalDelay - minD;
                    if(exDays > maxOff) exDays = maxOff;
                    var exTotal = exDays * (parseFloat(C.express_price)||0);
                    lines.push('\u26a1 Livraison express (-' + exDays + 'j) \u2014 ' + fmtPrice(exTotal));
                    total += exTotal;
                    totalDelay -= exDays;
                }

                if(isMaintenanceChecked()){
                    lines.push('\ud83d\udee0 Maintenance \u2014 ' + fmtPrice(C.maintenance_price) + '/mois');
                }

                var taxRate = parseFloat(C.tax_rate) || 0;
                var totalTTC = taxRate > 0 ? Math.round(total * (1 + taxRate / 100) * 100) / 100 : total;
                lines.push('\ud83d\udcb0 Total TTC : ' + totalTTC.toFixed(2).replace('.',',') + ' \u20ac');
                if(isMaintenanceChecked()) lines.push('+ ' + fmtPrice(C.maintenance_price) + '/mois (maintenance)');
                if(totalDelay > 0) lines.push('\u23f0 ' + C.i18n.delay_total + ' : ' + totalDelay + ' ' + C.i18n.days);
                return lines.join('\n');
            }

            function getSelectedIndices(){
                var indices = [];
                scChecks.forEach(function(cb){ if(cb.checked) indices.push(parseInt(cb.dataset.index)); });
                return indices;
            }

            if(scOrder){
                var svgChat = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>';
                function setOrderBtnText(txt, icon){ scOrder.innerHTML = (icon||'') + ' ' + txt; }

                scOrder.addEventListener('click', function(e){
                    e.preventDefault(); e.stopPropagation();
                    if(!C.is_logged){
                        window.location.href = C.login_url;
                        return;
                    }
                    if(C.is_admin) return;
                    if(orderFrozen) return;

                    if(orderSent && !isEditing){
                        unlockCard();
                        return;
                    }

                    if(C.stripe_enabled){
                        /* ── FLUX STRIPE ── */
                        scOrder.disabled = true;
                        setOrderBtnText(C.i18n.payment_redirect, svgChat);

                        var fd = new FormData();
                        fd.append('action', C.stripe_checkout_action);
                        fd.append('post_id', C.post_id);
                        fd.append('nonce', C.nonce);
                        fd.append('selected_pack', selectedPackIdx);
                        fd.append('selected_indices', JSON.stringify(getSelectedIndices()));
                        fd.append('extra_pages', getExtraPagesQty());
                        fd.append('maintenance', isMaintenanceChecked() ? '1' : '0');
                        fd.append('express_days', getExpressDays());

                        fetch(C.ajax_url, {method:'POST', body:fd})
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            if(res.success && res.data && res.data.checkout_url){
                                window.location.href = res.data.checkout_url;
                            } else {
                                alert(res.data && res.data.message ? res.data.message : C.i18n.payment_error);
                                scOrder.disabled = false;
                                setOrderBtnText(C.i18n.order_btn, svgChat);
                            }
                        })
                        .catch(function(){
                            alert(C.i18n.payment_error);
                            scOrder.disabled = false;
                            setOrderBtnText(C.i18n.order_btn, svgChat);
                        });
                    } else {
                        /* ── FLUX MANUEL (existant) ── */
                        if(!input) return;
                        var msg = buildOrderMsg(isEditing);
                        openChat();

                        var fd = new FormData();
                        fd.append('action', 'serviceflow_create_order');
                        fd.append('post_id', C.post_id);
                        fd.append('nonce', C.nonce);
                        fd.append('selected_pack', selectedPackIdx);
                        fd.append('selected_indices', JSON.stringify(getSelectedIndices()));
                        fd.append('extra_pages', getExtraPagesQty());
                        fd.append('maintenance', isMaintenanceChecked() ? '1' : '0');
                        fd.append('express_days', getExpressDays());

                        fetch(C.ajax_url, {method:'POST', body:fd})
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            if(res.success && res.data){
                                C.active_order = res.data.active_order;
                                renderOrderBar();
                                input.value = msg;
                                doSend();
                                lockCard();
                            }
                        });
                    }
                });
            }

            /* ── Total + delay calc ──────────────────── */
            function getExtraPagesTotal(){ return pagesQty ? (parseInt(pagesQty.value)||0) * (parseFloat(C.extra_page_price)||0) : 0; }
            function getExtraPagesQty(){ return pagesQty ? (parseInt(pagesQty.value)||0) : 0; }
            function isMaintenanceChecked(){ return maintCheck ? maintCheck.checked : false; }
            function getExpressDays(){ return expressDays ? (parseInt(expressDays.value)||0) : 0; }
            function getExpressTotal(){ return getExpressDays() * (parseFloat(C.express_price)||0); }

            function calcScTotal(){
                var pack = getSelectedPack();
                var t = parseFloat(pack.price) || 0;
                var d = parseInt(pack.delay) || 0;
                scChecks.forEach(function(cb){
                    if(cb.checked){
                        t += parseFloat(cb.dataset.price)||0;
                        d += parseInt(cb.dataset.delay)||0;
                    }
                });
                t += getExtraPagesTotal();
                /* Livraison express : le délai ne peut pas descendre sous 45% du délai initial */
                var ed = getExpressDays();
                if(ed > 0){
                    var minDelay = Math.ceil(d * 0.45);
                    var maxDaysOff = d - minDelay;
                    if(ed > maxDaysOff){
                        ed = maxDaysOff;
                        if(expressDays) expressDays.value = ed;
                    }
                    t += ed * (parseFloat(C.express_price)||0);
                    d = d - ed;
                } else {
                    t += getExpressTotal();
                }
                var taxRate = parseFloat(C.tax_rate) || 0;
                var tva     = taxRate > 0 ? Math.round(t * taxRate / 100 * 100) / 100 : 0;
                var total   = Math.round((t + tva) * 100) / 100;
                if(scSubtotal) scSubtotal.textContent = t.toFixed(2).replace('.',',') + ' \u20ac';
                if(scTva && taxRate > 0) scTva.textContent = tva.toFixed(2).replace('.',',') + ' \u20ac';
                if(scTotal) scTotal.textContent = total.toFixed(2).replace('.',',') + ' \u20ac';
                if(scDelay) scDelay.textContent = d + ' ' + C.i18n.days;
            }
            scChecks.forEach(function(cb){ cb.addEventListener('change', calcScTotal); });

            function fmtPrice(p){ return parseFloat(p).toFixed(2).replace('.',',') + ' \u20ac'; }

            /* ── Send message ────────────────────────── */
            if(send && input){
                send.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); doSend(); });
                input.addEventListener('keydown', function(e){
                    if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); doSend(); }
                });
                input.addEventListener('input', function(){
                    input.style.height = 'auto';
                    input.style.height = Math.min(input.scrollHeight, 120)+'px';
                });
            }

            function doSend(){
                var msg = input.value.trim();
                if(!msg) return;
                if(C.is_admin && !selectedClientId) return;
                send.disabled = true;
                var fd = new FormData();
                fd.append('action','serviceflow_send');
                fd.append('post_id', C.post_id);
                fd.append('nonce', C.nonce);
                fd.append('message', msg);
                if(C.is_admin) fd.append('client_id', selectedClientId);

                fetch(C.ajax_url, {method:'POST', body:fd})
                .then(function(r){return r.json();})
                .then(function(res){
                    if(res.success){
                        rmEmpty();
                        res.data.is_mine = true;
                        addMsg(res.data);
                        scrollEnd();
                        input.value = '';
                        input.style.height = 'auto';
                    }
                })
                .finally(function(){ send.disabled = false; input.focus(); });
            }

            /* ── Load ────────────────────────────────── */
            function loadMsgs(){
                var params = {action:'serviceflow_load',post_id:C.post_id,nonce:C.nonce};
                if(C.is_admin && selectedClientId) params.client_id = selectedClientId;
                fetch(C.ajax_url+'?'+new URLSearchParams(params))
                .then(function(r){return r.json();})
                .then(function(res){
                    msgs.innerHTML='';
                    hasLoaded = true;
                    var msgsData = res.data && res.data.messages ? res.data.messages : (res.data || []);
                    if(!res.success||!msgsData.length){
                        msgs.innerHTML='<div class="serviceflow-empty">'+esc(C.i18n.empty)+'</div>';
                        return;
                    }
                    msgsData.forEach(function(m){addMsg(m);});
                    updatePayButtons(res.data.paid_schedule_ids||[], res.data.expired_schedule_ids||[]);
                    scrollEnd();
                })
                .catch(function(){ msgs.innerHTML='<div class="serviceflow-empty">'+esc(C.i18n.error)+'</div>'; });
            }

            /* ── Mise à jour boutons paiement dans le chat ── */
            function updatePayButtons(paidIds, expiredIds){
                if(paidIds && paidIds.length){
                    paidIds.forEach(function(id){
                        var btn = msgs.querySelector('[data-sf-sched-id="'+id+'"]');
                        if(btn){
                            var span = document.createElement('span');
                            span.style.cssText = 'display:inline-block !important;margin-top:6px !important;padding:5px 14px !important;background:#10b981 !important;color:#fff !important;border-radius:6px !important;font-size:12px !important;font-weight:600 !important';
                            span.textContent = '✅ <?php echo esc_js( __( 'Payé', 'serviceflow' ) ); ?>';
                            btn.parentNode.replaceChild(span, btn);
                        }
                    });
                }
                if(expiredIds && expiredIds.length){
                    expiredIds.forEach(function(id){
                        var btn = msgs.querySelector('[data-sf-sched-id="'+id+'"]');
                        if(btn){
                            var span = document.createElement('span');
                            span.style.cssText = 'display:inline-block !important;margin-top:6px !important;padding:5px 14px !important;background:#6b7280 !important;color:#fff !important;border-radius:6px !important;font-size:12px !important;font-weight:600 !important;cursor:default !important';
                            span.textContent = '⏱ <?php echo esc_js( __( 'Lien expiré — contactez votre prestataire', 'serviceflow' ) ); ?>';
                            btn.parentNode.replaceChild(span, btn);
                        }
                    });
                }
            }

            /* ── Poll ────────────────────────────────── */
            setInterval(function(){
                if(C.is_admin && !selectedClientId) return;
                var params = {action:'serviceflow_poll',post_id:C.post_id,last_id:lastId,nonce:C.nonce};
                if(C.is_admin && selectedClientId) params.client_id = selectedClientId;

                fetch(C.ajax_url+'?'+new URLSearchParams(params))
                .then(function(r){return r.json();})
                .then(function(res){
                    if(!res.success||!res.data) return;
                    var pollMsgs = res.data.messages || res.data;
                    var pollOrder = res.data.active_order;

                    // Sync active_order — syncCardState uniquement si le statut change
                    if(typeof pollOrder !== 'undefined'){
                        var newKey = pollOrder ? (pollOrder.id + ':' + pollOrder.status) : 'none';
                        C.active_order = pollOrder;
                        renderOrderBar();
                        if(newKey !== lastOrderStateKey){
                            lastOrderStateKey = newKey;
                            syncCardState();
                        }
                    }

                    updatePayButtons(res.data.paid_schedule_ids||[], res.data.expired_schedule_ids||[]);
                    if(!pollMsgs||!pollMsgs.length) return;
                    var isOpen = box.style.display==='flex';
                    if(!hasLoaded){
                        pollMsgs.forEach(function(m){
                            lastId=Math.max(lastId,parseInt(m.id));
                            if(parseInt(m.user_id)!==parseInt(C.user_id) && !isOpen) unread++;
                        });
                        updBadge(); return;
                    }
                    rmEmpty();
                    pollMsgs.forEach(function(m){
                        if(!document.querySelector('[data-msg-id="'+m.id+'"]')){
                            addMsg(m);
                            if(parseInt(m.user_id)!==parseInt(C.user_id)&&!isOpen) unread++;
                        }
                    });
                    updBadge();
                    if(isOpen){ scrollEnd(); markSeen(); }
                });
            }, POLL);

            /* ── Render message ──────────────────────── */
            function addMsg(m){
                var isSys = m.is_system || parseInt(m.user_id) === 0;

                if(isSys){
                    var el = document.createElement('div');
                    el.className = 'serviceflow-message serviceflow-message--system';
                    el.setAttribute('data-msg-id', m.id);
                    // Convertir les URLs en boutons cliquables (token-based: remplacer avant esc())
                    var rawMsg = m.message;
                    // Supprimer la ligne marker [SF_SCHED:X]
                    rawMsg = rawMsg.replace(/^\[SF_SCHED:\d+\]\n?/m, '');
                    var isPaid   = !!m.schedule_paid;
                    var schedId  = m.schedule_id || 0;
                    var sysHtml  = rawMsg.replace(/(https?:\/\/[^\s]+)/g, function(url){
                        return '\x00PAY_LINK:'+url+'\x00';
                    });
                    sysHtml = esc(sysHtml).replace(/\n/g,'<br>');
                    sysHtml = sysHtml.replace(/\x00PAY_LINK:(.*?)\x00/g, function(_, url){
                        if(isPaid){
                            return '<span style="display:inline-block !important;margin-top:6px !important;padding:5px 14px !important;background:#10b981 !important;color:#fff !important;border-radius:6px !important;font-size:12px !important;font-weight:600 !important">✅ <?php echo esc_js( __( 'Payé', 'serviceflow' ) ); ?></span>';
                        }
                        return '<a href="'+url+'" target="_blank" rel="noopener"'+(schedId?' data-sf-sched-id="'+schedId+'"':'')+' style="display:inline-block !important;margin-top:6px !important;padding:5px 14px !important;background:'+C.color+' !important;color:#fff !important;border-radius:6px !important;font-size:12px !important;font-weight:600 !important;text-decoration:none !important">💳 <?php echo esc_js( __( 'Payer maintenant', 'serviceflow' ) ); ?></a>';
                    });
                    el.innerHTML = '<div style="background:#f0f4ff !important;border:1px solid #d0d9e8 !important;border-radius:10px !important;padding:10px 14px !important;text-align:center !important;font-size:13px !important;line-height:1.5 !important;color:#444 !important;width:100% !important">'+sysHtml+'</div>';
                    msgs.appendChild(el);
                    lastId = Math.max(lastId, parseInt(m.id));
                    return;
                }

                var mine = m.is_mine||(parseInt(m.user_id)===parseInt(C.user_id));
                var el = document.createElement('div');
                el.className = 'serviceflow-message serviceflow-message--'+(mine?'mine':'other');
                el.setAttribute('data-msg-id', m.id);
                var bubbleStyle = mine ? 'background:'+C.color+';color:#fff;border-bottom-right-radius:4px' : '';
                el.innerHTML =
                    '<div class="serviceflow-avatar"><img src="'+esc(m.avatar)+'" alt="'+esc(m.display_name)+'" style="width:36px;height:36px;border-radius:50%;object-fit:cover"></div>'+
                    '<div class="serviceflow-bubble-wrap">'+
                        (!mine?'<div class="serviceflow-username">'+esc(m.display_name)+'</div>':'')+
                        '<div class="serviceflow-bubble" style="'+bubbleStyle+'">'+esc(m.message).replace(/\n/g,'<br>')+'</div>'+
                        '<div class="serviceflow-time">'+fmtTime(m.created_at)+'</div>'+
                    '</div>';
                msgs.appendChild(el);
                lastId = Math.max(lastId, parseInt(m.id));
            }

            /* ── Order bar ───────────────────────────── */
            function renderOrderBar(){
                if(!orderBar) return;
                var o = C.active_order;
                if(!o){ orderBar.style.display = 'none'; return; }

                var contentHtml = '';
                if(C.is_admin && Array.isArray(o)){
                    // Admin dans une conversation : filtrer par client sélectionné
                    var filtered = selectedClientId ? o.filter(function(ord){ return parseInt(ord.client_id) === selectedClientId; }) : o;
                    if(filtered.length === 0){ orderBar.style.display = 'none'; return; }
                    filtered.forEach(function(order, idx){
                        if(idx > 0) contentHtml += '<div style="border-top:1px solid #d0d9e8;margin:8px 0"></div>';
                        contentHtml += renderSingleOrder(order, true);
                    });
                } else if(!C.is_admin && o && o.id){
                    contentHtml = renderSingleOrder(o, false);
                } else {
                    orderBar.style.display = 'none';
                    return;
                }

                var arrow = sfOrderCollapsed ? '\u25b6' : '\u25bc';
                var html = '<div data-sf-toggle="order" style="cursor:pointer;display:flex;justify-content:space-between;align-items:center;padding:0 0 6px;margin-bottom:6px;border-bottom:1px solid #d0d9e8;font-size:12px;font-weight:600;color:#555;user-select:none">';
                html += '<span>' + esc(C.i18n.orders_label || 'Commande') + '</span>';
                html += '<span style="font-size:9px;color:#aaa">' + arrow + '</span></div>';
                if(!sfOrderCollapsed) html += contentHtml;

                // Sauvegarder les valeurs des inputs de délai retouche avant re-render
                var savedDelays = {};
                orderBar.querySelectorAll('[data-revision-delay-for]').forEach(function(inp){
                    var v = inp.value.trim();
                    if(v) savedDelays[inp.dataset.revisionDelayFor] = v;
                });

                orderBar.innerHTML = html;
                orderBar.style.display = 'block';

                // Restaurer les valeurs
                Object.keys(savedDelays).forEach(function(oid){
                    var inp = orderBar.querySelector('[data-revision-delay-for="'+oid+'"]');
                    if(inp) inp.value = savedDelays[oid];
                });

                var orderToggle = orderBar.querySelector('[data-sf-toggle="order"]');
                if(orderToggle){
                    orderToggle.addEventListener('click', function(e){
                        e.stopPropagation();
                        sfOrderCollapsed = !sfOrderCollapsed;
                        renderOrderBar();
                    });
                }

                if(!sfOrderCollapsed){
                    orderBar.querySelectorAll('[data-order-action]').forEach(function(b){
                        b.addEventListener('click', function(){
                            var action = b.dataset.orderAction;
                            var orderId = parseInt(b.dataset.orderId);
                            if(action === 'revision_accept'){
                                var inp = orderBar.querySelector('[data-revision-delay-for="'+orderId+'"]');
                                var delay = inp ? (parseInt(inp.value) || 0) : 0;
                                doRevisionTransition(orderId, delay);
                            } else {
                                doOrderTransition(orderId, action);
                            }
                        });
                    });
                }

                renderTodoList();
            }

            /* ── Todo list bar ───────────────────────── */
            function renderTodoList(){
                if(!todoBar || !C.is_premium) return;
                var o = C.active_order;
                var order = null;

                if(C.is_admin && Array.isArray(o)){
                    var filtered = selectedClientId ? o.filter(function(ord){ return parseInt(ord.client_id) === selectedClientId; }) : [];
                    order = filtered.length ? filtered[0] : null;
                } else if(!C.is_admin && o && o.id){
                    order = o;
                }

                if(!order || !order.todos || !order.todos.items || !order.todos.items.length){
                    todoBar.style.display = 'none';
                    return;
                }

                if(['pending','paid','accepted'].indexOf(order.status) !== -1){
                    todoBar.style.display = 'none';
                    return;
                }

                var t = order.todos;
                var pct = t.progress.percent;
                var contentHtml = '';

                // Barre de progression
                contentHtml += '<div style="margin-bottom:8px">';
                contentHtml += '<div style="display:flex;justify-content:space-between;font-size:11px;color:#666;margin-bottom:4px">';
                contentHtml += '<span>'+esc(C.i18n.progress)+'</span>';
                contentHtml += '<span>'+t.progress.completed+'/'+t.progress.total+' ('+pct+'%)</span>';
                contentHtml += '</div>';
                contentHtml += '<div style="height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden">';
                contentHtml += '<div style="height:100%;width:'+pct+'%;background:'+C.color+';border-radius:3px;transition:width .3s"></div>';
                contentHtml += '</div></div>';

                // Checklist
                t.items.forEach(function(item){
                    var checked = item.is_completed;
                    contentHtml += '<div style="display:flex;align-items:flex-start;gap:8px;padding:3px 0;font-size:12px">';

                    if(C.is_admin){
                        contentHtml += '<input type="checkbox" class="sf-todo-check" data-todo-id="'+item.id+'" data-order-id="'+order.id+'" '+(checked?'checked':'')+' style="margin-top:2px;cursor:pointer;accent-color:'+C.color+';flex-shrink:0" />';
                    } else {
                        if(checked){
                            contentHtml += '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="'+C.color+'" stroke-width="2.5" style="flex-shrink:0;margin-top:2px"><path d="M20 6L9 17l-5-5"/></svg>';
                        } else {
                            contentHtml += '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="2" style="flex-shrink:0;margin-top:2px"><circle cx="12" cy="12" r="10"/></svg>';
                        }
                    }

                    contentHtml += '<div style="flex:1;min-width:0">';
                    contentHtml += '<span style="'+(checked?'text-decoration:line-through;color:#999':'color:#333')+'">'+esc(item.label)+'</span>';
                    if(item.source === 'option') contentHtml += ' <span style="font-size:10px;color:#aaa;font-style:italic">(option)</span>';
                    if(item.admin_note){
                        contentHtml += '<div style="font-size:11px;color:#888;margin-top:1px">'+esc(C.i18n.todo_note)+' : '+esc(item.admin_note)+'</div>';
                    }
                    contentHtml += '</div></div>';
                });

                var arrow = sfTodoCollapsed ? '\u25b6' : '\u25bc';
                var html = '<div data-sf-toggle="todo" style="cursor:pointer;display:flex;justify-content:space-between;align-items:center;padding:0 0 6px;margin-bottom:6px;border-bottom:1px solid #e2e8f0;font-size:12px;font-weight:600;color:#555;user-select:none">';
                html += '<span>' + esc(C.i18n.todos_label || 'Tâches') + ' ('+t.progress.completed+'/'+t.progress.total+')</span>';
                html += '<span style="font-size:9px;color:#aaa">' + arrow + '</span></div>';
                if(!sfTodoCollapsed) html += contentHtml;

                todoBar.innerHTML = html;
                todoBar.style.display = 'block';

                var todoToggle = todoBar.querySelector('[data-sf-toggle="todo"]');
                if(todoToggle){
                    todoToggle.addEventListener('click', function(e){
                        e.stopPropagation();
                        sfTodoCollapsed = !sfTodoCollapsed;
                        renderTodoList();
                    });
                }

                // Admin : handlers checkbox
                if(!sfTodoCollapsed && C.is_admin){
                    todoBar.querySelectorAll('.sf-todo-check').forEach(function(cb){
                        cb.addEventListener('change', function(){
                            var todoId = parseInt(cb.dataset.todoId);
                            var orderId = parseInt(cb.dataset.orderId);
                            var isChecked = cb.checked;
                            var note = '';
                            if(isChecked){
                                note = prompt(C.i18n.add_note) || '';
                            }
                            doToggleTodo(orderId, todoId, isChecked, note);
                        });
                    });
                }
            }

            function doToggleTodo(orderId, todoId, completed, note){
                var fd = new FormData();
                fd.append('action', 'serviceflow_toggle_todo');
                fd.append('nonce', C.nonce);
                fd.append('order_id', orderId);
                fd.append('todo_id', todoId);
                fd.append('completed', completed ? '1' : '0');
                fd.append('note', note);

                fetch(C.ajax_url, {method:'POST', body:fd})
                .then(function(r){return r.json();})
                .then(function(res){
                    if(res.success && res.data.todos){
                        // Mettre à jour les todos dans active_order
                        var o = C.active_order;
                        if(C.is_admin && Array.isArray(o)){
                            o.forEach(function(ord){
                                if(ord.id === orderId){
                                    ord.todos = res.data.todos;
                                    if(res.data.order_completed) ord.status = 'completed';
                                }
                            });
                        } else if(o && o.id === orderId){
                            o.todos = res.data.todos;
                            if(res.data.order_completed) o.status = 'completed';
                        }
                        if(res.data.order_completed){
                            renderOrderBar();
                        } else {
                            renderTodoList();
                        }
                    }
                });
            }

            function truncate(str, max){
                if(!str) return '';
                return str.length > max ? str.substring(0, max) + '\u2026' : str;
            }

            function fmtDate(d){
                if(!d) return '';
                var p = d.split('-');
                if(p.length === 3) return p[2]+'/'+p[1]+'/'+p[0];
                return d;
            }

            function renderSingleOrder(order, isAdmin){
                var sLabels = {'pending':C.i18n.status_pending,'paid':C.i18n.status_paid,'started':C.i18n.status_started,'completed':C.i18n.status_completed,'revision':C.i18n.status_revision,'accepted':C.i18n.status_accepted};
                var sColors = {'pending':'#f59e0b','paid':'#8b5cf6','started':'#3b82f6','completed':'#10b981','revision':'#ef4444','accepted':'#6b7280'};
                var sL = sLabels[order.status] || order.status;
                var sC = sColors[order.status] || '#888';
                var orderNum = order.order_number || ('#CMD-'+order.id);

                var html = '';
                if(isAdmin){
                    var cl = order.client_name ? esc(order.client_name) : '?';
                    var sv = truncate(C.post_title, 30);
                    html += '<div style="font-size:12px;color:#666;margin-bottom:4px"><strong>'+cl+'</strong> &mdash; <span style="color:#999;font-weight:600">'+esc(orderNum)+'</span></div>';
                } else {
                    html += '<div style="font-size:12px;color:#666;margin-bottom:6px;font-weight:600">'+esc(orderNum)+'</div>';
                }

                html += '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">';
                html += '<div style="display:flex;align-items:center;gap:6px">';
                html += '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;color:#fff;background:'+sC+'">'+esc(sL)+'</span>';
                if(order.estimated_date && (order.status==='started'||order.status==='revision')){
                    html += '<span style="font-size:11px;color:#666">'+esc(C.i18n.estimated)+' : '+fmtDate(order.estimated_date)+'</span>';
                }
                html += '</div><div style="display:flex;gap:4px">';

                if(isAdmin){
                    if(order.status==='pending'||order.status==='paid'){
                        html += makeBtn(order.id,'started',C.i18n.start_order,'#3b82f6');
                    } else if(order.status==='revision'){
                        html += '<input type="number" data-revision-delay-for="'+order.id+'" min="1" max="365" placeholder="'+esc(C.i18n.revision_delay_placeholder)+'" style="width:90px;padding:3px 6px;border:1px solid #d1d5db;border-radius:6px;font-size:11px;font-family:inherit" />';
                        html += '<button data-order-id="'+order.id+'" data-order-action="revision_accept" style="padding:4px 10px;border:none;border-radius:6px;font-size:11px;font-weight:600;color:#fff;background:#3b82f6;cursor:pointer">'+esc(C.i18n.validate_revision)+'</button>';
                    } else if(order.status==='started'){
                        html += makeBtn(order.id,'completed',C.i18n.complete_order,'#10b981');
                    }
                } else {
                    if(order.status==='completed'){
                        html += makeBtn(order.id,'accepted',C.i18n.accept_delivery,'#10b981');
                        html += makeBtn(order.id,'revision',C.i18n.request_revision,'#ef4444');
                    }
                }
                html += '</div></div>';
                return html;
            }

            function makeBtn(id,action,label,color){
                return '<button data-order-id="'+id+'" data-order-action="'+action+'" style="padding:4px 10px;border:none;border-radius:6px;font-size:11px;font-weight:600;color:#fff;background:'+color+';cursor:pointer">'+esc(label)+'</button>';
            }

            function doOrderTransition(orderId, newStatus, extraData){
                var fd = new FormData();
                fd.append('action', 'serviceflow_order_transition');
                fd.append('nonce', C.nonce);
                fd.append('order_id', orderId);
                fd.append('new_status', newStatus);
                fd.append('post_id', C.post_id);
                if(extraData) Object.keys(extraData).forEach(function(k){ fd.append(k, extraData[k]); });

                fetch(C.ajax_url, {method:'POST', body:fd})
                .then(function(r){return r.json();})
                .then(function(res){
                    if(res.success && res.data){
                        C.active_order = res.data.active_order;
                        renderOrderBar();
                        syncCardState();
                        loadMsgs();
                    }
                });
            }

            function doRevisionTransition(orderId, revisionDelay){
                doOrderTransition(orderId, 'started', revisionDelay > 0 ? {revision_delay: revisionDelay} : null);
            }

            /* ── Badge ───────────────────────────────── */
            function updBadge(){
                if(!badge) return;
                var isOpen = box.style.display==='flex';
                if(unread>0 && !isOpen){
                    badge.textContent = unread>9?'9+':unread;
                    badge.style.display = 'flex';
                } else { badge.style.display = 'none'; unread = 0; }
            }

            /* ── Helpers ─────────────────────────────── */
            function scrollEnd(){ msgs.scrollTop = msgs.scrollHeight; }
            function rmEmpty(){ var e=msgs.querySelector('.serviceflow-empty'); if(e)e.remove(); }
            function fmtTime(s){
                if(!s)return'';
                var d=new Date(s.replace(' ','T'));
                return d.getHours().toString().padStart(2,'0')+':'+d.getMinutes().toString().padStart(2,'0');
            }
            function esc(s){
                if(!s)return'';
                var d=document.createElement('div'); d.textContent=s; return d.innerHTML;
            }
        })();
        </script>
        <?php
    }

    private static function is_chat_page(): bool {
        return is_singular( ServiceFlow_Admin::get_post_type() );
    }
}
