<?php
/**
 * Plugin Name: BD Yolanda - CRUD Empleados
 * Description: Front-end CRUD for db-yolanda (NOMBRE, TELEFONO, ROL, PERMISOS, VIGENCIA_PERMISO) via shortcode.
 * Version: 1.5.2
 * Author: Yolanda
 */

if ( ! defined('ABSPATH') ) exit;

/* =========================
   CONFIG
   ========================= */
if ( ! defined('BDY_DB_NAME') ) define('BDY_DB_NAME', 'db_yolanda');
if ( ! defined('BDY_DB_HOST') ) define('BDY_DB_HOST', 'localhost');
if ( ! defined('BDY_DB_USER') ) define('BDY_DB_USER', 'yolanda');
if ( ! defined('BDY_DB_PASS') ) define('BDY_DB_PASS', 'pass1');
if ( ! defined('BDY_TABLE') ) define('BDY_TABLE', 'empleados');

function bdy_roles_options() : array { return ['Conductor', 'Mecánico']; }
function bdy_permisos_options() : array { return ['Nivel 1', 'Nivel 2']; }

/**
 * CLAVE DE SEGURIDAD (SHA-256)
 * Clave fuerte generada (GUÁRDALA):
 *   -?#1apPvOkZkNcNb@I=gELhE-L#??VTFUUt73+lV
 * 
 * Hash SHA-256 de esa clave:
 *   531e5a93c883e9dc778ac6e34026c1eeda131225de211e81a5b9f0e79d62209c
 */
if ( ! defined('BDY_SECURITY_HASH') ) {
    define('BDY_SECURITY_HASH', '531e5a93c883e9dc778ac6e34026c1eeda131225de211e81a5b9f0e79d62209c');
}

/* =========================
   DB connection
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
   Permission check
   ========================= */
function bdy_can_manage() : bool {
    return current_user_can('manage_options');
}

/* =========================
   ✅ Security key verify (SHA-256)
   ========================= */
function bdy_verify_security_key(string $plain_key) : bool {
    $plain_key = trim($plain_key);
    if ($plain_key === '') return false;

    $calc = hash('sha256', $plain_key);
    return hash_equals(BDY_SECURITY_HASH, $calc);
}

/* =========================
   Helper: vigencia class (semaforo)
   ========================= */
function bdy_vigencia_class(?string $vigencia_ymd) : string {
    if (empty($vigencia_ymd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $vigencia_ymd)) return '';
    try {
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Europe/Madrid');
        $today  = new DateTimeImmutable('today', $tz);
        $expiry = new DateTimeImmutable($vigencia_ymd, $tz);

        $days = (int) $today->diff($expiry)->format('%r%a');
        if ($days < 0)   return 'bdy-vig-red';
        if ($days <= 30) return 'bdy-vig-orange';
        if ($days <= 60) return 'bdy-vig-yellow';
        return '';
    } catch (Exception $e) {
        return '';
    }
}

/* =========================
   Activation: Create/Migrate table
   ========================= */
register_activation_hook(__FILE__, 'bdy_create_or_migrate_table');

