<?php
/**
 * Plugin Name: Board Empleados CRUD (AJAX)
 * Description: CRUD (listar/añadir/editar/borrar) con columnas: NOMBRE, ID_EMPLEADO, TELEFONO, ROLL, PERMISOS, VIGENCIA_DE_PERMISO. Shortcode: [tabla_empleados]
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

class TEC_Empleados_CRUD {
  private string $table_name;
  private string $nonce_action = 'tec_empleados_nonce_action';

  // ==== CUSTOMIZE OPTIONS ====
  private array $roles = [
    'Conductor',
    'Administrativo',
  ];

  private array $permisos = [
    'Nivel 1',
    'Nivel 2',
    'Nivel 3',
  ];
  // ======================================

  public function __construct() {
    global $wpdb;
    $this->table_name = $wpdb->prefix . 'empleados_registros';

    register_activation_hook(__FILE__, [$this, 'activate']);

    add_shortcode('tabla_empleados', [$this, 'shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

    add_action('wp_ajax_tec_list',   [$this, 'ajax_list']);
    add_action('wp_ajax_tec_save',   [$this, 'ajax_save']);
    add_action('wp_ajax_tec_delete', [$this, 'ajax_delete']);
  }

  /**
   * PCUSTOMIZE access/editing permissions:
   * - 'read' => almost any logged-in user.
   * - 'edit_posts' => editors/admins typically.
   */
  private function can_manage(): bool {
    return is_user_logged_in() && current_user_can('edit_post');
  }

  public function activate() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    // Custom table in the WordPress database
    $sql = "CREATE TABLE {$this->table_name} (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,

      nombre VARCHAR(200) NOT NULL,
      id_empleado VARCHAR(80) NOT NULL,
      telefono VARCHAR(50) NULL,

      rol VARCHAR(80) NOT NULL,
      permisos VARCHAR(80) NOT NULL,

      vigencia_permiso DATE NULL,

      updated_at DATETIME NOT NULL,
      updated_by BIGINT(20) UNSIGNED NULL,

      PRIMARY KEY (id),
      UNIQUE KEY uniq_id_empleado (id_empleado),
      KEY idx_rol (rol),
      KEY idx_permisos (permisos),
      KEY idx_vigencia (vigencia_permiso)
    ) $charset_collate;";

    dbDelta($sql);
  }

  public function enqueue_assets() {
    if (!is_singular()) return;

    global $post;
    if (!$post || strpos($post->post_content, '[tabla_empleados') === false) return;

    wp_enqueue_script(
      'tec-empleados-crud',
      plugin_dir_url(__FILE__) . 'tabla-empleados-crud.js',
      [],
      '1.0.0',
      true
    );

    wp_localize_script('tec-empleados-crud', 'TEC_EMPLEADOS', [
      'ajaxurl'   => admin_url('admin-ajax.php'),
      'nonce'     => wp_create_nonce($this->nonce_action),
      'roles'     => array_values($this->roles),
      'permisos'  => array_values($this->permisos),
    ]);
  }

  public function shortcode() {
    if (!$this->can_manage()) {
      return '<p>No tienes permisos para ver esta tabla.</p>';
    }

    // We generate selects from arrays (so they don't depend on JS)
    $roles_options = '';
    foreach ($this->roles as $r) {
      $roles_options .= '<option value="'.esc_attr($r).'">'.esc_html($r).'</option>';
    }

    $permisos_options = '';
    foreach ($this->permisos as $p) {
      $permisos_options .= '<option value="'.esc_attr($p).'">'.esc_html($p).'</option>';
    }

    ob_start();
    ?>
    <div class="tec-empleados-wrap">
      <h3>Empleados</h3>

      <form id="tec-form" style="margin-bottom:12px; padding:12px; border:1px solid #ddd;">
        <input type="hidden" id="tec-id" value="">

        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <label>
            NOMBRE<br>
            <input type="text" id="tec-nombre" required>
          </label>

          <label>
            ID_EMPLEADO<br>
            <input type="text" id="tec-id_empleado" required>
          </label>

          <label>
            TELEFONO<br>
            <input type="text" id="tec-telefono">
          </label>

          <label>
            ROLL<br>
            <select id="tec-rol" required>
              <?php echo $roles_options; ?>
            </select>
          </label>

          <label>
            PERMISOS<br>
            <select id="tec-permisos" required>
              <?php echo $permisos_options; ?>
            </select>
          </label>

          <label>
            VIGENCIA DE PERMISO<br>
            <input type="date" id="tec-vigencia_permiso">
          </label>
        </div>

        <div style="margin-top:10px; display:flex; gap:10px;">
          <button type="submit">Guardar</button>
          <button type="button" id="tec-cancel" style="display:none;">Cancelar edición</button>
        </div>

        <p id="tec-msg" style="margin:10px 0 0;"></p>
      </form>

      <table style="width:100%; border-collapse:collapse;" border="1" cellpadding="8">
        <thead>
          <tr>
            <th>ID</th>
            <th>NOMBRE</th>
            <th>ID_EMPLEADO</th>
            <th>TELEFONO</th>
            <th>ROLL</th>
            <th>PERMISOS</th>
            <th>VIGENCIA DE PERMISO</th>
            <th>Actualizado</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody id="tec-tbody">
          <tr><td colspan="9">Cargando…</td></tr>
        </tbody>
      </table>
    </div>
    <?php
    return ob_get_clean();
  }

  private function verify_request() {
    if (!$this->can_manage()) {
      wp_send_json_error(['message' => 'Sin permisos'], 403);
    }
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, $this->nonce_action)) {
      wp_send_json_error(['message' => 'Nonce inválido'], 403);
    }
  }

  private function is_allowed_value(string $value, array $allowed): bool {
    return in_array($value, $allowed, true);
  }

  public function ajax_list() {
    $this->verify_request();

    global $wpdb;
    $rows = $wpdb->get_results(
      "SELECT id, nombre, id_empleado, telefono, rol, permisos, vigencia_permiso, updated_at
       FROM {$this->table_name}
       ORDER BY updated_at DESC
       LIMIT 500",
      ARRAY_A
    );

    wp_send_json_success(['rows' => $rows]);
  }

  public function ajax_save() {
    $this->verify_request();
    global $wpdb;

    $id          = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $nombre      = isset($_POST['nombre']) ? sanitize_text_field($_POST['nombre']) : '';
    $id_empleado = isset($_POST['id_empleado']) ? sanitize_text_field($_POST['id_empleado']) : '';
    $telefono    = isset($_POST['telefono']) ? sanitize_text_field($_POST['telefono']) : '';
    $rol         = isset($_POST['rol']) ? sanitize_text_field($_POST['rol']) : '';
    $permisos    = isset($_POST['permisos']) ? sanitize_text_field($_POST['permisos']) : '';
    $vigencia    = isset($_POST['vigencia_permiso']) ? sanitize_text_field($_POST['vigencia_permiso']) : '';

    if ($nombre === '' || $id_empleado === '') {
      wp_send_json_error(['message' => 'NOMBRE e ID_EMPLEADO son obligatorios'], 400);
    }

    // Validate selects (very important)
    if (!$this->is_allowed_value($rol, $this->roles)) {
      wp_send_json_error(['message' => 'ROLL inválido'], 400);
    }
    if (!$this->is_allowed_value($permisos, $this->permisos)) {
      wp_send_json_error(['message' => 'PERMISOS inválido'], 400);
    }

    // Validate date (YYYY-MM-DD) or empty
    if ($vigencia !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $vigencia)) {
      wp_send_json_error(['message' => 'VIGENCIA DE PERMISO inválida'], 400);
    }

    $data = [
      'nombre'           => $nombre,
      'id_empleado'      => $id_empleado,
      'telefono'         => ($telefono !== '' ? $telefono : null),
      'rol'              => $rol,
      'permisos'         => $permisos,
      'vigencia_permiso' => ($vigencia !== '' ? $vigencia : null),
      'updated_at'       => current_time('mysql'),
      'updated_by'       => get_current_user_id(),
    ];

    $formats = ['%s','%s','%s','%s','%s','%s','%s','%d'];

    if ($id > 0) {
      $updated = $wpdb->update(
        $this->table_name,
        $data,
        ['id' => $id],
        $formats,
        ['%d']
      );
      if ($updated === false) {
        wp_send_json_error(['message' => 'Error al actualizar'], 500);
      }
      wp_send_json_success(['message' => 'Actualizado', 'id' => $id]);
    } else {
      // If id_empleado (UNIQUE) already exists, the insert will fail. We'll catch the error politely.
      $inserted = $wpdb->insert($this->table_name, $data, $formats);
      if (!$inserted) {
        $err = $wpdb->last_error ?: 'Error al insertar';
        wp_send_json_error(['message' => $err], 500);
      }
      wp_send_json_success(['message' => 'Creado', 'id' => $wpdb->insert_id]);
    }
  }

  public function ajax_delete() {
    $this->verify_request();
    global $wpdb;

    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($id <= 0) wp_send_json_error(['message' => 'ID inválido'], 400);

    $deleted = $wpdb->delete($this->table_name, ['id' => $id], ['%d']);
    if ($deleted === false) wp_send_json_error(['message' => 'Error al borrar'], 500);

    wp_send_json_success(['message' => 'Borrado']);
  }
}

new TEC_Empleados_CRUD();
