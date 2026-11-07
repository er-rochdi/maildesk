import { test, after } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import * as Vue from 'vue';

const originalDocument = globalThis.document;
globalThis.document = new EventTarget();
globalThis.document.hidden = false;
after(() => { if (originalDocument === undefined) delete globalThis.document; else globalThis.document = originalDocument; });

const { descriptor } = parse(readFileSync(new URL('../../resources/js/App.vue', import.meta.url), 'utf8'));
const script = compileScript(descriptor, { id: 'app-test', genDefaultAs: 'Component' });
const compiled = script.content
  .replace(/^import \{([^}]+)\} from 'vue';/m, 'const {$1} = Vue;')
  .replace(/^import (AppIcon|MailWorkspace|AdminDashboard|ProfileSettings|PasswordRecovery) from .*;$/gm, 'const $1 = {};')
  .replace(/^import \{ api as request \} from .*;$/m, '');
const makeComponent = new Function('Vue', 'request', `${compiled}\nComponent.render = () => null; return Component;`);
const template = compileTemplate({ source: descriptor.template.content, id: 'app-test', compilerOptions: { bindingMetadata: script.bindings } }).code
  .replace(/^import \{([^}]+)\} from "vue"/gm, (_, names) => `const {${names.replace(/\bas\b/g, ':')}} = Vue;`)
  .replace('export function render', 'function render');
const renderApp = new Function('Vue', `${template}\nreturn render;`)(Vue);
const renderer = Vue.createRenderer({
  createElement: () => ({}), createComment: () => ({}), createText: () => ({}),
  insert() {}, remove() {}, patchProp() {}, setText() {}, setElementText() {},
  parentNode: () => null, nextSibling: () => null,
});

for (const permissions of [['sync_mailboxes'], []]) {
  test(`${permissions.length ? 'sync-only' : 'no-permission'} account has the appropriate mail navigation and workspace`, async t => {
    const originalLocation = globalThis.location;
    globalThis.location = { pathname: '/' };
    t.after(() => { if (originalLocation === undefined) delete globalThis.location; else globalThis.location = originalLocation; });
    const request = async url => url === '/auth/me'
      ? { id: 1, role: 'member', permissions }
      : [{ id: 1, users: [{ id: 1 }] }];
    const app = renderer.createApp(makeComponent(Vue, request));
    const state = app.mount({}).$.setupState;
    t.after(() => app.unmount());
    await new Promise(resolve => setImmediate(resolve));
    const nodes = [];
    function visit(node) {
      if (Array.isArray(node)) return node.forEach(visit);
      if (!Vue.isVNode(node)) return;
      nodes.push(node); visit(node.children);
    }
    visit(renderApp({}, [], {}, state, {}, {}));
    const workspace = nodes.find(node => node.type === state.MailWorkspace);
    assert.equal(state.page, permissions.length ? 'inbox' : 'boxes');
    assert.equal(nodes.some(node => node.props?.title === 'Boîte de réception'), !!permissions.length);
    assert.equal(!!workspace, !!permissions.length);
    if (workspace) {
      assert.equal(workspace.props['can-read'], false);
      assert.equal(workspace.props['can-send'], false);
      assert.equal(workspace.props['can-sync'], true);
      assert.deepEqual(workspace.props.boxes.map(box => box.id), [1]);
    }
  });
}

for (const failure of [null, Object.assign(new Error('Votre session a expiré. Rechargez la page puis reconnectez-vous.'), { status: 419 }), new Error('Connexion indisponible')]) {
  test(`logout ${failure ? 'shows '+failure.message : 'reloads after success'}`, async t => {
    let reloads = 0, logouts = 0;
    const originalLocation = globalThis.location;
    globalThis.location = { reload: () => reloads++ };
    t.after(() => { if (originalLocation === undefined) delete globalThis.location; else globalThis.location = originalLocation; });
    const api = async (url, options) => {
      if (url === '/auth/me') return { role: 'member', permissions: [] };
      if (url === '/auth/logout') {
        assert.equal(options.method, 'POST');
        logouts++;
        if (failure) throw failure;
        return {};
      }
      return [];
    };
    const app = renderer.createApp(makeComponent(Vue, api));
    const state = app.mount({}).$.setupState;
    t.after(() => app.unmount());
    await Vue.nextTick();
    await state.logout();
    assert.equal(logouts, 1);
    assert.equal(reloads, failure ? 0 : 1);
    assert.equal(state.error, failure?.message ?? '');
  });
}

test('a revoked session clears workspace data and administration dialogs', async t => {
  const originalLocation = globalThis.location;
  globalThis.location = { pathname: '/' };
  t.after(() => { if (originalLocation === undefined) delete globalThis.location; else globalThis.location = originalLocation; });
  const request = async url => {
    if (url === '/auth/me') return { id: 1, role: 'member', permissions: [] };
    if (url === '/protected') throw Object.assign(new Error('Compte désactivé.'), { status: 401 });
    return [];
  };
  const app = renderer.createApp(makeComponent(Vue, request));
  const state = app.mount({}).$.setupState;
  t.after(() => app.unmount());
  await Vue.nextTick(); await Vue.nextTick();
  state.editingAccount = { id: 2 }; state.editingPermissions = { id: 2 }; state.statusTarget = { id: 2 }; state.showingBox = true;
  await assert.rejects(state.api('/protected'), error => error.status === 401);
  assert.equal(state.user, null); assert.deepEqual([...state.boxes], []); assert.deepEqual([...state.users], []);
  assert.equal(state.editingAccount, null); assert.equal(state.editingPermissions, null); assert.equal(state.statusTarget, null); assert.equal(state.showingBox, false);
  assert.equal(state.error, 'Compte désactivé.');
});

