/* --- Constantes globales --- */
const BASE_URL = '/centro-de-jubilados-primavera/public';
const API_BASE = BASE_URL + '/api';

/**
 * Timeout por defecto para todas las peticiones a la API (15 s).
 * En una red WiFi local debería ser más que suficiente para operaciones normales.
 * Para operaciones más lentas (generación de planillas, reportes pesados) se puede
 * pasar { timeoutMs: 30000 } en el objeto options para sobreescribirlo puntualmente.
 */
const API_TIMEOUT_MS = 15_000;

/**
 * Clasifica un error de fetch y devuelve un mensaje legible para el usuario,
 * diferenciando claramente tres situaciones:
 *   1. Sin conexión / servidor no alcanzable (TypeError — "Failed to fetch")
 *   2. Tiempo de espera agotado (AbortError — el servidor no respondió a tiempo)
 *   3. Error de respuesta del servidor (4xx / 5xx ya parseado como Error normal)
 */
function clasificarErrorRed(err) {
  if (err.name === 'AbortError') {
    return 'El servidor tardó demasiado en responder. Verificá tu conexión a la red del centro y volvé a intentarlo.';
  }
  if (err instanceof TypeError) {
    return 'No se pudo conectar al servidor. Verificá que estés conectado a la red WiFi del centro.';
  }
  // Error HTTP (4xx / 5xx) — el mensaje viene del servidor, se muestra tal cual
  return err.message || 'Error inesperado del servidor.';
}

/**
 * Devuelve true si el error es de red (sin conexión o timeout), lo que implica
 * que no se puede saber con certeza si la operación se aplicó en el servidor.
 * Útil para mostrar mensajes de ambigüedad en acciones críticas (pagos, anulaciones).
 */
function esErrorDeRed(err) {
  return err.name === 'AbortError' || err instanceof TypeError;
}

/**
 * Realiza una petición fetch con credenciales a la API y parsea la respuesta JSON.
 * Lanza un error descriptivo si el servidor responde con un código de error HTTP.
 *
 * @param {string} url     - URL destino
 * @param {object} options - Opciones de fetch estándar. Admite una propiedad extra:
 *                           { timeoutMs: number } para sobreescribir el timeout
 *                           predeterminado en llamadas que legítimamente tardan más.
 */
async function fetchJson(url, options = {}) {
  // Extraer timeoutMs del objeto options sin pasarlo al fetch nativo
  const { timeoutMs = API_TIMEOUT_MS, ...fetchOptions } = options;

  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

  let response;
  try {
    response = await fetch(url, {
      credentials: 'same-origin',
      signal: controller.signal,
      ...fetchOptions
    });
  } finally {
    clearTimeout(timeoutId);
  }

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
