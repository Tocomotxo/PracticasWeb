<?php
/**
 * Plugin Name: BD Yolanda - CRUD Empleados
 * Description: Front-end CRUD for db-yolanda (NOMBRE, TELEFONO, ROLL, PERMISOS, VIGENCIA_PERMISO) via shortcode.
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

// Table name inside db-yolanda
if ( ! defined('BDY_TABLE') ) define('BDY_TABLE', 'empleados');

// Dropdown options (final values)
function bdy_roles_options() : array {
    return ['Conductor', 'Mecánico'];
}
function bdy_permisos_options() : array {
    return ['Nivel 1', 'Nivel 2'];
}

/* =========================
   DB connection (db-yolanda)
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

    // Nota: mantenemos la columna id_empleado en DB por compatibilidad,
    // aunque ya no se use en el formulario/tabla.
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
        $telefono = isset($_POST['telefono']) ? sanitize_text_field($_POST['telefono']) : '';
        $rol      = isset($_POST['rol']) ? sanitize_text_field($_POST['rol']) : '';
        $permisos = isset($_POST['permisos']) ? sanitize_text_field($_POST['permisos']) : '';
        $vigencia = isset($_POST['vigencia_permiso']) ? sanitize_text_field($_POST['vigencia_permiso']) : '';

        // Basic checks (id_empleado eliminado)
        if ( ! $nombre || ! $telefono || ! $vigencia ) {
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

        // id_empleado eliminado
        $data = [
            'nombre'           => $nombre,
            'telefono'         => $telefono,
            'rol'              => $rol,
            'permisos'         => $permisos,
            'vigencia_permiso' => $vigencia,
        ];
    }

    if ( $action === 'add' ) {
        // 5 campos -> 5 formatos
        $db->insert($table, $data, ['%s','%s','%s','%s','%s']);
        wp_safe_redirect( add_query_arg('bdy_msg', 'added', $redirect) );
        exit;
    }

    if ( $action === 'update' ) {
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if ( ! $id ) {
            wp_safe_redirect( add_query_arg('bdy_msg', 'missing', $redirect) );
            exit;
        }

        // 5 campos -> 5 formatos
        $db->update($table, $data, ['id' => $id], ['%s','%s','%s','%s','%s'], ['%d']);
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
            echo '<div style="padding:12px;border:3px solid #ddd;margin:12px 0;">' . esc_html($map[$msg]) . '</div>';
        }
    }
    ?>

<!-- =========================
     TABLA PRIMERO
     ========================= -->
<div class="bdy-table-head">
  <h3 class="bdy-title" style="margin:0;">Empleados</h3>
  <div class="bdy-search">
    <input
      type="text"
      id="bdyEmployeeSearch"
      placeholder="Buscar por nombre, teléfono…"
      aria-label="Buscar empleado"
    >
  </div>
</div>

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
        <tr>
          <td><?php echo esc_html($r->id); ?></td>
          <td><?php echo esc_html($r->nombre); ?></td>
          <td><?php echo esc_html($r->telefono); ?></td>
          <td><?php echo esc_html($r->rol); ?></td>
          <td><?php echo esc_html($r->permisos); ?></td>
          <td><?php echo esc_html($r->vigencia_permiso); ?></td>
          <td>
            <a class="bdy-edit-link" href="<?php echo esc_url(add_query_arg('edit_id', $r->id)); ?>">Editar</a>

            <form method="post" style="display:inline;" onsubmit="return confirm('¿Seguro que quieres borrar este registro?');">
              <?php wp_nonce_field('bdy_nonce_action', 'bdy_nonce'); ?>
              <input type="hidden" name="bdy_action" value="delete">
              <input type="hidden" name="id" value="<?php echo esc_attr($r->id); ?>">
              <button type="submit" class="bdy-delete" style="margin-left:8px;">BORRAR</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </tbody>
</table>

<script>
(function(){
  const input = document.getElementById('bdyEmployeeSearch');
  const table = document.getElementById('bdyEmployeeTable');
  if(!input || !table) return;

  const tbody = table.querySelector('tbody');
  if(!tbody) return;

  const rows = Array.from(tbody.querySelectorAll('tr'));

  function normalize(s){
    return (s || '')
      .toString()
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g,'');
  }

  input.addEventListener('input', function(){
    const q = normalize(input.value.trim());

    rows.forEach(tr => {
      const isEmptyRow = tr.querySelectorAll('td').length === 1;
      if (isEmptyRow) return;

      const text = normalize(tr.innerText);
      tr.style.display = text.includes(q) ? '' : 'none';
    });
  });
})();
</script>

<br><br>

<!-- =========================
     FORMULARIO DESPUÉS (tu bloque intacto)
     ========================= -->

<div class="bdy-layout">
  <div class="bdy-card" style="padding:12px;border:3px solid #ddd;margin-bottom:12px;">
    <style>
      .bdy-form input[type="text"],
      .bdy-form input[type="date"],
      .bdy-form select{
        height: 36px;
        padding: 6px 8px;
        box-sizing: border-box;
      }
      /* BOTÓN PRINCIPAL */
      .bdy-form button[type="submit"]{
        height: 44px;
        border: none;
        border-radius: 10px;
        font-weight: 700;
        letter-spacing: 1px;
        cursor: pointer;
        box-shadow: 0 6px 16px rgba(0,0,0,.12);
        transition: transform .08s ease, box-shadow .2s ease, filter .2s ease;
      }
      .bdy-form button[type="submit"]:hover{
        filter: brightness(1.05);
        box-shadow: 0 10px 22px rgba(0,0,0,.18);
      }
      .bdy-form button[type="submit"]:active{
        transform: translateY(1px);
        box-shadow: 0 5px 12px rgba(0,0,0,.14);
      }
      /* TÍTULO DEL FORMULARIO */
      .bdy-title{
        margin: 0 0 18px 0;
        font-size: 22px;
        font-weight: 700;
        color: #00b3a4;/* mismo color que el botón */
        letter-spacing: .5px;
      }
      /* CAMPOS DEL FORMULARIO EN NEGRITA*/
      .bdy-form label{
        font-weight: 700;
      }
      /* BOTON BORRAR (peligroso) */
      .bdy-delete{
        background:#ffecec;
        border:1px solid #ff9a9a;
        color:#b30000;
        font-weight:700;
        padding: 8px 12px;
        border-radius: 6px;
        cursor: pointer;
      }
      .bdy-delete:hover{
        filter: brightness(0.98);
      }
      /* CABECERA DE LA TABLA */
      .bdy-table thead th{
        background:#f4f7f7;
        font-weight:700;
      }
      /* BOTON EDITAR*/
      .bdy-edit-link{
        color:#00b3a4;
        font-weight:700;
        text-decoration:none;
        margin-right: 10px;
        display: inline-block;
      }
      .bdy-edit-link:hover{
        text-decoration:underline;
      }
      /* Centrar y compactar la columna ACCIONES */
      .bdy-table th:last-child,
      .bdy-table td:last-child{
        text-align: center;
        white-space: nowrap;
      }
      /* Hover en filas (mejor lectura) */
      .bdy-table tbody tr:hover{
        background: #f7fbfb;
      }
      /* CABECERA: titulo a la izquierda + buscador a la derecha */
      .bdy-table-head{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        margin: 8px 0 10px 0;
      }
      .bdy-search{
        display:flex;
        align-items:center;
        gap:8px;
      }
      .bdy-search input{
        height: 36px;
        padding: 6px 10px;
        border: 1px solid #ccc;
        border-radius: 8px;
        min-width: 260px;
        box-sizing: border-box;
      }

      /* LAYOUT 2 COLUMNAS: formulario izq + hueco der */
      .bdy-layout{
        display: grid;
        grid-template-columns: 2fr 1fr; /* ✅ CAMBIO: formulario más ancho */
        gap: 24px;
        align-items: start;
      }

      .bdy-card{
        width: 100% !important;
        max-width: 100%;
      }

      /* Hueco derecho (vacío) */
      .bdy-right-space{
        width: 100%;
        min-height: 220px; /* ajusta si lo quieres más alto/bajo */
      }

      /* Tabla ocupa todo el ancho (cuando está abajo) */
      .bdy-table{
        width: 100% !important;
        table-layout: auto;
      }

      /* Responsive */
      @media (max-width: 980px){
        .bdy-layout{
          grid-template-columns: 1fr;
        }
      }
    </style>

    <h3 class="bdy-title"><?php echo $editing ? 'Editar empleado' : 'Añadir empleado'; ?></h3>

    <form method="post" class="bdy-form"
      style="display:grid;grid-template-columns:1fr 1fr;gap:14px 20px;align-items:start;">
      <?php wp_nonce_field('bdy_nonce_action', 'bdy_nonce'); ?>
      <input type="hidden" name="bdy_action" value="<?php echo $editing ? 'update' : 'add'; ?>">
      <?php if ($editing): ?>
        <input type="hidden" name="id" value="<?php echo esc_attr($editing->id); ?>">
      <?php endif; ?>

      <div>
        <label>NOMBRE<br>
          <input type="text" name="nombre" required style="width:100%;"
            value="<?php echo esc_attr($editing->nombre ?? ''); ?>">
        </label>
      </div>

      <div>
        <label>TELEFONO<br>
          <input type="text" name="telefono" required style="width:100%;"
            value="<?php echo esc_attr($editing->telefono ?? ''); ?>">
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
          <input type="date" name="vigencia_permiso" required style="width:100%;"
            value="<?php echo esc_attr($editing->vigencia_permiso ?? ''); ?>">
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

  <!-- Columna derecha vacía -->
  <div class="bdy-right-space"></div>
</div> <!-- /bdy-layout -->

<?php
return ob_get_clean();
}