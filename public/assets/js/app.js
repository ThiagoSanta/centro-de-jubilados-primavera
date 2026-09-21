/* --- Constantes globales --- */
const BASE_URL = '/centro-de-jubilados-primavera/public';
const API_BASE = BASE_URL + '/api';

/**
 * Realiza una petición fetch con credenciales a la API y parsea la respuesta JSON.
 * Lanza un error descriptivo si el servidor responde con un código de error HTTP.
 */
async function fetchJson(url, options = {}) {
  const response = await fetch(url, { credentials: 'same-origin', ...options });
  const text = await response.text();

  let data = null;
  if (text) {
    try {
      data = JSON.parse(text);
    } catch (error) {
      throw new Error(`Respuesta inválida del servidor (${response.status}).`);
    }
  }

  if (!response.ok) {
    throw new Error(data?.message || `Error ${response.status}`);
  }

  return data;
}

// Cierra la sesión en el backend y redirige a la pantalla de login
function logout() {
  fetch(BASE_URL + '/api/auth/logout', { method: 'POST', credentials: 'same-origin' })
    .catch(() => { })
    .finally(() => {
      window.location.replace(BASE_URL + '/views/auth/login.html');
    });
}
