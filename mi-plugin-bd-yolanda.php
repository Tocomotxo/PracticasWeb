<?php
/**
 * Plugin Name: BD Yolanda - CRUD Empleados
 * Description: Front-end CRUD for bd-yolanda (NOMBRE, ID_EMPLEADO, TELEFONO, ROLL, PERMISOS, VIGENCIA_PERMISO) via shortcode.
 * Version: 1.0
 * Author: Yolanda
 */

if ( ! defined('ABSPATH') ) exit; // Prevent direct access

/* =========================
   CONFIG (edit if needed)
   ========================= */

// Target database name (external to WP DB)
if ( ! defined('BDY_DB_NAME') ) define('BDY_DB_NAME', 'db_yolanda');

// Same server (usually DB_HOST works: localhost / 127.0.0.1)
if ( ! defined('BDY_DB_HOST') ) define('BDY_DB_HOST', 'localhost');

// If bd-yolanda uses the same MySQL user/pass as WordPress, keep DB_USER/DB_PASSWORD.
// Otherwise replace with the correct credentials (strings in quotes).
if ( ! defined('BDY_DB_USER') ) define('BDY_DB_USER', 'yolanda');
if ( ! defined('BDY_DB_PASS') ) define('BDY_DB_PASS', 'pass1');

// Table name inside bd-yolanda
if ( ! defined('BDY_TABLE') ) define('BDY_TABLE', 'empleados');

// Dropdown options (final values)
function bdy_roles_options() : array {
    return ['Conductor', 'Mecánico'];
}
function bdy_permisos_options() : array {
    return ['Nivel 1', 'Nivel 2'];
}

/* =========================
   DB connection (bd-yolanda)
   ========================= */
function bdy_db() : wpdb {
    static $db = null;
    if ( $db instanceof wpdb ) return $db;

    $db = new wpdb(BDY_DB_USER, BDY_DB_PASS, BDY_DB_NAME, BDY_DB_HOST);

    if ( ! empty($db->last_error) ) {
        error_log('[BDY CRUD] DB connection error: ' . $db->last_error);
    }

    return $db;
}

/* =========================
   Create table on activation
   ========================= */
register_activation_hook(__FILE__, 'bdy_create_table');

