const BASE_URL = '/centro-de-jubilados-primavera/public';
const API_BASE = BASE_URL + '/api';

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

function logout() {
  fetch(BASE_URL + '/api/auth/logout', { method: 'POST', credentials: 'same-origin' })
    .catch(() => { })
    .finally(() => {
      window.location.replace(BASE_URL + '/views/auth/login.html');
    });
}
