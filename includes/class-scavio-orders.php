<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Scavio_Orders {

    const STATUS_PENDING   = 'pending';
    const STATUS_PAID      = 'paid';
    const STATUS_STARTED   = 'started';
    const STATUS_COMPLETED = 'completed';
    const STATUS_REVISION  = 'revision';
    const STATUS_ACCEPTED  = 'accepted';

    private static array $transitions = [
        'pending'   => [ 'started' ],
        'paid'      => [ 'started' ],
        'started'   => [ 'completed' ],
        'completed' => [ 'revision', 'accepted' ],
        'revision'  => [ 'started' ],
    ];

    public static function init(): void {
        add_action( 'wp_ajax_scavio_create_order',     [ __CLASS__, 'ajax_create_order' ] );
        add_action( 'wp_ajax_scavio_order_transition',  [ __CLASS__, 'ajax_order_transition' ] );
        add_action( 'wp_ajax_scavio_get_clients',       [ __CLASS__, 'ajax_get_clients' ] );
    }

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'scavio_orders';
    }

    public static function create_table(): void {
        global $wpdb;

        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id         BIGINT UNSIGNED NOT NULL,
            client_id       BIGINT UNSIGNED NOT NULL,
            status          VARCHAR(20)     NOT NULL DEFAULT 'pending',
            base_offer      TEXT            NOT NULL,
            selected_options TEXT           NOT NULL,
            total_price     DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            total_delay     INT UNSIGNED    NOT NULL DEFAULT 0,
            estimated_date  DATE            DEFAULT NULL,
            stripe_session_id     VARCHAR(255) DEFAULT NULL,
            stripe_payment_intent VARCHAR(255) DEFAULT NULL,
            payment_mode    VARCHAR(20)     NOT NULL DEFAULT 'single',
            deposit_percent TINYINT UNSIGNED NOT NULL DEFAULT 50,
            installments_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
            extra_pages     TINYINT UNSIGNED NOT NULL DEFAULT 0,
            extra_page_price DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
            maintenance_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            express_days    TINYINT UNSIGNED NOT NULL DEFAULT 0,
            express_price         DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            advanced_options_data LONGTEXT,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_client (post_id, client_id),
            KEY status (status)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Crée une commande ou met à jour une commande en attente existante.
     * Si la commande existante est « pending », elle est mise à jour (modification de sélection).
     * Sinon (accepted, completed, etc.), une nouvelle commande est créée.
     */
    public static function create_order( int $post_id, int $client_id, array $base_offer, array $selected_options, float $total_price, int $total_delay, string $advanced_options_data = '' ): int|false {
        global $wpdb;

        $table    = self::table_name();
        $existing = self::get_order_for_client( $post_id, $client_id );

        $data = [
            'post_id'               => $post_id,
            'client_id'             => $client_id,
            'status'                => self::STATUS_PENDING,
            'base_offer'            => wp_json_encode( $base_offer ),
            'selected_options'      => wp_json_encode( $selected_options ),
            'total_price'           => $total_price,
            'total_delay'           => $total_delay,
            'estimated_date'        => null,
            'advanced_options_data' => $advanced_options_data ?: null,
            'updated_at'            => current_time( 'mysql' ),
        ];

        // Seule une commande « en attente » peut être modifiée (mise à jour).
        // Les autres statuts (accepted, etc.) entraînent une nouvelle commande.
        if ( $existing && $existing->status === self::STATUS_PENDING ) {
            $wpdb->update( $table, $data, [ 'id' => $existing->id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            return (int) $existing->id;
        }

        $data['created_at'] = current_time( 'mysql' );
        $inserted = $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    /**
     * Crée une commande avec statut « paid » (via Stripe).
     */
    public static function create_order_paid( int $post_id, int $client_id, array $base_offer, array $selected_options, float $total_price, int $total_delay, string $stripe_session_id, string $stripe_payment_intent, array $payment_context = [], int $extra_pages = 0, float $extra_page_price = 0.0, float $maintenance_price = 0.0, int $express_days = 0, float $express_price = 0.0, string $advanced_options_data = '' ): int|false {
        global $wpdb;

        $table    = self::table_name();
        $existing = self::get_order_for_client( $post_id, $client_id );

        $data = [
            'post_id'               => $post_id,
            'client_id'             => $client_id,
            'status'                => self::STATUS_STARTED,
            'base_offer'            => wp_json_encode( $base_offer ),
            'selected_options'      => wp_json_encode( $selected_options ),
            'total_price'           => $total_price,
            'total_delay'           => $total_delay,
            'estimated_date'        => gmdate( 'Y-m-d', strtotime( '+' . $total_delay . ' days' ) ),
            'stripe_session_id'     => $stripe_session_id,
            'stripe_payment_intent' => $stripe_payment_intent,
            'payment_mode'          => $payment_context['payment_mode'] ?? 'single',
            'deposit_percent'       => $payment_context['deposit_percent'] ?? 50,
            'installments_count'    => $payment_context['installments_count'] ?? 0,
            'extra_pages'           => $extra_pages,
            'extra_page_price'      => $extra_page_price,
            'maintenance_price'     => $maintenance_price,
            'express_days'          => $express_days,
            'express_price'         => $express_price,
            'advanced_options_data' => $advanced_options_data ?: null,
            'updated_at'            => current_time( 'mysql' ),
        ];

        if ( $existing && $existing->status === self::STATUS_PENDING ) {
            $wpdb->update( $table, $data, [ 'id' => $existing->id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            return (int) $existing->id;
        }

        $data['created_at'] = current_time( 'mysql' );
        $inserted = $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    /**
     * Récupère une commande par ID.
     */
    public static function get_order( int $order_id ): ?object {
        global $wpdb;

        $table = self::table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row   = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT o.*, u.display_name AS client_name
             FROM {$table} o
             LEFT JOIN {$wpdb->users} u ON o.client_id = u.ID
             WHERE o.id = %d",
            $order_id
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $row ?: null;
    }

    /**
     * Commande la plus récente d'un client sur un post.
     */
    public static function get_order_for_client( int $post_id, int $client_id ): ?object {
        global $wpdb;

        $table = self::table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row   = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT o.*, u.display_name AS client_name
             FROM {$table} o
             LEFT JOIN {$wpdb->users} u ON o.client_id = u.ID
             WHERE o.post_id = %d AND o.client_id = %d
             ORDER BY o.created_at DESC LIMIT 1",
            $post_id,
            $client_id
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $row ?: null;
    }

    /**
     * Alias pour compatibilité.
     */
    public static function get_active_order_for_client( int $post_id, int $client_id ): ?object {
        return self::get_order_for_client( $post_id, $client_id );
    }

    /**
     * Toutes les commandes d'un post (pour l'admin).
     */
    public static function get_active_orders_for_post( int $post_id ): array {
        global $wpdb;

        $table = self::table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT o.*, u.display_name AS client_name
             FROM {$table} o
             LEFT JOIN {$wpdb->users} u ON o.client_id = u.ID
             WHERE o.post_id = %d
             ORDER BY o.created_at DESC",
            $post_id
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Toutes les commandes (admin — tous clients).
     */
    public static function get_all_orders(): array {
        global $wpdb;
        $table = self::table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT o.*, p.post_title AS service_name
             FROM {$table} o
             LEFT JOIN {$wpdb->posts} p ON o.post_id = p.ID
             ORDER BY o.created_at DESC"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Toutes les commandes d'un client (tous posts confondus).
     */
    public static function get_all_orders_for_client( int $client_id, string $status = '' ): array {
        global $wpdb;

        $table = self::table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql   = $wpdb->prepare(
            "SELECT o.*, p.post_title AS service_name
             FROM {$table} o
             LEFT JOIN {$wpdb->posts} p ON o.post_id = p.ID
             WHERE o.client_id = %d",
            $client_id
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $valid = [ 'pending', 'paid', 'started', 'completed', 'revision', 'accepted' ];
        if ( $status !== '' && in_array( $status, $valid, true ) ) {
            $sql .= $wpdb->prepare( ' AND o.status = %s', $status );
        }

        $sql .= ' ORDER BY o.created_at DESC';

        return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query built with wpdb::prepare() above.
    }

    /**
     * Liste des clients distincts ayant une conversation sur un post.
     */
    public static function get_clients_for_post( int $post_id ): array {
        global $wpdb;

        $msg_table   = Scavio_DB::table_name();
        $order_table = self::table_name();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT DISTINCT u.ID AS client_id, u.display_name, u.user_email
             FROM {$wpdb->users} u
             WHERE u.ID IN (
                 SELECT DISTINCT m.client_id FROM {$msg_table} m
                 WHERE m.post_id = %d AND m.client_id > 0
                 UNION
                 SELECT DISTINCT o.client_id FROM {$order_table} o
                 WHERE o.post_id = %d
             )
             ORDER BY u.display_name ASC",
            $post_id,
            $post_id
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $results = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query built with wpdb::prepare() above.

        // Exclure les administrateurs
        return array_values( array_filter( $results, function ( $user ) {
            return ! user_can( (int) $user->client_id, 'manage_options' );
        } ) );
    }

    /**
     * Effectue une transition de statut.
     */
    public static function transition_status( int $order_id, string $new_status, int $acting_user_id, int $revision_delay = 0 ): bool {
        $order = self::get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        if ( ! self::can_transition( $order, $new_status, $acting_user_id ) ) {
            return false;
        }

        $old_status = $order->status;

        global $wpdb;
        $table = self::table_name();

        $update_data = [
            'status'     => $new_status,
            'updated_at' => current_time( 'mysql' ),
        ];

        // Date estimée : premier démarrage (pending/paid → started)
        if ( $new_status === self::STATUS_STARTED && in_array( $old_status, [ self::STATUS_PENDING, self::STATUS_PAID ], true ) ) {
            $update_data['estimated_date'] = gmdate( 'Y-m-d', strtotime( '+' . (int) $order->total_delay . ' days' ) );
        }

        // Retouche acceptée (revision → started) : cumul du délai de retouche au délai total
        if ( $new_status === self::STATUS_STARTED && $old_status === self::STATUS_REVISION ) {
            if ( $revision_delay > 0 ) {
                $update_data['estimated_date'] = gmdate( 'Y-m-d', strtotime( '+' . $revision_delay . ' days' ) );
                $update_data['total_delay']    = (int) $order->total_delay + $revision_delay;
            } else {
                // Pas de délai précisé : retouche immédiate, date = aujourd'hui
                $update_data['estimated_date'] = gmdate( 'Y-m-d' );
            }
        }

        $wpdb->update( $table, $update_data, [ 'id' => $order_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        // Recharger pour avoir estimated_date à jour
        $order = self::get_order( $order_id );

        // Poster un message système dans le chat, scopé au client
        $actor      = get_userdata( $acting_user_id );
        $actor_name = $actor ? $actor->display_name : __( 'Utilisateur', 'scavio' );
        $message    = self::format_status_message( $new_status, $order, $actor_name, $old_status );

        if ( ! empty( $message ) ) {
            Scavio_DB::insert_message( (int) $order->post_id, 0, $message, (int) $order->client_id );
        }

        // Déclencher la notification
        do_action( 'scavio_order_status_changed', $order_id, $new_status, $old_status, $acting_user_id );

        return true;
    }

    /**
     * Vérifie si une transition est autorisée.
     */
    public static function can_transition( object $order, string $new_status, int $acting_user_id ): bool {
        $current = $order->status;

        if ( ! isset( self::$transitions[ $current ] ) ) {
            return false;
        }

        if ( ! in_array( $new_status, self::$transitions[ $current ], true ) ) {
            return false;
        }

        // Qui peut faire quoi ?
        if ( $new_status === self::STATUS_REVISION || $new_status === self::STATUS_ACCEPTED ) {
            // Seul le client auteur de la commande
            return (int) $order->client_id === $acting_user_id;
        }

        // started, completed : admin uniquement
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        // Passage à "terminé" : toutes les tâches doivent être complétées (blocage manuel uniquement)
        // L'auto-complétion depuis ajax_toggle_todo() est exemptée car elle vérifie elle-même le 100%.
        if ( $new_status === self::STATUS_COMPLETED && class_exists( 'Scavio_Todos' ) ) {
            $progress = Scavio_Todos::get_progress( (int) $order->id );
            if ( $progress['total'] > 0 && $progress['percent'] < 100 ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Génère le message système pour un changement de statut.
     */
    public static function format_status_message( string $new_status, object $order, string $actor_name, string $old_status = '' ): string {
        $order_num = '#CMD-' . (int) $order->id;

        switch ( $new_status ) {
            case self::STATUS_PAID:
                return sprintf(
                    "--- %s %s ---\n%s",
                    $order_num,
                    __( 'Paiement reçu', 'scavio' ),
                    sprintf(
                        /* translators: %s: payment amount with currency symbol */
                        __( 'Le paiement de %s a été reçu via Stripe. Commande confirmée.', 'scavio' ),
                        number_format( (float) $order->total_price, 2, ',', ' ' ) . ' €'
                    )
                );

            case self::STATUS_STARTED:
                if ( $order->estimated_date ) {
                    $date = date_i18n( 'd/m/Y', strtotime( $order->estimated_date ) );
                }

                if ( isset( $date ) && $order->total_delay > 0 ) {
                    $delay_info = sprintf(
                        /* translators: %1$d: number of days, %2$s: estimated delivery date */
                        __( "Délai total : %1\$d jour(s)\nLivraison estimée : %2\$s", 'scavio' ),
                        (int) $order->total_delay,
                        $date
                    );
                } else {
                    $delay_info = '';
                }

                // Distinguer premier démarrage et reprise après retouche
                if ( $old_status === self::STATUS_REVISION ) {
                    return sprintf(
                        "--- %s %s ---\n%s",
                        $order_num,
                        __( 'Retouche validée', 'scavio' ),
                        sprintf(
                            /* translators: %s: actor display name */
                            __( '%s a validé la retouche et repris la commande.', 'scavio' ),
                            $actor_name
                        )
                    ) . ( $delay_info ? "\n" . $delay_info : '' );
                }

                // Paiement Stripe automatique (pas d'acteur)
                if ( empty( $actor_name ) ) {
                    $total        = (float) $order->total_price;
                    $payment_mode = $order->payment_mode ?? 'single';
                    $n_months     = max( 1, (int) ( $order->installments_count ?? 1 ) );

                    // Calcul de l'upfront selon le mode
                    if ( $payment_mode === 'monthly' ) {
                        $upfront = round( $total / $n_months, 2 );
                    } else {
                        $ratio   = match ( $payment_mode ) {
                            'deposit'      => 0.50,
                            'installments' => 0.40,
                            default        => 1.0,
                        };
                        $upfront = round( $total * $ratio, 2 );
                    }

                    if ( $payment_mode === 'single' ) {
                        $payment_line = sprintf(
                            /* translators: %s: payment amount with currency symbol */
                            __( 'Le paiement de %s a été reçu via Stripe. Commande démarrée.', 'scavio' ),
                            number_format( $upfront, 2, ',', ' ' ) . ' €'
                        );
                    } elseif ( $payment_mode === 'monthly' ) {
                        $payment_line = sprintf(
                            /* translators: %1$d: total number of months, %2$s: amount received */
                            __( 'Mois 1 / %1$d — %2$s reçu via Stripe. Commande démarrée.', 'scavio' ),
                            $n_months,
                            number_format( $upfront, 2, ',', ' ' ) . ' €'
                        ) . "\n" . sprintf(
                            /* translators: %1$d: number of months, %2$s: total amount */
                            __( 'Total abonnement (%1$d mois) : %2$s', 'scavio' ),
                            $n_months,
                            number_format( $total, 2, ',', ' ' ) . ' €'
                        );
                    } else {
                        $label = $payment_mode === 'deposit'
                            ? __( 'Acompte (50%)', 'scavio' )
                            : __( 'Premier versement (40%)', 'scavio' );
                        $payment_line = sprintf(
                            /* translators: %1$s: payment label (e.g. "Acompte"), %2$s: amount received */
                            __( '%1$s de %2$s reçu via Stripe. Commande démarrée.', 'scavio' ),
                            $label,
                            number_format( $upfront, 2, ',', ' ' ) . ' €'
                        ) . "\n" . sprintf(
                            /* translators: %s: total contract amount with currency symbol */
                            __( 'Total du contrat : %s', 'scavio' ),
                            number_format( $total, 2, ',', ' ' ) . ' €'
                        );
                    }

                    return sprintf(
                        "--- %s %s ---\n%s",
                        $order_num,
                        __( 'Paiement reçu', 'scavio' ),
                        $payment_line
                    ) . ( $delay_info ? "\n" . $delay_info : '' );
                }

                return sprintf(
                    "--- %s %s ---\n%s",
                    $order_num,
                    __( 'Commande démarrée', 'scavio' ),
                    sprintf(
                        /* translators: %s: actor display name */
                        __( '%s a démarré la commande.', 'scavio' ),
                        $actor_name
                    )
                ) . ( $delay_info ? "\n" . $delay_info : '' );

            case self::STATUS_COMPLETED:
                return sprintf(
                    "--- %s %s ---\n%s",
                    $order_num,
                    __( 'Commande terminée', 'scavio' ),
                    sprintf(
                        /* translators: %s: actor display name */
                        __( '%s a marqué la commande comme terminée.', 'scavio' ),
                        $actor_name
                    )
                );

            case self::STATUS_REVISION:
                return sprintf(
                    "--- %s %s ---\n%s",
                    $order_num,
                    __( 'Retouche demandée', 'scavio' ),
                    sprintf(
                        /* translators: %s: actor display name */
                        __( '%s a demandé une retouche.', 'scavio' ),
                        $actor_name
                    )
                );

            case self::STATUS_ACCEPTED:
                return sprintf(
                    "--- %s %s ---\n%s",
                    $order_num,
                    __( 'Livraison acceptée', 'scavio' ),
                    sprintf(
                        /* translators: %s: actor display name */
                        __( '%s a accepté la livraison. Commande terminée.', 'scavio' ),
                        $actor_name
                    )
                );

            default:
                return '';
        }
    }

    /**
     * AJAX : Créer une commande.
     */
    public static function ajax_create_order(): void {
        check_ajax_referer( 'scavio_nonce', 'nonce' );

        // Si Stripe est activé, les commandes doivent passer par Stripe Checkout
        if ( Scavio_Stripe::is_enabled() ) {
            wp_send_json_error( [ 'message' => __( 'Le paiement en ligne est requis.', 'scavio' ), 'stripe_required' => true ], 400 );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Non connecté.', 'scavio' ) ], 403 );
        }

        // Les administrateurs ne peuvent pas commander
        if ( current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Les administrateurs ne peuvent pas commander.', 'scavio' ) ], 403 );
        }

        $post_id           = absint( $_POST['post_id'] ?? 0 );
        $selected_pack_idx = absint( $_POST['selected_pack'] ?? 0 );
        $selected_indices  = array_map( 'absint', (array) json_decode( sanitize_text_field( wp_unslash( $_POST['selected_indices'] ?? '[]' ) ), true ) );

        if ( ! $post_id || ! is_array( $selected_indices ) ) {
            wp_send_json_error( [ 'message' => __( 'Données manquantes.', 'scavio' ) ], 400 );
        }

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== Scavio_Admin::get_post_type() ) {
            wp_send_json_error( [ 'message' => __( 'Post invalide.', 'scavio' ) ], 400 );
        }

        $client_id = get_current_user_id();

        // Vérifier que la commande existante est modifiable (pending ou accepted = nouvelle commande)
        $existing = self::get_order_for_client( $post_id, $client_id );
        if ( $existing && $existing->status !== self::STATUS_PENDING && $existing->status !== self::STATUS_ACCEPTED ) {
            wp_send_json_error( [ 'message' => __( 'La commande ne peut plus être modifiée.', 'scavio' ) ], 403 );
        }

        // Récupérer le pack sélectionné
        $packs    = Scavio_Options::get_packs( $post_id );
        $all_opts = Scavio_Options::get_options( $post_id );

        if ( empty( $packs ) || ! isset( $packs[ $selected_pack_idx ] ) ) {
            wp_send_json_error( [ 'message' => __( 'Pack invalide.', 'scavio' ) ], 400 );
        }

        $base_offer = $packs[ $selected_pack_idx ];

        $selected = [];
        foreach ( $selected_indices as $idx ) {
            $idx = absint( $idx );
            if ( isset( $all_opts[ $idx ] ) ) {
                $selected[] = $all_opts[ $idx ];
            }
        }

        $total_price = floatval( $base_offer['price'] ?? 0 );
        $total_delay = absint( $base_offer['delay'] ?? 0 );
        foreach ( $selected as $opt ) {
            $total_price += floatval( $opt['price'] ?? 0 );
            $total_delay += absint( $opt['delay'] ?? 0 );
        }

        // Options avancées dynamiques
        $adv_opts_raw  = get_post_meta( $post_id, '_scavio_advanced_options', true );
        $adv_opts_cfg  = $adv_opts_raw ? json_decode( $adv_opts_raw, true ) : [];
        $adv_sels_raw  = isset( $_POST['advanced_options_data'] ) ? sanitize_text_field( wp_unslash( $_POST['advanced_options_data'] ) ) : '[]';
        $adv_sels      = json_decode( $adv_sels_raw, true );
        $adv_sels      = is_array( $adv_sels ) ? $adv_sels : [];
        $adv_order_data = [];

        foreach ( $adv_sels as $sel ) {
            $idx  = absint( $sel['index'] ?? -1 );
            $qty  = absint( $sel['qty'] ?? 0 );
            if ( $idx < 0 || ! isset( $adv_opts_cfg[ $idx ] ) || $qty <= 0 ) {
                continue;
            }
            $cfg   = $adv_opts_cfg[ $idx ];
            $mode  = $cfg['mode'] ?? 'unit';
            $price = floatval( $cfg['price'] ?? 0 );
            $total = round( $qty * $price, 2 );

            if ( $mode === 'daily' ) {
                // Livraison express : délai ne peut pas descendre sous 45% du délai initial
                $min_delay    = (int) ceil( $total_delay * 0.45 );
                $max_days_off = $total_delay - $min_delay;
                $qty          = min( $qty, $max_days_off );
                $total        = round( $qty * $price, 2 );
                $total_delay -= $qty;
            }

            $total_price += $total;
            $adv_order_data[] = [
                'index'      => $idx,
                'label'      => sanitize_text_field( $cfg['label'] ?? '' ),
                'price'      => $price,
                'mode'       => $mode,
                'unit_label' => sanitize_text_field( $cfg['unit_label'] ?? '' ),
                'qty'        => $qty,
                'total'      => $total,
            ];
        }

        $adv_order_json = wp_json_encode( $adv_order_data );

        // Appliquer la TVA pour stocker le total TTC (premium uniquement)
        $tax_rate    = scavio_is_premium() ? floatval( Scavio_Invoices::get_settings()['tax_rate'] ?? 0 ) : 0;
        $total_price = round( $total_price * ( 1 + $tax_rate / 100 ), 2 );

        $order_id = self::create_order( $post_id, $client_id, $base_offer, $selected, $total_price, $total_delay, $adv_order_json );

        if ( ! $order_id ) {
            wp_send_json_error( [ 'message' => __( 'Erreur création commande.', 'scavio' ) ], 500 );
        }

        // Déclencher la notification
        do_action( 'scavio_order_created', $order_id, $post_id, $client_id );

        $active_order = self::build_order_response( $post_id );
        wp_send_json_success( [ 'order_id' => $order_id, 'active_order' => $active_order ] );
    }

    /**
     * AJAX : Transition de statut.
     */
    public static function ajax_order_transition(): void {
        check_ajax_referer( 'scavio_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Non connecté.', 'scavio' ) ], 403 );
        }

        $order_id   = absint( $_POST['order_id'] ?? 0 );
        $new_status = sanitize_text_field( wp_unslash( $_POST['new_status'] ?? '' ) );
        $post_id    = absint( $_POST['post_id'] ?? 0 );

        if ( ! $order_id || ! $new_status || ! $post_id ) {
            wp_send_json_error( [ 'message' => __( 'Données manquantes.', 'scavio' ) ], 400 );
        }

        $user_id        = get_current_user_id();
        $revision_delay = absint( $_POST['revision_delay'] ?? 0 );

        // Vérification préalable : tâches incomplètes bloquent le passage à "terminé"
        if ( $new_status === self::STATUS_COMPLETED && class_exists( 'Scavio_Todos' ) ) {
            $progress = Scavio_Todos::get_progress( $order_id );
            if ( $progress['total'] > 0 && $progress['percent'] < 100 ) {
                wp_send_json_error( [
                    'message' => sprintf(
                        /* translators: %d: todo completion percentage */
                        __( 'Impossible de terminer la commande : les tâches ne sont complétées qu\'à %d%%.', 'scavio' ),
                        $progress['percent']
                    ),
                ], 403 );
            }
        }

        $result = self::transition_status( $order_id, $new_status, $user_id, $revision_delay );

        if ( ! $result ) {
            wp_send_json_error( [ 'message' => __( 'Transition non autorisée.', 'scavio' ) ], 403 );
        }

        $active_order = self::build_order_response( $post_id );
        wp_send_json_success( [ 'active_order' => $active_order ] );
    }

    /**
     * AJAX : Liste des clients pour un post (admin uniquement).
     */
    public static function ajax_get_clients(): void {
        check_ajax_referer( 'scavio_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [], 403 );
        }

        $post_id = absint( $_GET['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( [], 400 );
        }

        $clients = self::get_clients_for_post( $post_id );

        $data = array_map( function ( $c ) use ( $post_id ) {
            $order = self::get_order_for_client( $post_id, (int) $c->client_id );
            return [
                'client_id'    => (int) $c->client_id,
                'display_name' => $c->display_name,
                'avatar'       => get_avatar_url( $c->client_id, [ 'size' => 40 ] ),
                'has_order'    => $order !== null,
                'order_status' => $order ? $order->status : null,
                'order_id'     => $order ? (int) $order->id : null,
            ];
        }, $clients );

        wp_send_json_success( $data );
    }

    /**
     * Construit la réponse order pour le JS (selon le rôle de l'utilisateur courant).
     */
    public static function build_order_response( int $post_id ): mixed {
        $is_premium = function_exists( 'scavio_is_premium' ) && scavio_is_premium();

        if ( current_user_can( 'manage_options' ) ) {
            $orders = self::get_active_orders_for_post( $post_id );
            if ( empty( $orders ) ) {
                return null;
            }
            return array_values( array_map( function ( $o ) use ( $is_premium ) {
                return [
                    'id'             => (int) $o->id,
                    'order_number'   => '#CMD-' . (int) $o->id,
                    'client_id'      => (int) $o->client_id,
                    'client_name'    => $o->client_name ?? '',
                    'status'         => $o->status,
                    'total_price'    => (float) $o->total_price,
                    'total_delay'    => (int) $o->total_delay,
                    'estimated_date' => $o->estimated_date,
                    'created_at'     => $o->created_at,
                    'todos'          => $is_premium ? Scavio_Todos::build_todos_response( (int) $o->id ) : null,
                ];
            }, $orders ) );
        }

        $order = self::get_order_for_client( $post_id, get_current_user_id() );
        if ( ! $order ) {
            return null;
        }
        return [
            'id'             => (int) $order->id,
            'order_number'   => '#CMD-' . (int) $order->id,
            'client_id'      => (int) $order->client_id,
            'status'         => $order->status,
            'total_price'    => (float) $order->total_price,
            'total_delay'    => (int) $order->total_delay,
            'estimated_date' => $order->estimated_date,
            'created_at'     => $order->created_at,
            'todos'          => $is_premium ? Scavio_Todos::build_todos_response( (int) $order->id ) : null,
        ];
    }
}
