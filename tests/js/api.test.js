import { afterEach, test } from 'node:test';
import assert from 'node:assert/strict';
import { api } from '../../resources/js/api.js';

const originalFetch = globalThis.fetch;
const originalDocument = globalThis.document;
afterEach(() => {
  globalThis.fetch = originalFetch;
  if (originalDocument === undefined) delete globalThis.document;
  else globalThis.document = originalDocument;
});

test('uses the current URL-decoded XSRF cookie after login and in another tab', async () => {
  globalThis.document = { cookie: 'maildesk-session=session; XSRF-TOKEN=before%3D' };
  const requests = [];
  globalThis.fetch = async (url, options) => {
    requests.push({ url, ...options });
    return new Response('{}', { status: 200 });
  };
  await api('/auth/login', { method: 'POST', body: '{}' });
  document.cookie = 'XSRF-TOKEN=after%2B%2F%3D; maildesk-session=new-session';
  await api('/workspace/users', { method: 'POST', body: '{}' });
  document.cookie = 'XSRF-TOKEN=another-tab%3D';
  await api('/workspace/mailboxes/1/access', { method: 'PUT', body: '{}' });
  assert.deepEqual(requests.map(request => request.headers['X-XSRF-TOKEN']), ['before=', 'after+/=', 'another-tab=']);
  assert.ok(requests.every(request => !('X-CSRF-TOKEN' in request.headers)));
});

test('preserves multipart uploads and handles an empty successful response', async () => {
  globalThis.document = { cookie: 'XSRF-TOKEN=current' };
  const body = new FormData();
  body.set('body', 'Hello');
  globalThis.fetch = async (url, options) => {
    assert.equal(options.body, body);
    assert.equal(options.headers['Content-Type'], undefined);
    assert.equal(options.headers['X-XSRF-TOKEN'], 'current');
    return new Response(null, { status: 204 });
  };
  assert.equal(await api('/workspace/mailboxes/1/messages', { method: 'POST', body }), null);
});

test('explains an expired session without replaying a message submission', async () => {
  globalThis.document = { cookie: '' };
  let calls = 0;
  globalThis.fetch = async () => {
    calls++;
    return new Response('{"message":"CSRF token mismatch."}', { status: 419 });
  };
  await assert.rejects(api('/workspace/mailboxes/1/messages', { method: 'POST', body: '{}' }), error => error.status === 419 && error.message.includes('Rechargez la page'));
  assert.equal(calls, 1);
});

test('preserves validation errors', async () => {
  globalThis.document = { cookie: 'XSRF-TOKEN=current' };
  globalThis.fetch = async () => new Response('{"errors":{"email":["Adresse email invalide."]}}', { status: 422 });
  await assert.rejects(api('/workspace/users', { method: 'POST', body: '{}' }), error => error.status === 422 && error.message === 'Adresse email invalide.');
});
