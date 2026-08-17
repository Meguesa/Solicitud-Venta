document.addEventListener("DOMContentLoaded", async () => {
  const loginButton = document.getElementById("btnLogin");
  const logoutButton = document.getElementById("btnLogout");
  const form = document.getElementById("solicitudForm");
  const tipoOperacion = document.getElementById("tipoOperacion");
  const tipoVentaProcap = document.getElementById("tipoVentaProcap");
  const formaPago = document.getElementById("formaPago");
  const fechaNacimiento = document.getElementById("fechaNacimiento");
  const precioTotal = document.getElementById("precioTotal");
  const enganche = document.getElementById("enganche");
  const mensualidades = document.getElementById("mensualidades");

  loginButton.addEventListener("click", async () => {
    try {
      loginButton.disabled = true;
      document.getElementById("loginMessage").textContent = "Abriendo inicio de sesión de Microsoft...";
      await window.solicitudVentaAuth.login();
    } catch (error) {
      console.error("No fue posible iniciar sesion:", error);
      loginButton.disabled = false;
      document.getElementById("loginMessage").textContent = formatearErrorAcceso(error);
    }
  });

  logoutButton.addEventListener("click", async () => {
    try {
      await window.solicitudVentaAuth.logout();
    } catch (error) {
      console.error("No fue posible cerrar sesion:", error);
    }
  });

  tipoOperacion.addEventListener("change", actualizarSecciones);
  tipoVentaProcap.addEventListener("change", actualizarSecciones);
  formaPago.addEventListener("change", actualizarFinanciamiento);
  fechaNacimiento.addEventListener("change", calcularEdad);
  precioTotal.addEventListener("input", recalcularImportes);
  enganche.addEventListener("input", recalcularImportes);
  mensualidades.addEventListener("input", recalcularImportes);

  document.getElementById("btnReset").addEventListener("click", () => {
    form.reset();
    inicializarFecha();
    copiarUsuarioEnVendedor();
    actualizarSecciones();
    actualizarFinanciamiento();
    recalcularImportes();
    mostrarMensaje("Formulario limpiado. Esta versión aún no guarda información en SharePoint.");
  });

  form.addEventListener("submit", (event) => {
    event.preventDefault();
    actualizarRequiredVisibles();

    if (!form.checkValidity()) {
      form.reportValidity();
      mostrarMensaje("Faltan campos obligatorios por completar.", "error");
      return;
    }

    if (formaPago.value === "CREDITO" && !document.getElementById("conformidadFinanciamiento").checked) {
      mostrarMensaje("Debes confirmar la conformidad del financiamiento.", "error");
      return;
    }

    mostrarMensaje("Validación correcta. El siguiente paso será guardar esta solicitud en SharePoint.", "ok");
  });

  try {
    await window.solicitudVentaAuth.initialize();
    inicializarFecha();
    copiarUsuarioEnVendedor();
    actualizarSecciones();
    actualizarFinanciamiento();
    recalcularImportes();
    loginButton.disabled = false;
    if (!window.solicitudVentaAuth.getUser()) {
      document.getElementById("loginMessage").textContent =
        "Inicia sesión con tu cuenta empresarial de Jardines de Juan Pablo.";
    }
  } catch (error) {
    console.error("No fue posible inicializar la autenticacion:", error);
    loginButton.disabled = false;
    document.getElementById("loginMessage").textContent = formatearErrorAcceso(error);
  }
});

function formatearErrorAcceso(error) {
  const mensaje = String(error?.message || error || "Error desconocido");
  const codigo = error?.errorCode ? ` [${error.errorCode}]` : "";
  return `No fue posible inicializar el acceso${codigo}: ${mensaje}`;
}

function inicializarFecha() {
  const control = document.getElementById("fechaSolicitud");
  if (!control.value) {
    const hoy = new Date();
    const local = new Date(hoy.getTime() - hoy.getTimezoneOffset() * 60000);
    control.value = local.toISOString().slice(0, 10);
  }
}

function copiarUsuarioEnVendedor() {
  const nombre = document.getElementById("userName")?.textContent?.trim() || "";
  const correo = document.getElementById("userEmail")?.textContent?.trim() || "";
  document.getElementById("vendedorNombre").value = nombre === "Usuario" ? "" : nombre;
  document.getElementById("vendedorCorreo").value = correo;
}

function actualizarSecciones() {
  const operacion = document.getElementById("tipoOperacion").value;
  const venta = document.getElementById("tipoVentaProcap").value;
  const esUsoInmediato = operacion === "USO INMEDIATO" || venta.endsWith(" UI");
  const esServicio = venta.includes("SERVICIO") || venta.includes("COBERTURA");

  mostrarGrupo("finadoFields", esUsoInmediato);
  mostrarGrupo("sustitutoFields", !esUsoInmediato);
  mostrarGrupo("servicioFields", esServicio);
  mostrarGrupo("duracionSection", esServicio);
  mostrarGrupo("propiedadFields", venta.includes("NICHO") || venta.includes("CEMENTERIO"));
  actualizarRequiredVisibles();
}

function actualizarFinanciamiento() {
  const esCredito = document.getElementById("formaPago").value === "CREDITO";
  mostrarGrupo("financiamientoFields", esCredito);
  actualizarRequiredVisibles();
  recalcularImportes();
}

function mostrarGrupo(id, visible) {
  const elemento = document.getElementById(id);
  if (elemento) elemento.hidden = !visible;
}

function actualizarRequiredVisibles() {
  document.querySelectorAll("[data-required-when-visible]").forEach((control) => {
    const contenedorOculto = control.closest("[hidden]");
    control.required = !contenedorOculto;
  });
}

function calcularEdad() {
  const valor = document.getElementById("fechaNacimiento").value;
  const salida = document.getElementById("edadCliente");
  if (!valor) {
    salida.value = "";
    return;
  }

  const nacimiento = new Date(`${valor}T00:00:00`);
  const hoy = new Date();
  let edad = hoy.getFullYear() - nacimiento.getFullYear();
  const mes = hoy.getMonth() - nacimiento.getMonth();
  if (mes < 0 || (mes === 0 && hoy.getDate() < nacimiento.getDate())) edad--;
  salida.value = Math.max(0, edad);
}

function recalcularImportes() {
  const total = Number(document.getElementById("precioTotal").value || 0);
  const inicial = Number(document.getElementById("enganche").value || 0);
  const saldo = Math.max(0, total - inicial);
  const pagos = Number(document.getElementById("mensualidades").value || 0);

  document.getElementById("saldo").value = saldo.toFixed(2);
  document.getElementById("importeMensual").value = pagos > 0 ? (saldo / pagos).toFixed(2) : "0.00";
}

function mostrarMensaje(texto, tipo = "") {
  const mensaje = document.getElementById("formMessage");
  mensaje.textContent = texto;
  mensaje.className = `form-message ${tipo}`.trim();
}
