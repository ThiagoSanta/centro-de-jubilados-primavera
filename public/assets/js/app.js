const BASE_URL = '/centro-de-jubilados-primavera/public';
const API_BASE = BASE_URL + '/api';

function logout() {
  fetch(BASE_URL + '/api/auth/logout', { method: 'POST' })
    .then(() => { window.location.href = BASE_URL + '/views/auth/login.html'; });
}
