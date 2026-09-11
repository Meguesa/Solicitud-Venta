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

  integrarCamposFaltantes();
  configurarInformacionLaboral();
  alinearCatalogosFormulario();
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
  document.getElementById("precioLista")?.addEventListener("input", recalcularImportes);
  document.getElementById("bonificacion")?.addEventListener("input", recalcularImportes);
  document.getElementById("interesFinanciamiento")?.addEventListener("input", recalcularImportes);
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

    if (window.solicitudVentaAuth.getUser()) await probarBackendSeguro();

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

function integrarCamposFaltantes() {
  if (document.getElementById("tipoContrato")) return;

  const paquete = document.getElementById("paquete");
  if (paquete) {
    const labelPaquete = paquete.closest("label");
    const grid = labelPaquete?.parentElement;
    if (grid && labelPaquete) {
      const label = document.createElement("label");
      label.innerHTML = 'Tipo de contrato<input id="tipoContrato" type="text" readonly required>';
      grid.insertBefore(label, labelPaquete);
    }
  }

  const financiamiento = document.getElementById("financiamientoFields");
  if (financiamiento) {
    const extra = document.createElement("div");
    extra.id = "financiamientoDetalleExtra";
    extra.className = "form-grid grid-4";
    extra.innerHTML = `
      <label>Precio de lista<input id="precioLista" type="number" min="0" step="0.01" data-required-when-visible></label>
      <label>Bonificación<input id="bonificacion" type="number" min="0" step="0.01" data-required-when-visible></label>
      <label>Monto a financiar<input id="montoFinanciar" type="number" min="0" step="0.01" readonly data-required-when-visible></label>
      <label>Interés de financiamiento (%)<input id="interesFinanciamiento" type="number" min="0" step="0.01" data-required-when-visible></label>
      <label>Periodo de pagos<select id="periodoPagos" data-required-when-visible><option value="">Selecciona</option><option>MENSUAL</option><option>QUINCENAL</option><option>SEMANAL</option><option>OTRO</option></select></label>
      <label>Pagos anuales<input id="pagosAnuales" type="number" min="1" step="1" value="12" data-required-when-visible></label>
      <label>Total a pagar<input id="totalPagar" type="number" min="0" step="0.01" readonly data-required-when-visible></label>
    `;
    financiamiento.insertBefore(extra, financiamiento.lastElementChild);
  }

  const ventaSection = document.getElementById("ventaDescripcion")?.closest("section");
  if (ventaSection) {
    const ui = document.createElement("section");
    ui.id = "usoInmediatoSection";
    ui.className = "form-section";
    ui.hidden = true;
    ui.innerHTML = `
      <div class="section-title"><span>UI</span><div><h3>Información de Uso Inmediato</h3><p>Datos del finado y corresponsable para solicitudes de uso inmediato.</p></div></div>
      <div class="form-grid grid-4">
        <label>Nombres del finado<input id="finadoNombres" type="text" data-required-when-visible></label>
        <label>Apellidos del finado<input id="finadoApellidos" type="text" data-required-when-visible></label>
        <label>Sexo<select id="finadoSexo" data-required-when-visible><option value="">Selecciona</option><option>MASCULINO</option><option>FEMENINO</option><option>OTRO</option></select></label>
        <label>Estatura (m)<input id="finadoEstatura" type="number" min="0" step="0.01" data-required-when-visible></label>
        <label>Peso (kg)<input id="finadoPeso" type="number" min="0" step="0.01" data-required-when-visible></label>
        <label>Parentesco con titular<input id="finadoParentescoTitular" type="text" data-required-when-visible></label>
        <label>Causa de defunción<input id="finadoCausaDefuncion" type="text" data-required-when-visible></label>
        <label>Procedencia<input id="finadoProcedencia" type="text" data-required-when-visible></label>
        <label>Nombres del corresponsable<input id="uiCorresponsableNombres" type="text" data-required-when-visible></label>
        <label>Apellidos del corresponsable<input id="uiCorresponsableApellidos" type="text" data-required-when-visible></label>
        <label>Parentesco con finado<input id="uiCorresponsableParentesco" type="text" data-required-when-visible></label>
        <label>Celular del corresponsable<input id="uiCorresponsableCelular" type="tel" data-required-when-visible></label>
        <label class="span-2">Observaciones de uso inmediato<textarea id="uiObservaciones" rows="3" data-required-when-visible></textarea></label>
      </div>`;
    ventaSection.parentElement.insertBefore(ui, ventaSection);
  }

  const vendedor = document.getElementById("vendedorNombre")?.closest(".form-section");
  if (vendedor) {
    const docs = document.createElement("section");
    docs.id = "documentosSection";
    docs.className = "form-section";
    docs.innerHTML = `
      <div class="section-title"><span>D</span><div><h3>Documentación recibida</h3><p>Marca los documentos entregados por el cliente al momento de la solicitud.</p></div></div>
      <div class="form-grid grid-2">
        <label class="check-line"><input id="documentoIdTitular" type="checkbox"> Identificación oficial del titular</label>
        <label class="check-line"><input id="documentoIdSustituto" type="checkbox"> Identificación del titular sustituto</label>
        <label class="check-line"><input id="documentoComprobanteDomicilio" type="checkbox"> Comprobante de domicilio</label>
        <label class="check-line"><input id="documentoComprobantePago" type="checkbox"> Comprobante de pago</label>
        <label class="span-2">Otros documentos<textarea id="documentoOtros" rows="2" required placeholder="Ej. NO APLICA / ACTA / DOCUMENTO ADICIONAL"></textarea></label>
      </div>`;

    const obs = document.createElement("section");
    obs.id = "observacionesSection";
    obs.className = "form-section";
    obs.innerHTML = `
      <div class="section-title"><span>O</span><div><h3>Observaciones de la solicitud</h3><p>Información adicional que deba conocer el coordinador, asistente o cobranza.</p></div></div>
      <label>Observaciones<textarea id="observacionesSolicitud" rows="4" required placeholder="Ej. SIN OBSERVACIONES"></textarea></label>`;

    vendedor.parentElement.insertBefore(docs, vendedor);
    vendedor.parentElement.insertBefore(obs, vendedor);
  }
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
  if (grid && etiquetaOcupacion && grid.firstElementChild !== etiquetaOcupacion) grid.insertBefore(etiquetaOcupacion, grid.firstElementChild);
}

