const solicitudVentaConfig = window.SOLICITUD_VENTA_CONFIG;

const solicitudVentaMsal = new msal.PublicClientApplication({
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

async function inicializarAutenticacionSolicitudVenta() {
  const redirectResponse = await solicitudVentaMsal.handleRedirectPromise();

  if (redirectResponse?.account) {
    solicitudVentaMsal.setActiveAccount(redirectResponse.account);
  }

  if (!solicitudVentaMsal.getActiveAccount()) {
    const accounts = solicitudVentaMsal.getAllAccounts();
    if (accounts.length > 0) {
      solicitudVentaMsal.setActiveAccount(accounts[0]);
    }
  }

  renderEstadoSesion();
}

async function iniciarSesionSolicitudVenta() {
  await solicitudVentaMsal.loginRedirect({
    scopes: ["openid", "profile", "email"]
  });
}

async function cerrarSesionSolicitudVenta() {
  const account = solicitudVentaMsal.getActiveAccount();

  await solicitudVentaMsal.logoutRedirect({
    account,
    postLogoutRedirectUri: solicitudVentaConfig.msal.redirectUri
  });
}

function obtenerUsuarioSolicitudVenta() {
  return solicitudVentaMsal.getActiveAccount();
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
