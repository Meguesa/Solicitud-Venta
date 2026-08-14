document.addEventListener("DOMContentLoaded", async () => {
  const loginButton = document.getElementById("btnLogin");
  const logoutButton = document.getElementById("btnLogout");

  loginButton.addEventListener("click", async () => {
    try {
      await window.solicitudVentaAuth.login();
    } catch (error) {
      console.error("No fue posible iniciar sesion:", error);
    }
  });

  logoutButton.addEventListener("click", async () => {
    try {
      await window.solicitudVentaAuth.logout();
    } catch (error) {
      console.error("No fue posible cerrar sesion:", error);
    }
  });

  try {
    await window.solicitudVentaAuth.initialize();
  } catch (error) {
    console.error("No fue posible inicializar la autenticacion:", error);
    document.getElementById("loginMessage").textContent =
      "No fue posible inicializar el acceso. Contacta a Sistemas.";
  }
});
