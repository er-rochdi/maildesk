function csrfToken() {
  const cookie = document.cookie.split(';').map(value => value.trim()).find(value => value.startsWith('XSRF-TOKEN='));
  return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

export async function api(url, options = {}) {
  // Laravel renews this cookie when authentication regenerates the session.
  const res = await fetch(url, { ...options, headers: { 'Accept': 'application/json', ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }), 'X-XSRF-TOKEN': csrfToken(), ...options.headers } });
  if (!res.ok) {
    const data = await res.json().catch(() => ({}));
    const err = new Error(res.status === 419 ? 'Votre session a expiré. Rechargez la page puis reconnectez-vous.' : Object.values(data.errors || {}).flat()[0] || data.message || 'Une erreur est survenue.');
    err.status = res.status;
    throw err;
  }
  return res.status === 204 ? null : res.json();
}