function alinearCatalogosFormulario() {
  const escolaridad = document.getElementById("clienteEscolaridad");
  if (escolaridad) {
    Array.from(escolaridad.options).forEach((option) => {
      if (option.value === "OTRA" || option.textContent?.trim() === "OTRA") {
        option.value = "OTRO";
        option.textContent = "OTRO";
      }
    });
  }
}

function ocupacionLaboralSinDetalle() {
  return ["HOGAR", "OTRO", "NO APLICA"].includes(valor("laboralOcupacion"));
}

function actualizarInformacionLaboral() {
  const omitirDetalle = ocupacionLaboralSinDetalle();
  const idsDetalle = [
    "laboralEmpresa", "laboralDomicilio", "laboralNumero", "laboralColonia", "laboralEstado", "laboralCp",
    "laboralCiudad", "laboralMunicipio", "laboralTelefono", "laboralExtension", "laboralActividad", "laboralSector",
    "laboralAntiguedad", "laboralAntiguedadAnterior"
  ];

  idsDetalle.forEach((id) => {
    const control = document.getElementById(id);
    const etiqueta = control?.closest("label");
    if (!control || !etiqueta) return;
    etiqueta.hidden = omitirDetalle;
    control.required = !omitirDetalle;
    if (omitirDetalle) control.value = "";
  });
}