async function notificationsApp(t, notificationRequest, permissions = ['read_emails', 'view_dashboard']) {
  const originalLocation = globalThis.location;
  globalThis.location = { pathname: '/' };
  t.after(() => { if (originalLocation === undefined) delete globalThis.location; else globalThis.location = originalLocation; });
  const request = async url => {
    if (url === '/auth/me') return { id: 1, role: 'member', permissions };
    if (url.startsWith('/workspace/mail-notifications')) return notificationRequest(url);
    return [];
  };
  const app = renderer.createApp(makeComponent(Vue, request));
  const state = app.mount({}).$.setupState;
  t.after(() => app.unmount());
  await new Promise(resolve => setImmediate(resolve));
  return { state, app };
}

for (const page of ['inbox', 'sent', 'dashboard']) {
  test(`notification action reloads the inbox from ${page} before dismissing the alert`, async t => {
    const { state } = await notificationsApp(t, async () => ({ mailbox_ids: [1], latest_id: 1, unread_count: 1, new_count: 0 }));
    let finish, reloads = 0;
    state.page = page; state.newMailCount = 1;
    state.mailWorkspace = { showInbox: () => { reloads++; return new Promise(resolve => { finish = resolve; }); } };
    const nodes = [];
    function visit(node) {
      if (Array.isArray(node)) return node.forEach(visit);
      if (!Vue.isVNode(node)) return;
      nodes.push(node); visit(node.children);
    }
    visit(renderApp({}, [], {}, state, {}, {}));
    const action = nodes.find(node => node.type === 'button' && node.children === 'Voir la réception');
    const viewing = action.props.onClick();
    await Vue.nextTick();
    assert.equal(state.page, 'inbox');
    assert.equal(reloads, 1);
    assert.equal(state.newMailCount, 1);
    finish();
    await viewing;
    assert.equal(state.newMailCount, 0);
  });
}

test('global polling alerts on new arrivals across pages without alerting on existing unread mail', async t => {
  const calls = [];
  let data = { mailbox_ids: [1], latest_id: 10, unread_count: 4, new_count: 0 };
  const { state } = await notificationsApp(t, async url => { calls.push(url); return data; });
  assert.equal(state.page, 'dashboard');
  assert.equal(state.unreadCount, 4);
  assert.equal(state.newMailCount, 0);
  // A read and an arrival can leave the unread total unchanged.
  data = { ...data, latest_id: 11, new_count: 1 };
  await state.pollNotifications();
  assert.equal(state.unreadCount, 4);
  assert.equal(state.newMailCount, 1);
  assert.equal(calls.at(-1), '/workspace/mail-notifications?after=10');
  state.changePage('boxes');
  assert.equal(state.newMailCount, 1);
  data = { ...data, new_count: 0 };
  await state.pollNotifications();
  assert.equal(state.newMailCount, 1);
  data = { ...data, unread_count: 0 };
  await state.pollNotifications();
  assert.equal(state.unreadCount, 0);
  assert.equal(state.newMailCount, 0);
});

test('does not poll without read permission and resets on changed mailbox access', async t => {
  const calls = [];
  let data = { mailbox_ids: [1], latest_id: 10, unread_count: 2, new_count: 0 };
  const { state } = await notificationsApp(t, async url => { calls.push(url); return data; }, ['sync_mailboxes']);
  await state.pollNotifications();
  assert.deepEqual(calls, []);
  state.user = { ...state.user, permissions: ['read_emails'] };
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(state.unreadCount, 2);
  data = { mailbox_ids: [1, 2], latest_id: 20, unread_count: 12, new_count: 10 };
  await state.pollNotifications();
  assert.equal(state.unreadCount, 12);
  assert.equal(state.newMailCount, 0);
  state.user = { ...state.user, permissions: [] };
  await Vue.nextTick();
  assert.equal(state.unreadCount, 0);
});

test('polling recovers from network errors and discards responses after logout', async t => {
  let failure = null, finish;
  const { state } = await notificationsApp(t, async () => {
    if (failure) throw failure;
    if (finish === null) return new Promise(resolve => { finish = resolve; });
    return { mailbox_ids: [1], latest_id: 5, unread_count: 1, new_count: 0 };
  });
  failure = new Error('Connexion indisponible');
  await state.pollNotifications();
  assert.match(state.notificationError, /Connexion indisponible/);
  failure = null;
  await state.pollNotifications();
  assert.equal(state.notificationError, '');
  finish = null;
  const polling = state.pollNotifications();
  state.user = null;
  await Vue.nextTick();
  finish({ mailbox_ids: [1], latest_id: 6, unread_count: 2, new_count: 1 });
  await polling;
  assert.equal(state.unreadCount, 0);
  assert.equal(state.newMailCount, 0);
});

test('polling runs every fifteen seconds and on returning to a visible tab, then stops on unmount', async t => {
  t.mock.timers.enable({ apis: ['setInterval'] });
  const calls = [];
  const { app } = await notificationsApp(t, async url => {
    calls.push(url);
    return { mailbox_ids: [1], latest_id: 1, unread_count: 1, new_count: 0 };
  });
  assert.equal(calls.length, 1);
  t.mock.timers.tick(15000);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(calls.length, 2);
  document.hidden = true;
  t.after(() => { document.hidden = false; });
  t.mock.timers.tick(15000);
  assert.equal(calls.length, 2);
  document.hidden = false;
  document.dispatchEvent(new Event('visibilitychange'));
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(calls.length, 3);
  app.unmount();
  t.mock.timers.tick(15000);
  document.dispatchEvent(new Event('visibilitychange'));
  assert.equal(calls.length, 3);
});
