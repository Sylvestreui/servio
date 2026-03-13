<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServiceFlow_DB {

    const TABLE_NAME = 'serviceflow_messages';

    /**
     * Retourne le nom complet de la table avec le préfixe WP.
     */
    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Crée la table des messages à l'activation du plugin.
     */
    public static function create_table(): void {
        global $wpdb;

        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            client_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            user_id BIGINT UNSIGNED NOT NULL,
            message TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY post_client (post_id, client_id),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'serviceflow_db_version', SERVICEFLOW_VERSION );
    }

    /**
     * Insère un nouveau message.
     */
    public static function insert_message( int $post_id, int $user_id, string $message, int $client_id = 0 ): int|false {
        global $wpdb;

        $result = $wpdb->insert(
            self::table_name(),
            [
                'post_id'   => $post_id,
                'client_id' => $client_id,
                'user_id'   => $user_id,
                'message'   => $message,
            ],
            [ '%d', '%d', '%d', '%s' ]
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Récupère les messages d'un post, filtrés par client_id si fourni.
     */
    public static function get_messages( int $post_id, int $client_id = 0, int $limit = 50, int $offset = 0 ): array {
        global $wpdb;

        $table = self::table_name();

        if ( $client_id > 0 ) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT m.*, u.display_name, u.user_email
                     FROM {$table} m
                     LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID
                     WHERE m.post_id = %d AND m.client_id = %d
                     ORDER BY m.created_at ASC
                     LIMIT %d OFFSET %d",
                    $post_id,
                    $client_id,
                    $limit,
                    $offset
                )
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT m.*, u.display_name, u.user_email
                 FROM {$table} m
                 LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID
                 WHERE m.post_id = %d
                 ORDER BY m.created_at ASC
                 LIMIT %d OFFSET %d",
                $post_id,
                $limit,
                $offset
            )
        );
    }

    /**
     * Récupère les messages plus récents qu'un ID donné (pour le polling).
     */
    public static function get_new_messages( int $post_id, int $last_id, int $client_id = 0 ): array {
        global $wpdb;

        $table = self::table_name();

        if ( $client_id > 0 ) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT m.*, u.display_name, u.user_email
                     FROM {$table} m
                     LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID
                     WHERE m.post_id = %d AND m.client_id = %d AND m.id > %d
                     ORDER BY m.created_at ASC",
                    $post_id,
                    $client_id,
                    $last_id
                )
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT m.*, u.display_name, u.user_email
                 FROM {$table} m
                 LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID
                 WHERE m.post_id = %d AND m.id > %d
                 ORDER BY m.created_at ASC",
                $post_id,
                $last_id
            )
        );
    }

    /**
     * Migre les messages existants pour renseigner client_id.
     */
    public static function migrate_client_ids(): void {
        global $wpdb;

        if ( get_option( 'serviceflow_client_id_migrated' ) ) {
            return;
        }

        $table = self::table_name();

        // Étape 1 : Messages d'utilisateurs non-admin → client_id = user_id
        $admin_ids = get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] );
        if ( ! empty( $admin_ids ) ) {
            $admin_ids_str = implode( ',', array_map( 'absint', $admin_ids ) );
            $wpdb->query(
                "UPDATE {$table} SET client_id = user_id
                 WHERE client_id = 0 AND user_id > 0 AND user_id NOT IN ({$admin_ids_str})"
            );
        } else {
            // Pas d'admin trouvé : tous les user_id > 0 sont des clients
            $wpdb->query(
                "UPDATE {$table} SET client_id = user_id WHERE client_id = 0 AND user_id > 0"
            );
        }

        // Étape 2 : Messages admin/système → chercher le client via les commandes
        $order_table = ServiceFlow_Orders::table_name();
        $orphan_posts = $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$table} WHERE client_id = 0"
        );

        foreach ( $orphan_posts as $pid ) {
            $client = $wpdb->get_var( $wpdb->prepare(
                "SELECT client_id FROM {$order_table} WHERE post_id = %d GROUP BY client_id LIMIT 1",
                $pid
            ) );

            if ( $client ) {
                $wpdb->update(
                    $table,
                    [ 'client_id' => (int) $client ],
                    [ 'post_id' => (int) $pid, 'client_id' => 0 ]
                );
            }
        }

        update_option( 'serviceflow_client_id_migrated', '1' );
    }
}
