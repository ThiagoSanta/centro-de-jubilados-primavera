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

/**
 * Atajo global de accesibilidad (WCAG 2.1 - 2.1.1):
 * Cierra modales, drawers, menú contextual o sidebars abiertos al presionar Escape.
 */
document.addEventListener('keydown', function (e) {
  if (e.key !== 'Escape') return;

  // 1. Menú contextual (ej. en socios/padron.html)
  const contextMenu = document.querySelector('.context-menu.context-menu--open, #contextMenu.context-menu--open');
  if (contextMenu) {
    if (typeof cerrarContextMenu === 'function') {
      cerrarContextMenu();
    } else {
      contextMenu.classList.remove('context-menu--open');
    }
    return;
  }

  // 2. Modales controlados por clase .modal-overlay--open (usuarios, socios/padron, socios/perfil, notificaciones)
  const openOverlays = document.querySelectorAll('.modal-overlay.modal-overlay--open, .modal-overlay--open');
  if (openOverlays.length > 0) {
    openOverlays.forEach(function (overlay) {
      if (typeof closeModal === 'function' && overlay.id) {
        closeModal(overlay.id);
      } else if (typeof cerrarModal === 'function') {
        cerrarModal();
      }
      overlay.classList.remove('modal-overlay--open');
    });
    return;
  }

  // 3. Modales controlados por estilo inline display (flex/block en pagos/listado, pagos/cobro-sede, deuda/generar)
  const visibleModals = Array.from(document.querySelectorAll('.modal-overlay')).filter(function (el) {
    return el.style.display && el.style.display !== 'none';
  });
  if (visibleModals.length > 0) {
    visibleModals.forEach(function (modal) {
      if (modal.id === 'modalAnular' && typeof cerrarModalAnular === 'function') {
        cerrarModalAnular();
      } else if (modal.id === 'modalSeleccionSocio' && typeof cerrarModalSeleccionSocio === 'function') {
        cerrarModalSeleccionSocio();
      } else if (typeof cerrarModal === 'function') {
        cerrarModal();
      }
      modal.style.display = 'none';
    });
    return;
  }

  // 4. Drawers laterales (ej. nuevo socio en socios/padron.html)
  const openDrawer = document.querySelector('.drawer.drawer--open, .drawer-overlay.drawer-overlay--open');
  if (openDrawer) {
    if (typeof cerrarDrawer === 'function') {
      cerrarDrawer();
    } else {
      document.querySelectorAll('.drawer--open').forEach(el => el.classList.remove('drawer--open'));
      document.querySelectorAll('.drawer-overlay--open').forEach(el => el.classList.remove('drawer-overlay--open'));
    }
    return;
  }

  // 5. Sidebar móvil expandido (ej. planillas/mapa, pagos/cobro-sede, cobrador/*)
  const openSidebar = document.querySelector('.sidebar.sidebar--open');
  if (openSidebar) {
    openSidebar.classList.remove('sidebar--open');
    const sidebarOverlay = document.getElementById('sidebarOverlay') || document.querySelector('.sidebar-overlay--visible');
    if (sidebarOverlay) {
      sidebarOverlay.classList.remove('sidebar-overlay--visible');
    }
  }
});

/**
 * Focus Trap — WCAG 2.1, criterio 2.1.2
 *
 * Confina el foco de teclado dentro de un modal mientras está abierto.
 * Solo intercepta Tab/Shift+Tab; el Escape sigue siendo manejado por el
 * listener global definido arriba, por lo que no hay conflicto.
 *
 * Uso:
 *   var trap = initFocusTrap(document.getElementById('modalXxx')); // al abrir
 *   trap.destroy();                                                 // al cerrar
 *
 * @param {HTMLElement} overlayEl - El .modal-overlay (contenedor del modal).
 * @returns {{ destroy: function }}
 */
function initFocusTrap(overlayEl) {
  // Recordar quién tenía el foco antes de abrir el modal
  var triggerEl = document.activeElement;

  // Selector canónico de elementos enfocables (excluye disabled y no visibles)
  var FOCUSABLE_SEL = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])'
  ].join(', ');

  function getFocusable() {
    return Array.from(overlayEl.querySelectorAll(FOCUSABLE_SEL)).filter(function (el) {
      return !el.closest('[hidden]') && el.offsetParent !== null;
    });
  }

  // Mover foco al primer elemento interactivo del modal
  var focusables = getFocusable();
  if (focusables.length > 0) {
    focusables[0].focus();
  } else {
    // Fallback: si no hay elementos enfocables, enfocar el overlay (requiere tabindex="-1")
    if (overlayEl.hasAttribute('tabindex')) {
      overlayEl.focus();
    }
  }

  function handleKeyDown(e) {
    if (e.key !== 'Tab') return;

    var focusables = getFocusable(); // re-evalúa (el contenido puede ser dinámico)
    if (focusables.length === 0) return;

    var first = focusables[0];
    var last  = focusables[focusables.length - 1];

    if (e.shiftKey) {
      // Shift+Tab desde el primero → ir al último
      if (document.activeElement === first) {
        e.preventDefault();
        last.focus();
      }
    } else {
      // Tab desde el último → ir al primero
      if (document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }
  }

  document.addEventListener('keydown', handleKeyDown);

  return {
    destroy: function () {
      document.removeEventListener('keydown', handleKeyDown);
      // Devolver el foco al elemento que abrió el modal
      if (triggerEl && typeof triggerEl.focus === 'function') {
        triggerEl.focus();
      }
    }
  };
}
