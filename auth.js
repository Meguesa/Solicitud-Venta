const solicitudVentaConfig = window.SOLICITUD_VENTA_CONFIG;
let solicitudVentaMsal = null;

const SOLICITUD_AUTO_LOGIN_KEY = "solicitudVenta:autoLogin";
const SOLICITUD_LOGIN_HINT_KEY = "solicitudVenta:loginHint";
const SOLICITUD_NUEVA_KEY = "solicitudVenta:nuevaSolicitud";
const SOLICITUD_BORRADOR_PREFIX = "solicitudVenta:borradorActivo:v1:";

async function asegurarMsalDisponible() {
  if (window.msal?.PublicClientApplication) {
    return;
  }

  await new Promise((resolve, reject) => {
    const script = document.createElement("script");
    script.src = "https://cdn.jsdelivr.net/npm/@azure/msal-browser@2.39.0/lib/msal-browser.min.js";
    script.async = true;
    script.onload = resolve;
    script.onerror = () => reject(new Error("No fue posible cargar la libreria de autenticacion MSAL."));
    document.head.appendChild(script);
  });

  if (!window.msal?.PublicClientApplication) {
    throw new Error("MSAL no esta disponible despues de cargar el respaldo.");
  }
}

async function obtenerInstanciaMsal() {
  if (solicitudVentaMsal) {
    return solicitudVentaMsal;
  }

  if (!solicitudVentaConfig?.msal?.clientId || !solicitudVentaConfig?.msal?.authority || !solicitudVentaConfig?.msal?.redirectUri) {
    throw new Error("La configuracion de Entra ID para Solicitud de Venta esta incompleta.");
  }

  await asegurarMsalDisponible();

  solicitudVentaMsal = new window.msal.PublicClientApplication({
    auth: {
      clientId: solicitudVentaConfig.msal.clientId,
      authority: solicitudVentaConfig.msal.authority,
      redirectUri: solicitudVentaConfig.msal.redirectUri
    },
    cache: {
      cacheLocation: "localStorage",
      storeAuthStateInCookie: false
    }
  });

  return solicitudVentaMsal;
}

function scopesSolicitudVenta() {
  const scopes = ["openid", "profile", "email"];
  if (solicitudVentaConfig?.msal?.backendScope) {
    scopes.push(solicitudVentaConfig.msal.backendScope);
  }
  return scopes;
}

function leerSessionStorage(key) {
  try {
    return sessionStorage.getItem(key) || "";
  } catch (_error) {
    return "";
  }
}

function borrarSessionStorage(key) {
  try {
    sessionStorage.removeItem(key);
  } catch (_error) {
    // Sin almacenamiento de sesion, el acceso manual sigue disponible.
  }
}

async function intentarAutenticacionAutomatica(instancia) {
  if (instancia.getActiveAccount()) return;
  if (leerSessionStorage(SOLICITUD_AUTO_LOGIN_KEY) !== "1") return;

  // Consumimos la bandera antes de cualquier redireccion para impedir bucles.
  borrarSessionStorage(SOLICITUD_AUTO_LOGIN_KEY);

  const loginHint = leerSessionStorage(SOLICITUD_LOGIN_HINT_KEY).trim();
  const request = {
    scopes: scopesSolicitudVenta()
  };
  if (loginHint) request.loginHint = loginHint;

  try {
    const response = await instancia.ssoSilent(request);
    if (response?.account) {
      instancia.setActiveAccount(response.account);
      return;
    }
  } catch (error) {
    console.info("SSO silencioso no disponible; se continuara mediante redireccion de Microsoft.", error);
  }

  await instancia.loginRedirect(request);
}

function prepararNuevaSolicitudSiCorresponde(instancia) {
  if (leerSessionStorage(SOLICITUD_NUEVA_KEY) !== "1") return;

  const account = instancia.getActiveAccount();
  const correo = String(account?.username || "").trim().toLowerCase();
  if (!correo) return;

  // Nueva solicitud significa captura realmente nueva: no recuperar el ultimo
  // borrador activo del vendedor desde persistencia.js.
  try {
    localStorage.removeItem(`${SOLICITUD_BORRADOR_PREFIX}${correo}`);
  } catch (_error) {
    // Si localStorage no esta disponible, no bloqueamos la captura.
  }

  borrarSessionStorage(SOLICITUD_NUEVA_KEY);
  borrarSessionStorage(SOLICITUD_LOGIN_HINT_KEY);
}

async function inicializarAutenticacionSolicitudVenta() {
  const instancia = await obtenerInstanciaMsal();
  const redirectResponse = await instancia.handleRedirectPromise();

  if (redirectResponse?.account) {
    instancia.setActiveAccount(redirectResponse.account);
  }

  if (!instancia.getActiveAccount()) {
    const accounts = instancia.getAllAccounts();
    if (accounts.length > 0) {
      instancia.setActiveAccount(accounts[0]);
    }
  }

  if (!instancia.getActiveAccount()) {
    await intentarAutenticacionAutomatica(instancia);
  }

  prepararNuevaSolicitudSiCorresponde(instancia);
  renderEstadoSesion();
}

async function iniciarSesionSolicitudVenta() {
  const instancia = await obtenerInstanciaMsal();
  const request = { scopes: scopesSolicitudVenta() };
  const loginHint = leerSessionStorage(SOLICITUD_LOGIN_HINT_KEY).trim();
  if (loginHint) request.loginHint = loginHint;

  await instancia.loginRedirect(request);
}

async function cerrarSesionSolicitudVenta() {
  const instancia = await obtenerInstanciaMsal();
  const account = instancia.getActiveAccount();

  await instancia.logoutRedirect({
    account,
    postLogoutRedirectUri: solicitudVentaConfig.msal.redirectUri
  });
}

function obtenerUsuarioSolicitudVenta() {
  return solicitudVentaMsal?.getActiveAccount() || null;
}

async function obtenerAccessTokenBackend() {
  const instancia = await obtenerInstanciaMsal();
  const account = instancia.getActiveAccount();
  const backendScope = solicitudVentaConfig?.msal?.backendScope;

  if (!account) {
    throw new Error("No hay un usuario autenticado.");
  }

  if (!backendScope) {
    throw new Error("No se configuro el scope del backend de Solicitud de Venta.");
  }

  const request = {
    account,
    scopes: [backendScope]
  };

  try {
    const response = await instancia.acquireTokenSilent(request);
    return response.accessToken;
  } catch (error) {
    if (error instanceof window.msal.InteractionRequiredAuthError) {
      await instancia.acquireTokenRedirect(request);
      return null;
    }

    throw error;
  }
}

function renderEstadoSesion() {
  const account = obtenerUsuarioSolicitudVenta();
  const loginPanel = document.getElementById("loginPanel");
  const appPanel = document.getElementById("appPanel");
  const userName = document.getElementById("userName");
  const userEmail = document.getElementById("userEmail");

  if (!account) {
    loginPanel.hidden = false;
    appPanel.hidden = true;
    return;
  }

  loginPanel.hidden = true;
  appPanel.hidden = false;
  userName.textContent = account.name || "Usuario";
  userEmail.textContent = account.username || "";
}

window.solicitudVentaAuth = {
  initialize: inicializarAutenticacionSolicitudVenta,
  login: iniciarSesionSolicitudVenta,
  logout: cerrarSesionSolicitudVenta,
  getUser: obtenerUsuarioSolicitudVenta,
  getBackendAccessToken: obtenerAccessTokenBackend
};