function aplicarEjemplosCampos() {
  const ejemplos = {
    referencia: "Ej. PLATINO - 001 - A",
    origenVenta: "Ej. RECOMENDACION / REDES SOCIALES / VISITA",
    clienteNumeroId: "Ej. 2099510770",
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
    clienteCelular: "Ej. 81 9876 5432",
    clienteCorreo: "Ej. cliente@correo.com",
    clienteDomicilioAnterior: "Ej. CALLE HIDALGO 123, CENTRO",
    clienteAntiguedadDomicilioAnterior: "Ej. 3 AÑOS",
    clienteEdadesDependientes: "Ej. 5, 9, 14 / NO APLICA",
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
    propiedadClave: "Ej. PLATINO-001-025",
    finadoNombres: "Ej. JOSE MANUEL",
    finadoApellidos: "Ej. GARCIA LOPEZ",
    finadoParentescoTitular: "Ej. PADRE",
    finadoCausaDefuncion: "Ej. CAUSA INDICADA EN CERTIFICADO",
    finadoProcedencia: "Ej. HOSPITAL / DOMICILIO / SEMEFO",
    uiCorresponsableNombres: "Ej. MARIA ELENA",
    uiCorresponsableApellidos: "Ej. GARCIA MARTINEZ",
    uiCorresponsableParentesco: "Ej. HIJA",
    uiCorresponsableCelular: "Ej. 81 1234 5678",
    uiObservaciones: "Ej. SIN OBSERVACIONES / INDICACIONES DEL SERVICIO"
  };

  Object.entries(ejemplos).forEach(([id, placeholder]) => {
    const control = document.getElementById(id);
    if (control) control.placeholder = placeholder;
  });

  document.querySelectorAll('input[type="text"], input[type="tel"], input[type="email"], textarea').forEach((control) => {
    if (!control.placeholder && !control.readOnly) control.placeholder = "Ej. captura la información correspondiente";
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
  const tipoContrato = document.getElementById("tipoContrato");
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
  if (tipoContrato) tipoContrato.value = ventaProcap;

  const esServicio = tipoSolicitud === "SERVICIO";
  const esPropiedad = tipoSolicitud === "LOTE" || tipoSolicitud === "NICHO";
  const esUsoInmediato = tipoOperacion === "USO INMEDIATO";

  mostrarGrupo("servicioFields", esServicio);
  mostrarGrupo("propiedadFields", esPropiedad);
  mostrarGrupo("referenciasSection", esPropiedad);
  mostrarGrupo("financieraSection", esPropiedad);
  mostrarGrupo("sustitutoSection", esServicio);
  mostrarGrupo("usoInmediatoSection", esUsoInmediato);

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

  const etiquetaPropiedad = propiedadTipo.closest("label");
  if (etiquetaPropiedad && etiquetaPropiedad.firstChild) etiquetaPropiedad.firstChild.textContent = "Subtipo";

  propiedadTipo.innerHTML = '<option value="">Selecciona</option>';
  if (tipoSolicitud === "LOTE") ["BRONCE", "ORO", "PLATA", "PLATINO", "SJV", "SMV", "SPV"].forEach((subtipo) => agregarOpcion(propiedadTipo, subtipo));
  if (tipoSolicitud === "NICHO") ["PLN", "SPN"].forEach((subtipo) => agregarOpcion(propiedadTipo, subtipo));

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

function agregarOpcion(select, valorOpcion) {
  const opcion = document.createElement("option");
  opcion.value = valorOpcion;
  opcion.textContent = valorOpcion;
  select.appendChild(opcion);
}

function calcularEdadDesde(fechaId, salidaId) {
  const valorFecha = document.getElementById(fechaId).value;
  const salida = document.getElementById(salidaId);
  if (!valorFecha) {
    salida.value = "";
    return;
  }

  const nacimiento = new Date(`${valorFecha}T00:00:00`);
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

  const precioLista = Number(document.getElementById("precioLista")?.value || total || 0);
  const bonificacion = Number(document.getElementById("bonificacion")?.value || 0);
  const montoFinanciar = Math.max(0, precioLista - bonificacion - inicial);
  const interes = Number(document.getElementById("interesFinanciamiento")?.value || 0);
  const totalPagar = montoFinanciar * (1 + interes / 100);
  if (document.getElementById("montoFinanciar")) document.getElementById("montoFinanciar").value = montoFinanciar.toFixed(2);
  if (document.getElementById("totalPagar")) document.getElementById("totalPagar").value = totalPagar.toFixed(2);
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

function marcado(id) {
  return Boolean(document.getElementById(id)?.checked);
}

function construirPayloadBorrador() {
  const sinDetalleLaboral = ocupacionLaboralSinDetalle();

  return {
    accion: "guardar_borrador",
    itemId: borradorActual.itemId,
    folio: borradorActual.folio,
    tipoSolicitud: valor("tipoSolicitud"), tipoOperacion: valor("tipoOperacion"), tipoVentaProcap: valor("tipoVentaProcap"),
    fechaSolicitud: valor("fechaSolicitud"), referencia: valor("referencia"), origenVenta: valor("origenVenta"), lugar: valor("lugar"),
    tipoContrato: valor("tipoContrato"),

    clienteTipoId: valor("clienteTipoId"), clienteNumeroId: valor("clienteNumeroId"), clienteRfc: valor("clienteRfc"), clienteCurp: valor("clienteCurp"),
    clienteApellidoPaterno: valor("clienteApellidoPaterno"), clienteApellidoMaterno: valor("clienteApellidoMaterno"), clienteNombres: valor("clienteNombres"),
    clienteEdad: numeroValor("edadCliente"), clienteFechaNacimiento: valor("fechaNacimiento"), clienteSexo: valor("clienteSexo"), clienteEstadoCivil: valor("clienteEstadoCivil"),
    clienteNacionalidad: valor("clienteNacionalidad"), clienteRegimenMatrimonial: valor("clienteRegimenMatrimonial"), clienteVivienda: valor("clienteVivienda"),
    clienteEscolaridad: valor("clienteEscolaridad"), clienteDomicilio: valor("clienteDomicilio"), clienteNumero: valor("clienteNumero"), clienteColonia: valor("clienteColonia"),
    clienteEstado: valor("clienteEstado"), clienteCp: valor("clienteCp"), clienteCiudad: valor("clienteCiudad"), clienteMunicipio: valor("clienteMunicipio"),
    clienteTelefono: valor("clienteTelefono"), clienteCelular: valor("clienteCelular"), clienteCorreo: valor("clienteCorreo"),
    clienteDomicilioAnterior: valor("clienteDomicilioAnterior"), clienteAntiguedadDomicilioAnterior: valor("clienteAntiguedadDomicilioAnterior"),
    clienteDependientes: numeroValor("clienteDependientes"), clienteEdadesDependientes: valor("clienteEdadesDependientes"), clienteConyuge: valor("clienteConyuge"),
    clienteConyugeFechaNacimiento: valor("clienteConyugeFechaNacimiento"), clienteConyugeEdad: numeroValor("clienteConyugeEdad"),

    laboralEmpresa: sinDetalleLaboral ? "" : valor("laboralEmpresa"), laboralOcupacion: valor("laboralOcupacion"),
    laboralDomicilio: sinDetalleLaboral ? "" : valor("laboralDomicilio"), laboralNumero: sinDetalleLaboral ? "" : valor("laboralNumero"),
    laboralColonia: sinDetalleLaboral ? "" : valor("laboralColonia"), laboralCiudad: sinDetalleLaboral ? "" : valor("laboralCiudad"),
    laboralMunicipio: sinDetalleLaboral ? "" : valor("laboralMunicipio"), laboralEstado: sinDetalleLaboral ? "" : valor("laboralEstado"),
    laboralCp: sinDetalleLaboral ? "" : valor("laboralCp"), laboralTelefono: sinDetalleLaboral ? "" : valor("laboralTelefono"),
    laboralExtension: sinDetalleLaboral ? "" : valor("laboralExtension"), laboralActividad: sinDetalleLaboral ? "" : valor("laboralActividad"),
    laboralSector: sinDetalleLaboral ? "" : valor("laboralSector"), laboralAntiguedad: sinDetalleLaboral ? "" : valor("laboralAntiguedad"),
    laboralAntiguedadAnterior: sinDetalleLaboral ? "" : valor("laboralAntiguedadAnterior"),

    sustitutoNombre: valor("sustitutoNombre"), sustitutoDomicilio: valor("sustitutoDomicilio"), sustitutoEdad: numeroValor("sustitutoEdad"),
    sustitutoTelefono: valor("sustitutoTelefono"), sustitutoParentesco: valor("sustitutoParentesco"), sustitutoId: valor("sustitutoId"),

    referencia1Nombre: valor("referencia1Nombre"), referencia1Telefono: valor("referencia1Telefono"), referencia1Celular: valor("referencia1Celular"),
    referencia2Nombre: valor("referencia2Nombre"), referencia2Telefono: valor("referencia2Telefono"), referencia2Celular: valor("referencia2Celular"),
    banco1Nombre: valor("banco1Nombre"), banco1TipoCuenta: valor("banco1TipoCuenta"), banco1NumeroCuenta: valor("banco1NumeroCuenta"),
    banco2Nombre: valor("banco2Nombre"), banco2TipoCuenta: valor("banco2TipoCuenta"), banco2NumeroCuenta: valor("banco2NumeroCuenta"),

    paquete: valor("paquete"), descripcionVenta: valor("descripcionVenta"), servicioTipo: valor("servicioTipo"), servicioAtaud: valor("servicioAtaud"),
    servicioUrna: valor("servicioUrna"), servicioDuracion: valor("servicioDuracion"), propiedadTipo: valor("propiedadTipo"),
    propiedadSeccion: valor("propiedadSeccion"), propiedadManzana: valor("propiedadManzana"), propiedadNumero: valor("propiedadNumero"), propiedadClave: valor("propiedadClave"),

    formaPago: valor("formaPago"), precioTotal: numeroValor("precioTotal"), enganche: numeroValor("enganche"), saldo: numeroValor("saldo"),
    metodoPago: valor("metodoPago"), mensualidades: numeroValor("mensualidades"), importeMensual: numeroValor("importeMensual"), diaPago: numeroValor("diaPago"),
    fechaPrimerVencimiento: valor("fechaPrimerVencimiento"), conformidadFinanciamiento: marcado("conformidadFinanciamiento"),
    precioLista: numeroValor("precioLista"), bonificacion: numeroValor("bonificacion"), montoFinanciar: numeroValor("montoFinanciar"),
    interesFinanciamiento: numeroValor("interesFinanciamiento"), periodoPagos: valor("periodoPagos"), pagosAnuales: numeroValor("pagosAnuales"), totalPagar: numeroValor("totalPagar"),

    documentoIdTitular: marcado("documentoIdTitular"), documentoIdSustituto: marcado("documentoIdSustituto"),
    documentoComprobanteDomicilio: marcado("documentoComprobanteDomicilio"), documentoComprobantePago: marcado("documentoComprobantePago"),
    documentoOtros: valor("documentoOtros"),

    finadoNombres: valor("finadoNombres"), finadoApellidos: valor("finadoApellidos"), finadoSexo: valor("finadoSexo"),
    finadoEstatura: numeroValor("finadoEstatura"), finadoPeso: numeroValor("finadoPeso"), finadoParentescoTitular: valor("finadoParentescoTitular"),
    finadoCausaDefuncion: valor("finadoCausaDefuncion"), finadoProcedencia: valor("finadoProcedencia"),
    uiCorresponsableNombres: valor("uiCorresponsableNombres"), uiCorresponsableApellidos: valor("uiCorresponsableApellidos"),
    uiCorresponsableParentesco: valor("uiCorresponsableParentesco"), uiCorresponsableCelular: valor("uiCorresponsableCelular"), uiObservaciones: valor("uiObservaciones"),
    observacionesSolicitud: valor("observacionesSolicitud")
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
      headers: { "Content-Type": "application/json", "Authorization": `Bearer ${token}` },
      body: JSON.stringify(payload)
    });

    const resultado = await response.json().catch(() => null);
    if (!response.ok || !resultado?.ok) throw new Error(resultado?.message || resultado?.error || `HTTP ${response.status}`);

    borradorActual.itemId = String(resultado.itemId || borradorActual.itemId || "");
    borradorActual.folio = String(resultado.folio || borradorActual.folio || "");
    actualizarFolio(borradorActual.folio);
    mostrarMensaje(`Borrador ${borradorActual.folio} guardado correctamente en SharePoint.`, "ok");
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
    if (!token) return;

    const response = await fetch("/api/solicitud-venta/borrador.php", {
      method: "POST",
      headers: { "Content-Type": "application/json", "Authorization": `Bearer ${token}` },
      body: JSON.stringify({ prueba: true, fecha: new Date().toISOString() })
    });

    const resultado = await response.json().catch(() => null);
    if (!response.ok || !resultado?.ok) throw new Error(resultado?.message || resultado?.error || `HTTP ${response.status}`);

    const usuario = resultado.usuario || {};
    const identificacion = usuario.correo || usuario.nombre || "usuario autenticado";
    mostrarMensaje(`Conexión segura validada correctamente para ${identificacion}.`, "ok");
  } catch (error) {
    console.error("Error al probar backend seguro:", error);
    mostrarMensaje(`No fue posible validar la conexión segura: ${error.message || error}`, "error");
  }
}
