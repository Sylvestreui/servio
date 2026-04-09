<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Servio_Todos {

    public static function init(): void {
        if ( ! servio_is_premium() ) {
            return;
        }

        add_action( 'wp_ajax_servio_toggle_todo', [ __CLASS__, 'ajax_toggle_todo' ] );
        add_action( 'wp_ajax_servio_get_todos',   [ __CLASS__, 'ajax_get_todos' ] );

        add_action( 'servio_order_status_changed', [ __CLASS__, 'on_order_status_changed' ], 10, 4 );
    }

    /* ================================================================
     *  TABLE
     * ================================================================ */

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'servio_todos';
    }

    public static function create_table(): void {
        global $wpdb;

        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id        BIGINT UNSIGNED NOT NULL,
            label           VARCHAR(255)    NOT NULL,
            source          VARCHAR(20)     NOT NULL DEFAULT 'feature',
            position        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            is_completed    TINYINT(1)      NOT NULL DEFAULT 0,
            admin_note      TEXT            DEFAULT NULL,
            completed_at    DATETIME        DEFAULT NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /* ================================================================
     *  AUTO-GÉNÉRATION
     * ================================================================ */

    public static function on_order_status_changed( int $order_id, string $new_status, string $old_status, int $acting_user_id ): void {
        if ( ! servio_is_premium() ) {
            return;
        }

        if ( $new_status === 'started' && in_array( $old_status, [ 'pending', 'paid', '' ], true ) ) {
            self::generate_todos_for_order( $order_id );
        }
    }

    public static function generate_todos_for_order( int $order_id ): void {
        $existing = self::get_todos_for_order( $order_id );
        if ( ! empty( $existing ) ) {
            return;
        }

        $order = Servio_Orders::get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        global $wpdb;
        $table = self::table_name();
        $pos   = 0;

        // Features du pack → étapes
        $base_offer = json_decode( $order->base_offer, true );
        $features   = $base_offer['features'] ?? [];
        foreach ( $features as $feat ) {
            if ( empty( $feat ) ) {
                continue;
            }
            $wpdb->insert( $table, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                'order_id' => $order_id,
                'label'    => sanitize_text_field( $feat ),
                'source'   => 'feature',
                'position' => $pos++,
            ] );
        }

        // Options sélectionnées → étapes
        $selected = json_decode( $order->selected_options, true );
        if ( is_array( $selected ) ) {
            foreach ( $selected as $opt ) {
                $name = is_array( $opt ) ? ( $opt['name'] ?? '' ) : $opt;
                if ( empty( $name ) ) {
                    continue;
                }
                $wpdb->insert( $table, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    'order_id' => $order_id,
                    'label'    => sanitize_text_field( $name ),
                    'source'   => 'option',
                    'position' => $pos++,
                ] );
            }
        }
    }

    /* ================================================================
     *  CRUD
     * ================================================================ */

    public static function get_todos_for_order( int $order_id ): array {
        global $wpdb;
        $table = self::table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT * FROM {$table} WHERE order_id = %d ORDER BY position ASC",
            $order_id
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function get_todo( int $todo_id ): ?object {
        global $wpdb;
        $table = self::table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT * FROM {$table} WHERE id = %d",
            $todo_id
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function toggle_todo( int $todo_id, bool $completed, string $note = '' ): bool {
        global $wpdb;
        $table = self::table_name();

        $data = [
            'is_completed' => $completed ? 1 : 0,
            'completed_at' => $completed ? current_time( 'mysql' ) : null,
            'admin_note'   => $completed && ! empty( $note ) ? $note : null,
        ];

        return (bool) $wpdb->update( $table, $data, [ 'id' => $todo_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /* ================================================================
     *  RESPONSE BUILDER
     * ================================================================ */

    public static function get_progress( int $order_id ): array {
        $todos     = self::get_todos_for_order( $order_id );
        $total     = count( $todos );
        $completed = 0;

        foreach ( $todos as $t ) {
            if ( (int) $t->is_completed === 1 ) {
                $completed++;
            }
        }

        return [
            'completed' => $completed,
            'total'     => $total,
            'percent'   => $total > 0 ? (int) round( ( $completed / $total ) * 100 ) : 0,
        ];
    }

    public static function build_todos_response( int $order_id ): ?array {
        $todos = self::get_todos_for_order( $order_id );
        if ( empty( $todos ) ) {
            return null;
        }

        $items = [];
        foreach ( $todos as $t ) {
            $items[] = [
                'id'           => (int) $t->id,
                'label'        => $t->label,
                'source'       => $t->source,
                'is_completed' => (bool) (int) $t->is_completed,
                'admin_note'   => $t->admin_note,
                'completed_at' => $t->completed_at,
            ];
        }

        return [
            'items'    => $items,
            'progress' => self::get_progress( $order_id ),
        ];
    }

    /* ================================================================
     *  AJAX
     * ================================================================ */

    public static function ajax_toggle_todo(): void {
        check_ajax_referer( 'servio_nonce', 'nonce' );

        if ( ! servio_is_premium() ) {
            wp_send_json_error( [ 'message' => 'Premium required.' ], 403 );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'servio' ) ], 403 );
        }

        $todo_id   = absint( $_POST['todo_id'] ?? 0 );
        $order_id  = absint( $_POST['order_id'] ?? 0 );
        $completed = (bool) absint( wp_unslash( $_POST['completed'] ?? 0 ) );
        $note      = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

        if ( ! $todo_id || ! $order_id ) {
            wp_send_json_error( [ 'message' => __( 'Données manquantes.', 'servio' ) ], 400 );
        }

        $result = self::toggle_todo( $todo_id, $completed, $note );
        if ( ! $result ) {
            wp_send_json_error( [ 'message' => __( 'Erreur.', 'servio' ) ], 500 );
        }

        // Message système dans le chat si une étape est complétée
        if ( $completed ) {
            $todo  = self::get_todo( $todo_id );
            $order = Servio_Orders::get_order( $order_id );

            if ( $todo && $order ) {
                $progress = self::get_progress( $order_id );
                /* translators: %1$d: order ID, %2$s: section label */
                $msg  = sprintf( "--- #CMD-%d %s ---\n", $order_id, __( 'Étape terminée', 'servio' ) );
                /* translators: %s: todo item label */
                $msg .= sprintf( __( '✅ « %s » complétée.', 'servio' ), $todo->label );

                if ( ! empty( $note ) ) {
                    /* translators: %s: admin note text */
                    $msg .= "\n" . sprintf( __( '📝 Note : %s', 'servio' ), $note );
                }

                $msg .= "\n" . sprintf(
                    /* translators: %1$d: completed tasks, %2$d: total tasks, %3$d: percentage */
                    __( '📊 Progression : %1$d/%2$d (%3$d%%)', 'servio' ),
                    $progress['completed'],
                    $progress['total'],
                    $progress['percent']
                );

                Servio_DB::insert_message( (int) $order->post_id, 0, $msg, (int) $order->client_id );
            }
        }

        // Auto-complétion : si tous les todos sont cochés, terminer la commande
        $order_completed = false;
        if ( $completed ) {
            $progress = isset( $progress ) ? $progress : self::get_progress( $order_id );
            $order    = isset( $order ) ? $order : Servio_Orders::get_order( $order_id );
            if ( $progress['total'] > 0 && (int) $progress['completed'] === (int) $progress['total'] ) {
                if ( $order && $order->status === 'started' ) {
                    $done = Servio_Orders::transition_status( $order_id, 'completed', get_current_user_id() );
                    $order_completed = $done;
                }
            }
        }

        $todos = self::build_todos_response( $order_id );
        wp_send_json_success( [ 'todos' => $todos, 'order_completed' => $order_completed ] );
    }

    public static function ajax_get_todos(): void {
        check_ajax_referer( 'servio_nonce', 'nonce' );

        if ( ! servio_is_premium() ) {
            wp_send_json_error( [ 'message' => 'Premium required.' ], 403 );
        }

        $order_id = absint( $_GET['order_id'] ?? $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( [ 'message' => __( 'Données manquantes.', 'servio' ) ], 400 );
        }

        // Vérifier que l'utilisateur a accès à cette commande
        $order = Servio_Orders::get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Commande introuvable.', 'servio' ) ], 404 );
        }

        if ( ! current_user_can( 'manage_options' ) && (int) $order->client_id !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'servio' ) ], 403 );
        }

        $todos = self::build_todos_response( $order_id );
        wp_send_json_success( [ 'todos' => $todos ] );
    }
}
