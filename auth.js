const solicitudVentaConfig = window.SOLICITUD_VENTA_CONFIG;
let solicitudVentaMsal = null;

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

  renderEstadoSesion();
}

async function iniciarSesionSolicitudVenta() {
  const instancia = await obtenerInstanciaMsal();

  await instancia.loginRedirect({
    scopes: ["openid", "profile", "email"]
  });
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
  getUser: obtenerUsuarioSolicitudVenta
};
