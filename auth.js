const SOLICITUD_BORRADOR_PREFIX = "solicitudVenta:borradorActivo:v1:";
let solicitudVentaUsuario = null;

function obtenerSesionPortal() {
  const sesion = window.SOLICITUD_PORTAL_SESSION;
  if (!sesion?.authenticated || !sesion?.user) return null;

  const email = String(sesion.user.email || "").trim().toLowerCase();
  if (!email) return null;

  return {
    homeAccountId: String(sesion.user.id || email),
    localAccountId: String(sesion.user.id || ""),
    name: String(sesion.user.name || "Usuario"),
    username: email,
    tenantId: ""
  };
}

function limpiarBorradorActivoSiEsNuevaSolicitud(account) {
  const params = new URLSearchParams(window.location.search);
  if (params.get("nuevo") !== "1") return;

  const correo = String(account?.username || "").trim().toLowerCase();
  if (correo) {
    try {
      localStorage.removeItem(`${SOLICITUD_BORRADOR_PREFIX}${correo}`);
    } catch (_error) {
      // La captura puede continuar aunque el navegador bloquee localStorage.
    }
  }

  params.delete("nuevo");
  const query = params.toString();
  const limpia = `${window.location.pathname}${query ? `?${query}` : ""}${window.location.hash || ""}`;
  window.history.replaceState({}, "", limpia);
}

async function inicializarAutenticacionSolicitudVenta() {
  solicitudVentaUsuario = obtenerSesionPortal();

  if (!solicitudVentaUsuario) {
    const returnTo = encodeURIComponent(`${window.location.pathname}${window.location.search}${window.location.hash}`);
    window.location.replace(`/login.php?return_to=${returnTo}`);
    return;
  }

  limpiarBorradorActivoSiEsNuevaSolicitud(solicitudVentaUsuario);
  renderEstadoSesion();
}

async function iniciarSesionSolicitudVenta() {
  const returnTo = encodeURIComponent(`${window.location.pathname}${window.location.search}${window.location.hash}`);
  window.location.assign(`/login.php?return_to=${returnTo}`);
}

async function cerrarSesionSolicitudVenta() {
  window.location.assign("/logout.php");
}

function obtenerUsuarioSolicitudVenta() {
  return solicitudVentaUsuario;
}

async function obtenerAccessTokenBackend() {
  if (!solicitudVentaUsuario) {
    throw new Error("La sesion del Portal no esta activa.");
  }

  // Los endpoints de Solicitud de Venta validan la cookie HttpOnly de la sesion
  // del Portal en el servidor. Este valor solo mantiene compatibilidad con el
  // codigo cliente que ya agrega el encabezado Authorization.
  return "PORTAL_SESSION";
}

function renderEstadoSesion() {
  const account = obtenerUsuarioSolicitudVenta();
  const loginPanel = document.getElementById("loginPanel");
  const appPanel = document.getElementById("appPanel");
  const userName = document.getElementById("userName");
  const userEmail = document.getElementById("userEmail");

  if (!account) {
    if (loginPanel) loginPanel.hidden = false;
    if (appPanel) appPanel.hidden = true;
    return;
  }

  if (loginPanel) loginPanel.hidden = true;
  if (appPanel) appPanel.hidden = false;
  if (userName) userName.textContent = account.name || "Usuario";
  if (userEmail) userEmail.textContent = account.username || "";
}

window.solicitudVentaAuth = {
  initialize: inicializarAutenticacionSolicitudVenta,
  login: iniciarSesionSolicitudVenta,
  logout: cerrarSesionSolicitudVenta,
  getUser: obtenerUsuarioSolicitudVenta,
  getBackendAccessToken: obtenerAccessTokenBackend
};
