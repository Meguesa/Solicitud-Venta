document.addEventListener("DOMContentLoaded", async () => {
  const loginButton = document.getElementById("btnLogin");
  const logoutButton = document.getElementById("btnLogout");
  const form = document.getElementById("solicitudForm");
  const tipoSolicitud = document.getElementById("tipoSolicitud");
  const tipoOperacion = document.getElementById("tipoOperacion");
  const formaPago = document.getElementById("formaPago");
  const fechaNacimiento = document.getElementById("fechaNacimiento");
  const fechaNacimientoConyuge = document.getElementById("clienteConyugeFechaNacimiento");
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

  tipoSolicitud.addEventListener("change", actualizarFormularioDinamico);
  tipoOperacion.addEventListener("change", actualizarFormularioDinamico);
  formaPago.addEventListener("change", actualizarFinanciamiento);
  fechaNacimiento.addEventListener("change", () => calcularEdadDesde("fechaNacimiento", "edadCliente"));
  fechaNacimientoConyuge.addEventListener("change", () => calcularEdadDesde("clienteConyugeFechaNacimiento", "clienteConyugeEdad"));
  precioTotal.addEventListener("input", recalcularImportes);
  enganche.addEventListener("input", recalcularImportes);
  mensualidades.addEventListener("input", recalcularImportes);

  document.getElementById("btnReset").addEventListener("click", () => {
    form.reset();
    inicializarFecha();
    copiarUsuarioEnVendedor();
    actualizarFormularioDinamico();
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
    actualizarFormularioDinamico();
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

function actualizarFormularioDinamico() {
  const tipoSolicitud = document.getElementById("tipoSolicitud").value;
  const tipoOperacion = document.getElementById("tipoOperacion").value;
  const tipoVentaProcap = document.getElementById("tipoVentaProcap");
  const propiedadTipo = document.getElementById("propiedadTipo");
  const titulo = document.getElementById("tituloSolicitud");
  const descripcion = document.getElementById("ventaDescripcion");

  let ventaProcap = "";
  if (tipoSolicitud === "SERVICIO" && tipoOperacion === "PREVISION") ventaProcap = "SERVICIO PREVISION";
  if (tipoSolicitud === "SERVICIO" && tipoOperacion === "USO INMEDIATO") ventaProcap = "SERVICIO UI";
  if (tipoSolicitud === "LOTE" && tipoOperacion === "PREVISION") ventaProcap = "CEMENTERIO PREVISION";
  if (tipoSolicitud === "LOTE" && tipoOperacion === "USO INMEDIATO") ventaProcap = "CEMENTERIO UI";
  if (tipoSolicitud === "NICHO" && tipoOperacion === "PREVISION") ventaProcap = "NICHO PREVISION";
  if (tipoSolicitud === "NICHO" && tipoOperacion === "USO INMEDIATO") ventaProcap = "NICHO UI";
  tipoVentaProcap.value = ventaProcap;

  const esServicio = tipoSolicitud === "SERVICIO";
  const esPropiedad = tipoSolicitud === "LOTE" || tipoSolicitud === "NICHO";

  mostrarGrupo("servicioFields", esServicio);
  mostrarGrupo("propiedadFields", esPropiedad);
  mostrarGrupo("referenciasSection", esPropiedad);
  mostrarGrupo("financieraSection", esPropiedad);
  mostrarGrupo("sustitutoSection", esServicio);

  if (tipoSolicitud === "SERVICIO") {
    titulo.textContent = "Solicitud de Compra Servicio Funerario";
    descripcion.textContent = "Datos del servicio funerario contratado.";
  } else if (tipoSolicitud === "LOTE") {
    titulo.textContent = "Solicitud de Compra Lote Funerario";
    descripcion.textContent = "Datos del lote contratado.";
  } else if (tipoSolicitud === "NICHO") {
    titulo.textContent = "Solicitud de Compra Nicho";
    descripcion.textContent = "Datos del nicho contratado.";
  } else {
    titulo.textContent = "Solicitud de Venta";
    descripcion.textContent = "Selecciona el tipo de solicitud para capturar la información correspondiente.";
  }

  propiedadTipo.innerHTML = '<option value="">Selecciona</option>';
  if (tipoSolicitud === "LOTE") {
    agregarOpcion(propiedadTipo, "JARDIN");
    agregarOpcion(propiedadTipo, "VIP");
    agregarOpcion(propiedadTipo, "OSARIOS");
  } else if (tipoSolicitud === "NICHO") {
    agregarOpcion(propiedadTipo, "NICHO");
    agregarOpcion(propiedadTipo, "OSARIO");
    agregarOpcion(propiedadTipo, "OTRO");
  }

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

function agregarOpcion(select, valor) {
  const opcion = document.createElement("option");
  opcion.value = valor;
  opcion.textContent = valor;
  select.appendChild(opcion);
}

function calcularEdadDesde(fechaId, salidaId) {
  const valor = document.getElementById(fechaId).value;
  const salida = document.getElementById(salidaId);
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