function bdy_create_table() {
    $db = bdy_db();
    $table = BDY_TABLE;
    $charset_collate = $db->get_charset_collate();

    // Columns:
    // nombre, id_empleado, telefono (editable text)
    // rol, permisos (dropdown)
    // vigencia_permiso (date picker)
    $sql = "CREATE TABLE IF NOT EXISTS `$table` (
        `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `nombre` VARCHAR(100) NOT NULL,
        `id_empleado` VARCHAR(50) NOT NULL,
        `telefono` VARCHAR(30) NOT NULL,
        `rol` VARCHAR(50) NOT NULL,
        `permisos` VARCHAR(50) NOT NULL,
        `vigencia_permiso` DATE NOT NULL,
        PRIMARY KEY (`id`),
        KEY `id_empleado` (`id_empleado`)
    ) $charset_collate;";

    $db->query($sql);

    if ( ! empty($db->last_error) ) {
        error_log('[BDY CRUD] Table create error: ' . $db->last_error);
    }
}

/* =========================
   Permission check
   ========================= */
function bdy_can_manage() : bool {
    // Default: only admins. Change if you want other WP roles.
    return current_user_can('manage_options');
}

/* =========================
   Handle POST actions
   ========================= */
add_action('init', 'bdy_handle_actions');

function bdy_handle_actions() {
    if ( ! is_user_logged_in() ) return;
    if ( empty($_POST['bdy_action']) ) return;

    // Nonce security
    if ( empty($_POST['bdy_nonce']) || ! wp_verify_nonce($_POST['bdy_nonce'], 'bdy_nonce_action') ) return;

    // Capability
    if ( ! bdy_can_manage() ) return;

    $db = bdy_db();
    $table = BDY_TABLE;

    $action = sanitize_text_field($_POST['bdy_action']);

    $redirect = wp_get_referer() ? wp_get_referer() : home_url('/');
    $redirect = remove_query_arg(['bdy_msg', 'edit_id'], $redirect);

    // Validate dropdown values against allowed lists
    $roles_allowed = bdy_roles_options();
    $perms_allowed = bdy_permisos_options();

    if ( $action === 'add' || $action === 'update' ) {
        $nombre   = isset($_POST['nombre']) ? sanitize_text_field($_POST['nombre']) : '';
        $idemple  = isset($_POST['id_empleado']) ? sanitize_text_field($_POST['id_empleado']) : '';
        $telefono = isset($_POST['telefono']) ? sanitize_text_field($_POST['telefono']) : '';
        $rol      = isset($_POST['rol']) ? sanitize_text_field($_POST['rol']) : '';
        $permisos = isset($_POST['permisos']) ? sanitize_text_field($_POST['permisos']) : '';
        $vigencia = isset($_POST['vigencia_permiso']) ? sanitize_text_field($_POST['vigencia_permiso']) : '';

        // Basic checks
        if ( ! $nombre || ! $idemple || ! $telefono || ! $vigencia ) {
            wp_safe_redirect( add_query_arg('bdy_msg', 'missing', $redirect) );
            exit;
        }

        if ( ! in_array($rol, $roles_allowed, true) ) $rol = $roles_allowed[0];
        if ( ! in_array($permisos, $perms_allowed, true) ) $permisos = $perms_allowed[0];

        // Light validation for YYYY-MM-DD
        if ( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $vigencia) ) {
            wp_safe_redirect( add_query_arg('bdy_msg', 'bad_date', $redirect) );
            exit;
        }

        $data = [
            'nombre'           => $nombre,
            'id_empleado'      => $idemple,
            'telefono'         => $telefono,
            'rol'              => $rol,
            'permisos'         => $permisos,
            'vigencia_permiso' => $vigencia,
        ];
    }

    if ( $action === 'add' ) {
        $db->insert($table, $data, ['%s','%s','%s','%s','%s','%s']);
        wp_safe_redirect( add_query_arg('bdy_msg', 'added', $redirect) );
        exit;
    }

    if ( $action === 'update' ) {
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if ( ! $id ) {
            wp_safe_redirect( add_query_arg('bdy_msg', 'missing', $redirect) );
            exit;
        }

        $db->update($table, $data, ['id' => $id], ['%s','%s','%s','%s','%s','%s'], ['%d']);
        wp_safe_redirect( add_query_arg('bdy_msg', 'updated', $redirect) );
        exit;
    }

    if ( $action === 'delete' ) {
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if ( $id ) {
            $db->delete($table, ['id' => $id], ['%d']);
            wp_safe_redirect( add_query_arg('bdy_msg', 'deleted', $redirect) );
            exit;
        }
        wp_safe_redirect( add_query_arg('bdy_msg', 'missing', $redirect) );
        exit;
    }
}

/* =========================
   Shortcode: [bdy_empleados]
   ========================= */
add_shortcode('bdy_empleados', 'bdy_shortcode');

function bdy_shortcode() {
    if ( ! is_user_logged_in() ) return '<p>Debes iniciar sesión para ver esta página.</p>';
    if ( ! bdy_can_manage() ) return '<p>No tienes permisos para gestionar estos datos.</p>';

    $db = bdy_db();
    $table = BDY_TABLE;

    $edit_id = isset($_GET['edit_id']) ? absint($_GET['edit_id']) : 0;
    $msg = isset($_GET['bdy_msg']) ? sanitize_text_field($_GET['bdy_msg']) : '';

    $editing = null;
    if ( $edit_id ) {
        $editing = $db->get_row($db->prepare("SELECT * FROM `$table` WHERE id = %d", $edit_id));
    }

    $rows = $db->get_results("SELECT * FROM `$table` ORDER BY id DESC");

    $roles = bdy_roles_options();
    $perms = bdy_permisos_options();

    ob_start();

    // Messages
    if ($msg) {
        $map = [
            'added'    => '✅ Registro añadido.',
            'updated'  => '✅ Registro actualizado.',
            'deleted'  => '✅ Registro eliminado.',
            'missing'  => '⚠️ Faltan datos.',
            'bad_date' => '⚠️ Fecha inválida (usa el calendario).',
        ];
        if ( isset($map[$msg]) ) {
            echo '<div style="padding:10px;border:1px solid #ddd;margin:10px 0;">' . esc_html($map[$msg]) . '</div>';
        }
    }

    ?>
    <div style="padding:12px;border:1px solid #ddd;margin-bottom:16px;">
        <h3 style="margin-top:0;"><?php echo $editing ? 'Editar empleado' : 'Añadir empleado'; ?></h3>

        <form method="post">
            <?php wp_nonce_field('bdy_nonce_action', 'bdy_nonce'); ?>
            <input type="hidden" name="bdy_action" value="<?php echo $editing ? 'update' : 'add'; ?>">
            <?php if ($editing): ?>
                <input type="hidden" name="id" value="<?php echo esc_attr($editing->id); ?>">
            <?php endif; ?>

            <p>
                <label>NOMBRE<br>
                    <input type="text" name="nombre" required value="<?php echo esc_attr($editing->nombre ?? ''); ?>">
                </label>
            </p>

            <p>
                <label>ID_EMPLEADO<br>
                    <input type="text" name="id_empleado" required value="<?php echo esc_attr($editing->id_empleado ?? ''); ?>">
                </label>
            </p>

            <p>
                <label>TELEFONO<br>
                    <input type="text" name="telefono" required value="<?php echo esc_attr($editing->telefono ?? ''); ?>">
                </label>
            </p>

            <p>
                <label>ROLL<br>
                    <select name="rol" required>
                        <?php
                        $current_role = $editing->rol ?? $roles[0];
                        foreach ($roles as $r) {
                            $sel = ($current_role === $r) ? 'selected' : '';
                            echo '<option value="' . esc_attr($r) . '" ' . $sel . '>' . esc_html($r) . '</option>';
                        }
                        ?>
                    </select>
                </label>
            </p>

            <p>
                <label>PERMISOS<br>
                    <select name="permisos" required>
                        <?php
                        $current_perm = $editing->permisos ?? $perms[0];
                        foreach ($perms as $p) {
                            $sel = ($current_perm === $p) ? 'selected' : '';
                            echo '<option value="' . esc_attr($p) . '" ' . $sel . '>' . esc_html($p) . '</option>';
                        }
                        ?>
                    </select>
                </label>
            </p>

            <p>
                <label>VIGENCIA DE PERMISO<br>
                    <input type="date" name="vigencia_permiso" required
                           value="<?php echo esc_attr($editing->vigencia_permiso ?? ''); ?>">
                </label>
            </p>

            <button type="submit"><?php echo $editing ? 'Guardar cambios' : 'Añadir'; ?></button>

            <?php if ($editing): ?>
                <a style="margin-left:10px;" href="<?php echo esc_url(remove_query_arg('edit_id')); ?>">Cancelar</a>
            <?php endif; ?>
        </form>
    </div>

    <h3>Empleados</h3>
    <table border="1" cellpadding="6" style="border-collapse:collapse;width:100%;">
        <thead>
            <tr>
                <th>ID</th>
                <th>NOMBRE</th>
                <th>ID_EMPLEADO</th>
                <th>TELEFONO</th>
                <th>ROLL</th>
                <th>PERMISOS</th>
                <th>VIGENCIA</th>
                <th>ACCIONES</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="8">No hay datos aún.</td></tr>
        <?php else: ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo esc_html($r->id); ?></td>
                    <td><?php echo esc_html($r->nombre); ?></td>
                    <td><?php echo esc_html($r->id_empleado); ?></td>
                    <td><?php echo esc_html($r->telefono); ?></td>
                    <td><?php echo esc_html($r->rol); ?></td>
                    <td><?php echo esc_html($r->permisos); ?></td>
                    <td><?php echo esc_html($r->vigencia_permiso); ?></td>
                    <td>
                        <a href="<?php echo esc_url(add_query_arg('edit_id', $r->id)); ?>">Editar</a>

                        <form method="post" style="display:inline;" onsubmit="return confirm('¿Seguro que quieres borrar este registro?');">
                            <?php wp_nonce_field('bdy_nonce_action', 'bdy_nonce'); ?>
                            <input type="hidden" name="bdy_action" value="delete">
                            <input type="hidden" name="id" value="<?php echo esc_attr($r->id); ?>">
                            <button type="submit" style="margin-left:8px;">Borrar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    <?php

    return ob_get_clean();
}
