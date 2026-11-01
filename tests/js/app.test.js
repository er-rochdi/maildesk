import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import * as Vue from 'vue';

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
