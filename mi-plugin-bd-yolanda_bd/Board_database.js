(function () {
  const ajaxurl = window.TEC_EMPLEADOS?.ajaxurl;
  const nonce = window.TEC_EMPLEADOS?.nonce;
  const roles = window.TEC_EMPLEADOS?.roles || [];
  const permisos = window.TEC_EMPLEADOS?.permisos || [];

  const tbody = document.getElementById("tec-tbody");
  const msg = document.getElementById("tec-msg");

  // (Opcional) si añades un botón en el PHP con id="tec-add-row"
  const addBtn = document.getElementById("tec-add-row");

  if (!ajaxurl || !nonce || !tbody) return;

  function setMessage(text, ok = true) {
    if (!msg) return;
    msg.textContent = text || "";
    msg.style.color = ok ? "green" : "red";
  }

  async function post(action, data) {
    const fd = new FormData();
    fd.append("action", action);
    fd.append("nonce", nonce);
    Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v ?? ""));

    const res = await fetch(ajaxurl, { method: "POST", body: fd });
    const json = await res.json();
    if (!json?.success) throw new Error(json?.data?.message || "Error");
    return json.data;
  }

  function esc(s) {
    return (s ?? "").toString()
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function optionList(list, selected) {
    const sel = (selected ?? "").toString();
    return list.map(v => {
      const vv = (v ?? "").toString();
      const isSel = vv === sel ? "selected" : "";
      return `<option value="${esc(vv)}" ${isSel}>${esc(vv)}</option>`;
    }).join("");
  }

  function rowTemplate(r) {
    const id = r.id ? String(r.id) : ""; // vacío => nuevo
    const nombre = r.nombre ?? "";
    const id_empleado = r.id_empleado ?? "";
    const telefono = r.telefono ?? "";
    const rol = r.rol ?? (roles[0] ?? "");
    const perm = r.permisos ?? (permisos[0] ?? "");
    const vig = r.vigencia_permiso ?? ""; // YYYY-MM-DD o ""

    return `
      <tr data-id="${esc(id)}" class="${id ? "" : "tec-new-row"}">
        <td>${id ? esc(id) : "<em>Nuevo</em>"}</td>

        <td>
          <input class="tec-cell" data-field="nombre" type="text" value="${esc(nombre)}" placeholder="Nombre" required>
        </td>

        <td>
          <input class="tec-cell" data-field="id_empleado" type="text" value="${esc(id_empleado)}" placeholder="ID_EMPLEADO" required>
        </td>

        <td>
          <input class="tec-cell" data-field="telefono" type="text" value="${esc(telefono)}" placeholder="Teléfono">
        </td>

        <td>
          <select class="tec-cell" data-field="rol" required>
            ${optionList(roles, rol)}
          </select>
        </td>

        <td>
          <select class="tec-cell" data-field="permisos" required>
            ${optionList(permisos, perm)}
          </select>
        </td>

        <td>
          <input class="tec-cell" data-field="vigencia_permiso" type="date" value="${esc(vig)}">
        </td>

        <td class="tec-updated-at">${esc(r.updated_at ?? "")}</td>

        <td>
          <button type="button" class="tec-save-row">Guardar</button>
          <button type="button" class="tec-del-row">Borrar</button>
          <span class="tec-dirty" style="margin-left:8px; display:none;">Pendiente…</span>
        </td>
      </tr>
    `;
  }

  function render(rows) {
    if (!rows || rows.length === 0) {
      tbody.innerHTML = `<tr><td colspan="9">Sin registros</td></tr>`;
      return;
    }
    tbody.innerHTML = rows.map(rowTemplate).join("");
  }

  async function load() {
    try {
      tbody.innerHTML = `<tr><td colspan="9">Cargando…</td></tr>`;
      const data = await post("tec_list", {});
      render(data.rows);
    } catch (e) {
      tbody.innerHTML = `<tr><td colspan="9">Error cargando</td></tr>`;
      setMessage(e.message, false);
    }
  }

  function getRowData(tr) {
    const id = tr.getAttribute("data-id") || "";

    const fields = {};
    tr.querySelectorAll(".tec-cell").forEach(el => {
      const key = el.getAttribute("data-field");
      fields[key] = (el.value ?? "").trim();
    });

    return {
      id,
      nombre: fields.nombre || "",
      id_empleado: fields.id_empleado || "",
      telefono: fields.telefono || "",
      rol: fields.rol || "",
      permisos: fields.permisos || "",
      vigencia_permiso: fields.vigencia_permiso || ""
    };
  }

  function markDirty(tr, dirty) {
    const tag = tr.querySelector(".tec-dirty");
    if (tag) tag.style.display = dirty ? "inline" : "none";
    tr.dataset.dirty = dirty ? "1" : "0";
  }

  // Marcar como “pendiente” cuando cambie algo
  tbody.addEventListener("input", (ev) => {
    const tr = ev.target.closest("tr");
    if (!tr) return;
    if (!ev.target.classList.contains("tec-cell")) return;
    markDirty(tr, true);
  });

  // Acciones: guardar/borrar
  tbody.addEventListener("click", async (ev) => {
    const btn = ev.target;
    const tr = btn.closest("tr");
    if (!tr) return;

    // GUARDAR FILA
    if (btn.classList.contains("tec-save-row")) {
      try {
        setMessage("");

        const payload = getRowData(tr);

        // Validación mínima en cliente (el servidor también valida)
        if (!payload.nombre || !payload.id_empleado) {
          setMessage("NOMBRE e ID_EMPLEADO son obligatorios", false);
          return;
        }

        const data = await post("tec_save", payload);

        // Si era nuevo, el backend devuelve un id => lo ponemos
        if (!tr.getAttribute("data-id") && data?.id) {
          tr.setAttribute("data-id", String(data.id));
          tr.classList.remove("tec-new-row");
          tr.children[0].innerHTML = esc(String(data.id));
        }

        // Quitamos flag “pendiente” y recargamos para ver updated_at coherente
        markDirty(tr, false);
        setMessage("Guardado ✅", true);

        await load();
      } catch (e) {
        setMessage(e.message, false);
      }
    }

    // BORRAR FILA
    if (btn.classList.contains("tec-del-row")) {
      const id = tr.getAttribute("data-id") || "";
      if (!id) {
        // Si es una fila nueva aún no guardada, solo la quitamos
        tr.remove();
        setMessage("Fila nueva eliminada (no estaba guardada).", true);
        return;
      }

      if (!confirm("¿Seguro que quieres borrar el registro ID " + id + "?")) return;

      try {
        await post("tec_delete", { id });
        setMessage("Borrado ✅", true);
        await load();
      } catch (e) {
        setMessage(e.message, false);
      }
    }
  });

  // (Opcional) Añadir fila “en blanco”
  if (addBtn) {
    addBtn.addEventListener("click", () => {
      // Si la tabla estaba en "Sin registros", sustituimos
      if (tbody.querySelector("td[colspan]")) {
        tbody.innerHTML = "";
      }

      const blank = {
        id: "",
        nombre: "",
        id_empleado: "",
        telefono: "",
        rol: roles[0] ?? "",
        permisos: permisos[0] ?? "",
        vigencia_permiso: "",
        updated_at: ""
      };

      tbody.insertAdjacentHTML("afterbegin", rowTemplate(blank));
      const firstRow = tbody.querySelector("tr");
      if (firstRow) {
        markDirty(firstRow, true);
        const firstInput = firstRow.querySelector('input[data-field="nombre"]');
        if (firstInput) firstInput.focus();
      }
    });
  }

  load();
})();
