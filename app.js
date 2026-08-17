let borradorActual = {
  itemId: "",
  folio: ""
};

document.addEventListener("DOMContentLoaded", async () => {
  const loginButton = document.getElementById("btnLogin");
  const logoutButton = document.getElementById("btnLogout");
  const form = document.getElementById("solicitudForm");
  const tipoSolicitud = document.getElementById("tipoSolicitud");
  const tipoOperacion = document.getElementById("tipoOperacion");
  const formaPago = document.getElementById("formaPago");
  const laboralOcupacion = document.getElementById("laboralOcupacion");
  const fechaNacimiento = document.getElementById("fechaNacimiento");
  const fechaNacimientoConyuge = document.getElementById("clienteConyugeFechaNacimiento");
  const precioTotal = document.getElementById("precioTotal");
  const enganche = document.getElementById("enganche");
  const mensualidades = document.getElementById("mensualidades");
  const saveButton = asegurarBotonGuardarBorrador();

  configurarInformacionLaboral();
  aplicarEjemplosCampos();

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
  laboralOcupacion.addEventListener("change", actualizarInformacionLaboral);
  fechaNacimiento.addEventListener("change", () => calcularEdadDesde("fechaNacimiento", "edadCliente"));
  fechaNacimientoConyuge.addEventListener("change", () => calcularEdadDesde("clienteConyugeFechaNacimiento", "clienteConyugeEdad"));
  precioTotal.addEventListener("input", recalcularImportes);
  enganche.addEventListener("input", recalcularImportes);
  mensualidades.addEventListener("input", recalcularImportes);
  saveButton.addEventListener("click", guardarBorrador);

  document.getElementById("btnReset").addEventListener("click", () => {
    form.reset();
    borradorActual = { itemId: "", folio: "" };
    inicializarFecha();
    copiarUsuarioEnVendedor();
    actualizarFormularioDinamico();
    actualizarFinanciamiento();
    actualizarInformacionLaboral();
    recalcularImportes();
    actualizarFolio("PENDIENTE");
    mostrarMensaje("Formulario limpiado. Puedes guardar un nuevo borrador cuando captures los datos iniciales.");
  });

  form.addEventListener("submit", (event) => {
    event.preventDefault();
    actualizarRequiredVisibles();
    actualizarInformacionLaboral();

    if (!form.checkValidity()) {
      form.reportValidity();
      mostrarMensaje("Faltan campos obligatorios por completar.", "error");
      return;
    }

    if (formaPago.value === "CREDITO" && !document.getElementById("conformidadFinanciamiento").checked) {
      mostrarMensaje("Debes confirmar la conformidad del financiamiento.", "error");
      return;
    }

    mostrarMensaje("Validación correcta. La solicitud está completa para continuar al flujo de Vo.Bo.", "ok");
  });

  try {
    await window.solicitudVentaAuth.initialize();
    inicializarFecha();
    copiarUsuarioEnVendedor();
    actualizarFormularioDinamico();
    actualizarFinanciamiento();
    actualizarInformacionLaboral();
    recalcularImportes();
    loginButton.disabled = false;

    if (window.solicitudVentaAuth.getUser()) {
      await probarBackendSeguro();
    }

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

function asegurarBotonGuardarBorrador() {
  let button = document.getElementById("btnSaveDraft");
  if (button) return button;

  const validateButton = document.getElementById("btnValidate");
  button = document.createElement("button");
  button.id = "btnSaveDraft";
  button.type = "button";
  button.className = "draft-button inline-button";
  button.textContent = "Guardar borrador";
  validateButton.parentElement.insertBefore(button, validateButton);
  return button;
}

function configurarInformacionLaboral() {
  const ocupacion = document.getElementById("laboralOcupacion");
  if (!ocupacion) return;

  ["HOGAR", "NO APLICA"].forEach((valorOpcion) => {
    const existe = Array.from(ocupacion.options).some((option) => option.value === valorOpcion);
    if (!existe) agregarOpcion(ocupacion, valorOpcion);
  });

  const etiquetaOcupacion = ocupacion.closest("label");
  const grid = etiquetaOcupacion?.parentElement;
  if (grid && etiquetaOcupacion && grid.firstElementChild !== etiquetaOcupacion) {
    grid.insertBefore(etiquetaOcupacion, grid.firstElementChild);
  }
}

function ocupacionLaboralSinDetalle() {
  return ["HOGAR", "OTRO", "NO APLICA"].includes(valor("laboralOcupacion"));
}

function actualizarInformacionLaboral() {
  const omitirDetalle = ocupacionLaboralSinDetalle();
  const idsDetalle = [
    "laboralEmpresa",
    "laboralDomicilio",
    "laboralNumero",
    "laboralColonia",
    "laboralEstado",
    "laboralCp",
    "laboralCiudad",
    "laboralMunicipio",
    "laboralTelefono",
    "laboralExtension",
    "laboralActividad",
    "laboralSector",
    "laboralAntiguedad",
    "laboralAntiguedadAnterior"
  ];

  idsDetalle.forEach((id) => {
    const control = document.getElementById(id);
    const etiqueta = control?.closest("label");
    if (!control || !etiqueta) return;

    etiqueta.hidden = omitirDetalle;
    control.required = !omitirDetalle;

    if (omitirDetalle) {
      control.value = "";
    }
  });
}

function aplicarEjemplosCampos() {
  const ejemplos = {
    referencia: "Ej. PLATINO - 001 - A",
    origenVenta: "Ej. RECOMENDACION / REDES SOCIALES / VISITA",
    clienteNumeroId: "Ej. 1234567890",
    clienteRfc: "Ej. GUGA920101ABC",
    clienteCurp: "Ej. GUGA920101HNLRRB01",
    clienteApellidoPaterno: "Ej. GONZALEZ",
    clienteApellidoMaterno: "Ej. MARTINEZ",
    clienteNombres: "Ej. JUAN CARLOS",
    clienteDomicilio: "Ej. AV. UNIVERSIDAD",
    clienteNumero: "Ej. 1250 INT. 4",
    clienteColonia: "Ej. MITRAS CENTRO",
    clienteEstado: "Ej. NUEVO LEON",
    clienteCp: "Ej. 64000",
    clienteCiudad: "Ej. MONTERREY",
    clienteMunicipio: "Ej. MONTERREY",
    clienteTelefono: "Ej. 81 1234 5678",
    clienteCelular: "Ej. 81 1234 5678",
    clienteCorreo: "Ej. cliente@correo.com",
    clienteDomicilioAnterior: "Ej. CALLE HIDALGO 123, CENTRO",
    clienteAntiguedadDomicilioAnterior: "Ej. 3 AÑOS",
    clienteEdadesDependientes: "Ej. 5, 9, 14",
    clienteConyuge: "Ej. MARIA LOPEZ GARCIA / NO APLICA",
    laboralEmpresa: "Ej. EMPRESA ABC, S.A. DE C.V.",
    laboralDomicilio: "Ej. AV. CONSTITUCION",
    laboralNumero: "Ej. 450",
    laboralColonia: "Ej. OBISPADO",
    laboralEstado: "Ej. NUEVO LEON",
    laboralCp: "Ej. 64060",
    laboralCiudad: "Ej. MONTERREY",
    laboralMunicipio: "Ej. MONTERREY",
    laboralTelefono: "Ej. 81 1234 5678",
    laboralExtension: "Ej. 125 / SIN EXTENSION",
    laboralActividad: "Ej. ADMINISTRACION",
    laboralAntiguedad: "Ej. 5 AÑOS 3 MESES",
    laboralAntiguedadAnterior: "Ej. 2 AÑOS / NO APLICA",
    sustitutoNombre: "Ej. ANA MARTINEZ LOPEZ",
    sustitutoDomicilio: "Ej. AV. LEONES 1500, CUMBRES",
    sustitutoTelefono: "Ej. 81 1234 5678",
    sustitutoParentesco: "Ej. HIJA",
    sustitutoId: "Ej. INE 1234567890",
    referencia1Nombre: "Ej. PEDRO GONZALEZ MARTINEZ",
    referencia1Telefono: "Ej. 81 1234 5678",
    referencia1Celular: "Ej. 81 9876 5432",
    referencia2Nombre: "Ej. LAURA MARTINEZ LOPEZ",
    referencia2Telefono: "Ej. 81 2345 6789",
    referencia2Celular: "Ej. 81 8765 4321",
    banco1Nombre: "Ej. BBVA",
    banco1TipoCuenta: "Ej. NOMINA / DEBITO / CHEQUES",
    banco1NumeroCuenta: "Ej. 1234567890",
    banco2Nombre: "Ej. BANORTE / NO APLICA",
    banco2TipoCuenta: "Ej. DEBITO / NO APLICA",
    banco2NumeroCuenta: "Ej. 9876543210 / NO APLICA",
    paquete: "Ej. PLAN PREVISION PLATINO",
    descripcionVenta: "Ej. LOTE PLATINO, SECCION 001, NUMERO 025",
    servicioAtaud: "Ej. MADERA MODELO ITALIA",
    servicioUrna: "Ej. URNA DE MADERA / NO APLICA",
    servicioDuracion: "Ej. 12 HORAS",
    propiedadSeccion: "Ej. PLATINO",
    propiedadManzana: "Ej. 001",
    propiedadNumero: "Ej. 025",
    propiedadClave: "Ej. PLATINO-001-025"
  };

  Object.entries(ejemplos).forEach(([id, placeholder]) => {
    const control = document.getElementById(id);
    if (control) control.placeholder = placeholder;
  });

  document.querySelectorAll('input[type="text"], input[type="tel"], input[type="email"], textarea').forEach((control) => {
    if (!control.placeholder && !control.readOnly) {
      control.placeholder = "Ej. captura la información correspondiente";
    }
  });
}

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

function actualizarFolio(folio) {
  const control = document.querySelector(".folio-box strong");
  if (control) control.textContent = folio || "PENDIENTE";
}

function valor(id) {
  const control = document.getElementById(id);
  if (!control) return "";
  return String(control.value ?? "").trim();
}

function numeroValor(id) {
  const texto = valor(id);
  if (texto === "") return null;
  const numero = Number(texto);
  return Number.isFinite(numero) ? numero : null;
}

function construirPayloadBorrador() {
  const sinDetalleLaboral = ocupacionLaboralSinDetalle();

  return {
    accion: "guardar_borrador",
    itemId: borradorActual.itemId,
    folio: borradorActual.folio,
    tipoSolicitud: valor("tipoSolicitud"),
    tipoOperacion: valor("tipoOperacion"),
    tipoVentaProcap: valor("tipoVentaProcap"),
    fechaSolicitud: valor("fechaSolicitud"),
    referencia: valor("referencia"),
    origenVenta: valor("origenVenta"),
    lugar: valor("lugar"),

    clienteTipoId: valor("clienteTipoId"),
    clienteNumeroId: valor("clienteNumeroId"),
    clienteRfc: valor("clienteRfc"),
    clienteCurp: valor("clienteCurp"),
    clienteApellidoPaterno: valor("clienteApellidoPaterno"),
    clienteApellidoMaterno: valor("clienteApellidoMaterno"),
    clienteNombres: valor("clienteNombres"),
    clienteEdad: numeroValor("edadCliente"),
    clienteFechaNacimiento: valor("fechaNacimiento"),
    clienteSexo: valor("clienteSexo"),
    clienteEstadoCivil: valor("clienteEstadoCivil"),
    clienteNacionalidad: valor("clienteNacionalidad"),
    clienteRegimenMatrimonial: valor("clienteRegimenMatrimonial"),
    clienteVivienda: valor("clienteVivienda"),
    clienteEscolaridad: valor("clienteEscolaridad"),
    clienteDomicilio: valor("clienteDomicilio"),
    clienteNumero: valor("clienteNumero"),
    clienteColonia: valor("clienteColonia"),
    clienteEstado: valor("clienteEstado"),
    clienteCp: valor("clienteCp"),
    clienteCiudad: valor("clienteCiudad"),
    clienteMunicipio: valor("clienteMunicipio"),
    clienteTelefono: valor("clienteTelefono"),
    clienteCelular: valor("clienteCelular"),
    clienteCorreo: valor("clienteCorreo"),
    clienteDomicilioAnterior: valor("clienteDomicilioAnterior"),
    clienteAntiguedadDomicilioAnterior: valor("clienteAntiguedadDomicilioAnterior"),
    clienteDependientes: numeroValor("clienteDependientes"),
    clienteEdadesDependientes: valor("clienteEdadesDependientes"),
    clienteConyuge: valor("clienteConyuge"),
    clienteConyugeFechaNacimiento: valor("clienteConyugeFechaNacimiento"),
    clienteConyugeEdad: numeroValor("clienteConyugeEdad"),

    laboralEmpresa: sinDetalleLaboral ? "" : valor("laboralEmpresa"),
    laboralOcupacion: valor("laboralOcupacion"),
    laboralDomicilio: sinDetalleLaboral ? "" : valor("laboralDomicilio"),
    laboralNumero: sinDetalleLaboral ? "" : valor("laboralNumero"),
    laboralColonia: sinDetalleLaboral ? "" : valor("laboralColonia"),
    laboralCiudad: sinDetalleLaboral ? "" : valor("laboralCiudad"),
    laboralMunicipio: sinDetalleLaboral ? "" : valor("laboralMunicipio"),
    laboralEstado: sinDetalleLaboral ? "" : valor("laboralEstado"),
    laboralCp: sinDetalleLaboral ? "" : valor("laboralCp"),
    laboralTelefono: sinDetalleLaboral ? "" : valor("laboralTelefono"),
    laboralExtension: sinDetalleLaboral ? "" : valor("laboralExtension"),
    laboralActividad: sinDetalleLaboral ? "" : valor("laboralActividad"),
    laboralSector: sinDetalleLaboral ? "" : valor("laboralSector"),
    laboralAntiguedad: sinDetalleLaboral ? "" : valor("laboralAntiguedad"),
    laboralAntiguedadAnterior: sinDetalleLaboral ? "" : valor("laboralAntiguedadAnterior"),

    sustitutoNombre: valor("sustitutoNombre"),
    sustitutoDomicilio: valor("sustitutoDomicilio"),
    sustitutoEdad: numeroValor("sustitutoEdad"),
    sustitutoTelefono: valor("sustitutoTelefono"),
    sustitutoParentesco: valor("sustitutoParentesco"),
    sustitutoId: valor("sustitutoId")
  };
}

async function guardarBorrador() {
  const saveButton = document.getElementById("btnSaveDraft");
  const payload = construirPayloadBorrador();

  if (!payload.tipoSolicitud || !payload.tipoOperacion || !payload.tipoVentaProcap || !payload.fechaSolicitud) {
    mostrarMensaje("Para crear el borrador selecciona Tipo de solicitud, Tipo de operación y Fecha.", "error");
    return;
  }

  try {
    saveButton.disabled = true;
    mostrarMensaje(borradorActual.itemId ? "Actualizando borrador en SharePoint..." : "Guardando borrador en SharePoint...");

    const token = await window.solicitudVentaAuth.getBackendAccessToken();
    if (!token) return;

    const response = await fetch("/api/solicitud-venta/borrador.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Authorization": `Bearer ${token}`
      },
      body: JSON.stringify(payload)
    });

    const resultado = await response.json().catch(() => null);
    if (!response.ok || !resultado?.ok) {
      throw new Error(resultado?.message || resultado?.error || `HTTP ${response.status}`);
    }

    borradorActual.itemId = String(resultado.itemId || borradorActual.itemId || "");
    borradorActual.folio = String(resultado.folio || borradorActual.folio || "");
    actualizarFolio(borradorActual.folio);

    mostrarMensaje(
      borradorActual.itemId
        ? `Borrador ${borradorActual.folio} guardado correctamente en SharePoint.`
        : "Borrador guardado correctamente en SharePoint.",
      "ok"
    );
  } catch (error) {
    console.error("Error al guardar borrador:", error);
    mostrarMensaje(`No fue posible guardar el borrador: ${error.message || error}`, "error");
  } finally {
    saveButton.disabled = false;
  }
}

function mostrarMensaje(texto, tipo = "") {
  const mensaje = document.getElementById("formMessage");
  mensaje.textContent = texto;
  mensaje.className = `form-message ${tipo}`.trim();
}

async function probarBackendSeguro() {
  try {
    mostrarMensaje("Validando conexión segura con el servidor...");

    const token = await window.solicitudVentaAuth.getBackendAccessToken();

    if (!token) {
      return;
    }

    const response = await fetch("/api/solicitud-venta/borrador.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Authorization": `Bearer ${token}`
      },
      body: JSON.stringify({
        prueba: true,
        fecha: new Date().toISOString()
      })
    });

    let resultado;

    try {
      resultado = await response.json();
    } catch (error) {
      throw new Error(
        `El servidor respondió HTTP ${response.status}, pero no devolvió JSON válido.`
      );
    }

    if (!response.ok || !resultado.ok) {
      const detalle =
        resultado?.message ||
        resultado?.error ||
        `HTTP ${response.status}`;

      throw new Error(detalle);
    }

    console.log("Backend Solicitud Venta:", resultado);

    const usuario = resultado.usuario || {};
    const identificacion =
      usuario.correo ||
      usuario.nombre ||
      "usuario autenticado";

    mostrarMensaje(
      `Conexión segura validada correctamente para ${identificacion}.`,
      "ok"
    );
  } catch (error) {
    console.error("Error al probar backend seguro:", error);

    mostrarMensaje(
      `No fue posible validar la conexión segura: ${error.message || error}`,
      "error"
    );
  }
}