function bdy_create_or_migrate_table() {
    $db = bdy_db();
    $table = BDY_TABLE;
    $charset_collate = $db->get_charset_collate();

    // Crear tabla SIN id_empleado
    $sql = "CREATE TABLE IF NOT EXISTS `$table` (
        `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `nombre` VARCHAR(100) NOT NULL,
        `telefono` VARCHAR(30) NOT NULL,
        `rol` VARCHAR(50) NOT NULL,
        `permisos` VARCHAR(50) NOT NULL,
        `vigencia_permiso` DATE NOT NULL,
        PRIMARY KEY (`id`)
    ) $charset_collate;";

    $db->query($sql);

    if ( ! empty($db->last_error) ) {
        error_log('[BDY CRUD] Table create error: ' . $db->last_error);
    }

    // Migración: eliminar id_empleado si existía
    $has_col = $db->get_var( $db->prepare("SHOW COLUMNS FROM `$table` LIKE %s", 'id_empleado') );
    if ( $has_col ) {
        $idx_count = (int) $db->get_var( $db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
            BDY_DB_NAME, $table, 'id_empleado'
        ) );
        if ( $idx_count > 0 ) {
            $db->query("ALTER TABLE `$table` DROP INDEX `id_empleado`");
        }
        $db->query("ALTER TABLE `$table` DROP COLUMN `id_empleado`");

        if ( ! empty($db->last_error) ) {
            error_log('[BDY CRUD] Migration drop id_empleado error: ' . $db->last_error);
        }
    }
}

function bdy_next_available_id(wpdb $db, string $table) : int {
    // Trae todos los IDs en orden ascendente
    $ids = $db->get_col("SELECT id FROM `$table` ORDER BY id ASC");

    // Si no hay registros, el primero es 1
    if (empty($ids)) return 1;

    $expected = 1;
    foreach ($ids as $id) {
        $id = (int)$id;

        // Saltar ids inválidos (por seguridad)
        if ($id < 1) continue;

        // Si encontramos un hueco, devolvemos ese número
        if ($id > $expected) return $expected;

        // Si coincide, avanzamos
        if ($id === $expected) $expected++;
    }

    // Si no hay huecos, el siguiente es el último + 1
    return $expected;
}

/* =========================
   Handle POST actions
   ========================= */
add_action('init', 'bdy_handle_actions');

function bdy_handle_actions() {
    if ( ! is_user_logged_in() ) return;
    if ( empty($_POST['bdy_action']) ) return;

    if ( empty($_POST['bdy_nonce']) || ! wp_verify_nonce($_POST['bdy_nonce'], 'bdy_nonce_action') ) return;
    if ( ! bdy_can_manage() ) return;

    $db = bdy_db();
    $table = BDY_TABLE;
    $action = sanitize_text_field($_POST['bdy_action']);

    $redirect = wp_get_referer() ? wp_get_referer() : home_url('/');
    $redirect = remove_query_arg(['bdy_msg', 'edit_id'], $redirect);

    // ✅ Pedir clave para acciones sensibles (add/update/delete)
    if (in_array($action, ['add','update','delete'], true)) {
        $key = isset($_POST['bdy_key']) ? (string) $_POST['bdy_key'] : '';
        if ( ! bdy_verify_security_key($key) ) {
            wp_safe_redirect( add_query_arg('bdy_msg', 'bad_key', $redirect) );
            exit;
        }
    }

    $roles_allowed = bdy_roles_options();
    $perms_allowed = bdy_permisos_options();

    if ( $action === 'add' || $action === 'update' ) {
        $nombre   = isset($_POST['nombre']) ? sanitize_text_field($_POST['nombre']) : '';
        $telefono = isset($_POST['telefono']) ? sanitize_text_field($_POST['telefono']) : '';
        $rol      = isset($_POST['rol']) ? sanitize_text_field($_POST['rol']) : '';
        $permisos = isset($_POST['permisos']) ? sanitize_text_field($_POST['permisos']) : '';
        $vigencia = isset($_POST['vigencia_permiso']) ? sanitize_text_field($_POST['vigencia_permiso']) : '';

        if ( ! $nombre || ! $telefono || ! $vigencia ) {
            wp_safe_redirect( add_query_arg('bdy_msg', 'missing', $redirect) );
            exit;
        }

        if ( ! in_array($rol, $roles_allowed, true) ) $rol = $roles_allowed[0];
        if ( ! in_array($permisos, $perms_allowed, true) ) $permisos = $perms_allowed[0];

        if ( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $vigencia) ) {
            wp_safe_redirect( add_query_arg('bdy_msg', 'bad_date', $redirect) );
            exit;
        }

        $data = [
            'nombre'           => $nombre,
            'telefono'         => $telefono,
            'rol'              => $rol,
            'permisos'         => $permisos,
            'vigencia_permiso' => $vigencia,
        ];
    }

    /* =========================
       ADD (rellena huecos SIEMPRE; con lock si se puede, sin lock también)
       ========================= */
    if ( $action === 'add' ) {

        $locked = false;

        // ✅ IMPORTANTE: limpiar estado previo de errores antes de evaluar last_error
        $db->flush();

        // Intentar lock; si falla, seguimos SIN lock pero igualmente rellenando huecos
        $db->query("LOCK TABLES `$table` WRITE");
        if (empty($db->last_error)) {
            $locked = true;
        } else {
            error_log('[BDY CRUD] LOCK TABLES failed: ' . $db->last_error);
            // Limpia el error del lock para no contaminar comprobaciones posteriores
            $db->flush();
        }

        $ok = false;

        try {
            // ✅ CON o SIN lock: intentamos insertar usando el id libre más pequeño.
            // Reintentamos por si hay colisión (concurrencia).
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $next_id = bdy_next_available_id($db, $table);

                $sql = $db->prepare(
                    "INSERT INTO `$table` (id, nombre, telefono, rol, permisos, vigencia_permiso)
                     VALUES (%d, %s, %s, %s, %s, %s)",
                    $next_id,
                    $data['nombre'],
                    $data['telefono'],
                    $data['rol'],
                    $data['permisos'],
                    $data['vigencia_permiso']
                );

                $ok = (bool) $db->query($sql);

                if ($ok) {
                    // Mantener AUTO_INCREMENT por encima del máximo (por seguridad)
                    $max_id = (int) $db->get_var("SELECT COALESCE(MAX(id), 0) FROM `$table`");
                    $db->query("ALTER TABLE `$table` AUTO_INCREMENT = " . ((int)$max_id + 1));
                    break;
                }

                // Si no es duplicado, no tiene sentido reintentar
                if (stripos((string)$db->last_error, 'Duplicate') === false) {
                    break;
                }

                // Si fue duplicado, limpiamos y reintentamos
                $db->flush();
            }

        } finally {
            if ($locked) {
                $db->query("UNLOCK TABLES");
            }
        }

        if ( ! $ok || ! empty($db->last_error) ) {
            error_log('[BDY CRUD] Insert error: ' . $db->last_error);
            wp_safe_redirect( add_query_arg('bdy_msg', 'db_error', $redirect) );
            exit;
        }

        wp_safe_redirect( add_query_arg('bdy_msg', 'added', $redirect) );
        exit;
    }

    /* =========================
       UPDATE
       ========================= */
    if ( $action === 'update' ) {
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if ( ! $id ) {
            wp_safe_redirect( add_query_arg('bdy_msg', 'missing', $redirect) );
            exit;
        }

        $ok = $db->update($table, $data, ['id' => $id], ['%s','%s','%s','%s','%s'], ['%d']);

        if ( $ok === false || ! empty($db->last_error) ) {
            error_log('[BDY CRUD] Update error: ' . $db->last_error);
            wp_safe_redirect( add_query_arg('bdy_msg', 'db_error', $redirect) );
            exit;
        }

        wp_safe_redirect( add_query_arg('bdy_msg', 'updated', $redirect) );
        exit;
    }

    /* =========================
       DELETE
       ========================= */
    if ( $action === 'delete' ) {
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if ( $id ) {
            $ok = $db->delete($table, ['id' => $id], ['%d']);

            if ( $ok === false || ! empty($db->last_error) ) {
                error_log('[BDY CRUD] Delete error: ' . $db->last_error);
                wp_safe_redirect( add_query_arg('bdy_msg', 'db_error', $redirect) );
                exit;
            }

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

    $is_mobile = function_exists('wp_is_mobile') ? wp_is_mobile() : false;

    ob_start();

    if ($msg) {
        $map = [
            'added'    => '✅ Registro añadido.',
            'updated'  => '✅ Registro actualizado.',
            'deleted'  => '✅ Registro eliminado.',
            'missing'  => '⚠️ Faltan datos.',
            'bad_date' => '⚠️ Fecha inválida (usa el calendario).',
            'bad_key'  => '❌ Clave de seguridad incorrecta.',
            'db_error' => '❌ Error en base de datos. Revisa el log del servidor.',
        ];
        if ( isset($map[$msg]) ) {
            echo '<div style="padding:12px;border:3px solid #ddd;margin:12px 0;">' . esc_html($map[$msg]) . '</div>';
        }
    }
    ?>

<div class="bdy-table-head">
  <h3 class="bdy-title" style="margin:0;">Empleados</h3>
  <div class="bdy-search">
    <input type="text" id="bdyEmployeeSearch" placeholder="Buscar por nombre, teléfono…" aria-label="Buscar empleado">
  </div>
</div>

<?php if ( ! $is_mobile ) : ?>
  <table id="bdyEmployeeTable" class="bdy-table" border="2" cellpadding="10" style="border-collapse:collapse;width:100%;">
    <thead>
      <tr>
        <th>ID</th>
        <th>NOMBRE</th>
        <th>TELEFONO</th>
        <th>ROL</th>
        <th>PERMISOS</th>
        <th>VIGENCIA</th>
        <th>ACCIONES</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="7">No hay datos aún.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php $vig_class = bdy_vigencia_class($r->vigencia_permiso); ?>
          <tr>
            <td><?php echo esc_html($r->id); ?></td>
            <td><?php echo esc_html($r->nombre); ?></td>
            <td><?php echo esc_html($r->telefono); ?></td>
            <td><?php echo esc_html($r->rol); ?></td>
            <td><?php echo esc_html($r->permisos); ?></td>
            <td class="<?php echo esc_attr($vig_class); ?>"><?php echo esc_html($r->vigencia_permiso); ?></td>
            <td>
              <a class="bdy-edit-link" href="<?php echo esc_url(add_query_arg('edit_id', $r->id)); ?>">Editar</a>

              <form method="post" class="bdy-delete-form" style="display:inline;" onsubmit="return confirm('¿Seguro que quieres borrar este registro?');">
                <?php wp_nonce_field('bdy_nonce_action', 'bdy_nonce'); ?>
                <input type="hidden" name="bdy_action" value="delete">
                <input type="hidden" name="id" value="<?php echo esc_attr($r->id); ?>">
                <input type="hidden" name="bdy_key" class="bdy-key-field" value="">
                <button type="submit" class="bdy-delete" style="margin-left:8px;">BORRAR</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

<?php else: ?>
  <div id="bdyEmployeeCards" class="bdy-cards">
    <?php if (empty($rows)): ?>
      <div class="bdy-card-mobile">No hay datos aún.</div>
    <?php else: ?>
      <?php foreach ($rows as $r): ?>
        <?php $vig_class = bdy_vigencia_class($r->vigencia_permiso); ?>
        <div class="bdy-card-mobile">
          <div class="bdy-card-top">
            <div class="bdy-card-name"><?php echo esc_html($r->nombre); ?></div>
            <div class="bdy-card-id">#<?php echo esc_html($r->id); ?></div>
          </div>

          <div class="bdy-card-row"><strong>Tel:</strong> <?php echo esc_html($r->telefono); ?></div>
          <div class="bdy-card-row"><strong>Rol:</strong> <?php echo esc_html($r->rol); ?></div>
          <div class="bdy-card-row"><strong>Permisos:</strong> <?php echo esc_html($r->permisos); ?></div>

          <div class="bdy-card-row bdy-card-vig <?php echo esc_attr($vig_class); ?>">
            <strong>Vigencia:</strong> <?php echo esc_html($r->vigencia_permiso); ?>
          </div>

          <div class="bdy-card-actions">
            <a class="bdy-edit-link" href="<?php echo esc_url(add_query_arg('edit_id', $r->id)); ?>">Editar</a>

            <form method="post" class="bdy-delete-form" style="display:inline;" onsubmit="return confirm('¿Seguro que quieres borrar este registro?');">
              <?php wp_nonce_field('bdy_nonce_action', 'bdy_nonce'); ?>
              <input type="hidden" name="bdy_action" value="delete">
              <input type="hidden" name="id" value="<?php echo esc_attr($r->id); ?>">
              <input type="hidden" name="bdy_key" class="bdy-key-field" value="">
              <button type="submit" class="bdy-delete">BORRAR</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<script>
(function(){
  const input = document.getElementById('bdyEmployeeSearch');
  if(!input) return;

  const table = document.getElementById('bdyEmployeeTable');
  const cards = document.getElementById('bdyEmployeeCards');

  function normalize(s){
    return (s || '')
      .toString()
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g,'');
  }

  input.addEventListener('input', function(){
    const q = normalize(input.value.trim());

    if (table) {
      const tbody = table.querySelector('tbody');
      if(!tbody) return;
      const rows = Array.from(tbody.querySelectorAll('tr'));
      rows.forEach(tr => {
        const isEmptyRow = tr.querySelectorAll('td').length === 1;
        if (isEmptyRow) return;
        const text = normalize(tr.innerText);
        tr.style.display = text.includes(q) ? '' : 'none';
      });
      return;
    }

    if (cards) {
      const items = Array.from(cards.querySelectorAll('.bdy-card-mobile'));
      items.forEach(card => {
        const text = normalize(card.innerText);
        card.style.display = text.includes(q) ? '' : 'none';
      });
    }
  });
})();
</script>

<script>
(function(){
  function attachDeleteKeyPrompts(){
    document.querySelectorAll('form.bdy-delete-form').forEach(form => {
      if (form.dataset.keyPromptAttached) return;
      form.dataset.keyPromptAttached = "1";

      form.addEventListener('submit', function(e){
        const key = window.prompt('Introduce la clave de seguridad para BORRAR:');
        if (!key) { e.preventDefault(); return; }
        const hidden = form.querySelector('.bdy-key-field');
        if (hidden) hidden.value = key;
      });
    });
  }
  attachDeleteKeyPrompts();
})();
</script>

<br><br>

<div class="bdy-layout">
  <div class="bdy-card" style="padding:12px;border:3px solid #ddd;margin-bottom:12px;">
    <style>
      .bdy-form input[type="text"],
      .bdy-form input[type="date"],
      .bdy-form input[type="password"],
      .bdy-form select{ height:36px; padding:6px 8px; box-sizing:border-box; }

      .bdy-form button[type="submit"]{
        height:44px; border:none; border-radius:10px; font-weight:700; letter-spacing:1px;
        cursor:pointer; box-shadow:0 6px 16px rgba(0,0,0,.12);
        transition:transform .08s ease, box-shadow .2s ease, filter .2s ease;
      }
      .bdy-form button[type="submit"]:hover{ filter:brightness(1.05); box-shadow:0 10px 22px rgba(0,0,0,.18); }
      .bdy-form button[type="submit"]:active{ transform:translateY(1px); box-shadow:0 5px 12px rgba(0,0,0,.14); }

      .bdy-title{ margin:0 0 18px 0; font-size:22px; font-weight:700; color:#00b3a4; letter-spacing:.5px; }
      .bdy-form label{ font-weight:700; }

      .bdy-delete{
        background:#ffecec; border:1px solid #ff9a9a; color:#b30000; font-weight:700;
        padding:8px 12px; border-radius:6px; cursor:pointer;
      }
      .bdy-delete:hover{ filter:brightness(0.98); }

      .bdy-table thead th{ background:#f4f7f7; font-weight:700; }
      .bdy-edit-link{ color:#00b3a4; font-weight:700; text-decoration:none; margin-right:10px; display:inline-block; }
      .bdy-edit-link:hover{ text-decoration:underline; }

      .bdy-table th:last-child, .bdy-table td:last-child{ text-align:center; white-space:nowrap; }
      .bdy-table tbody tr:hover{ background:#f7fbfb; }

      .bdy-table-head{ display:flex; align-items:center; justify-content:space-between; gap:12px; margin:8px 0 10px 0; }
      .bdy-search{ display:flex; align-items:center; gap:8px; }
      .bdy-search input{
        height:36px; padding:6px 10px; border:1px solid #ccc; border-radius:8px; min-width:260px; box-sizing:border-box;
      }

      .bdy-layout{ display:grid; grid-template-columns:2fr 1fr; gap:24px; align-items:start; }
      .bdy-card{ width:100% !important; max-width:100%; }
      .bdy-right-space{ width:100%; min-height:220px; }
      .bdy-table{ width:100% !important; table-layout:auto; }

      /* Semáforo vigencia */
      .bdy-vig-yellow{ background:#fff7cc; font-weight:700; }
      .bdy-vig-orange{ background:#ffe0b2; font-weight:700; }
      .bdy-vig-red{ background:#ffd6d6; font-weight:700; color:#8a0000; }

      /* Tarjetas */
      .bdy-cards{ display:grid; gap:12px; }
      .bdy-card-mobile{
        border:2px solid #ddd; border-radius:12px; padding:12px; background:#fff;
        box-shadow:0 6px 16px rgba(0,0,0,.06);
      }
      .bdy-card-top{ display:flex; align-items:baseline; justify-content:space-between; gap:10px; margin-bottom:8px; }
      .bdy-card-name{ font-weight:800; font-size:16px; }
      .bdy-card-id{ font-weight:800; opacity:.65; }
      .bdy-card-row{ margin:6px 0; }
      .bdy-card-vig{ border-radius:8px; padding:8px; }
      .bdy-card-actions{ margin-top:10px; display:flex; gap:10px; align-items:center; }

      @media (max-width: 980px){ .bdy-layout{ grid-template-columns:1fr; } }
      @media (max-width: 768px){ .bdy-search input{ min-width:150px; } }
    </style>

    <h3 class="bdy-title"><?php echo $editing ? 'Editar empleado' : 'Añadir empleado'; ?></h3>

    <form method="post" class="bdy-form" style="display:grid;grid-template-columns:1fr 1fr;gap:14px 20px;align-items:start;">
      <?php wp_nonce_field('bdy_nonce_action', 'bdy_nonce'); ?>
      <input type="hidden" name="bdy_action" value="<?php echo $editing ? 'update' : 'add'; ?>">
      <?php if ($editing): ?>
        <input type="hidden" name="id" value="<?php echo esc_attr($editing->id); ?>">
      <?php endif; ?>

      <div>
        <label>NOMBRE<br>
          <input type="text" name="nombre" required style="width:100%;" value="<?php echo esc_attr($editing->nombre ?? ''); ?>">
        </label>
      </div>

      <div>
        <label>TELEFONO<br>
          <input type="text" name="telefono" required style="width:100%;" value="<?php echo esc_attr($editing->telefono ?? ''); ?>">
        </label>
      </div>

      <div>
        <label>ROL<br>
          <select name="rol" required style="width:100%;">
            <?php
              $current_role = $editing->rol ?? $roles[0];
              foreach ($roles as $r) {
                $sel = ($current_role === $r) ? 'selected' : '';
                echo '<option value="' . esc_attr($r) . '" ' . $sel . '>' . esc_html($r) . '</option>';
              }
            ?>
          </select>
        </label>
      </div>

      <div>
        <label>PERMISOS<br>
          <select name="permisos" required style="width:100%;">
            <?php
              $current_perm = $editing->permisos ?? $perms[0];
              foreach ($perms as $p) {
                $sel = ($current_perm === $p) ? 'selected' : '';
                echo '<option value="' . esc_attr($p) . '" ' . $sel . '>' . esc_html($p) . '</option>';
              }
            ?>
          </select>
        </label>
      </div>

      <div>
        <label>VIGENCIA DE PERMISO<br>
          <input type="date" name="vigencia_permiso" required style="width:100%;" value="<?php echo esc_attr($editing->vigencia_permiso ?? ''); ?>">
        </label>
      </div>

      <div>
        <label>CLAVE DE SEGURIDAD<br>
          <input type="password" name="bdy_key" required style="width:100%;" autocomplete="current-password">
        </label>
      </div>

      <div style="grid-column:1 / -1;">
        <button type="submit" style="width:100%;padding:10px;">
          <?php echo $editing ? 'Guardar cambios' : 'Añadir'; ?>
        </button>
        <?php if ($editing): ?>
          <a style="margin-left:10px;" href="<?php echo esc_url(remove_query_arg('edit_id')); ?>">Cancelar</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="bdy-right-space"></div>
</div>

<?php
    return ob_get_clean();
}