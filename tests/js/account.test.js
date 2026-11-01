import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, compileScript } from '@vue/compiler-sfc';
import * as Vue from 'vue';

const renderer = Vue.createRenderer({
  createElement: () => ({}), createComment: () => ({}), createText: () => ({}),
  insert() {}, remove() {}, patchProp() {}, setText() {}, setElementText() {},
  parentNode: () => null, nextSibling: () => null,
});
function mount(t, name, props) {
  const { descriptor } = parse(readFileSync(new URL(`../../resources/js/components/${name}.vue`, import.meta.url), 'utf8'));
  const compiled = compileScript(descriptor, { id: 'account-test', genDefaultAs: 'Component' }).content.replace(/^import \{([^}]+)\} from 'vue';/m, 'const {$1} = Vue;');
  const component = new Function('Vue', `${compiled}\nComponent.render = () => null; return Component;`)(Vue);
  const emitted = {};
  const app = renderer.createApp(component, { ...props, onUpdated: value => (emitted.updated ??= []).push([value]), onNotice: value => (emitted.notice ??= []).push([value]) });
  const instance = app.mount({}).$;
  t.after(() => app.unmount());
  return { setupState: instance.setupState, emitted };
}

test('profile preserves input after validation failure and clears password after success', async t => {
  let fail = true;
  const instance = mount(t, 'ProfileSettings', { user: { name: 'Member', email: 'old@example.test' }, api: async (url, options) => {
    assert.equal(url, '/account/profile'); assert.equal(options.method, 'PUT');
    if (fail) throw new Error('Mot de passe incorrect.');
    return { id: 1, name: 'Member', email: 'new@example.test' };
  } });
  const state = instance.setupState;
  state.profile.email = 'new@example.test'; state.profile.current_password = 'current';
  await state.saveProfile();
  assert.equal(state.error, 'Mot de passe incorrect.'); assert.equal(state.profile.email, 'new@example.test'); assert.equal(instance.emitted?.updated, undefined);
  fail = false; await state.saveProfile();
  assert.equal(state.profile.current_password, ''); assert.equal(state.busy, false);
  assert.equal(instance.emitted.updated[0][0].email, 'new@example.test');
});

test('password form keeps failed input and clears all password fields after success', async t => {
  let fail = true;
  const instance = mount(t, 'ProfileSettings', { user: { name: 'Member', email: 'member@example.test' }, api: async () => {
    if (fail) throw new Error('Confirmation incorrecte.');
    return { message: 'Mot de passe modifié.' };
  } });
  const state = instance.setupState;
  state.password = { current_password: 'old', password: 'new-password1', password_confirmation: 'new-password1' };
  await state.savePassword(); assert.equal(state.password.password, 'new-password1');
  fail = false; await state.savePassword();
  assert.deepEqual({ ...state.password }, { current_password: '', password: '', password_confirmation: '' });
  assert.equal(instance.emitted.notice[0][0], 'Mot de passe modifié.');
});

test('reset form handles an invalid token then completes without automatically logging in', async t => {
  let fail = true, calls = 0;
  const instance = mount(t, 'PasswordRecovery', { token: 'test-token', email: 'member@example.test', api: async (url, options) => {
    calls++; assert.equal(url, '/auth/reset-password'); assert.equal(JSON.parse(options.body).token, 'test-token');
    if (fail) throw new Error('Lien invalide.');
    return { message: 'Mot de passe réinitialisé.' };
  } });
  const state = instance.setupState;
  state.form.password = 'new-password1'; state.form.password_confirmation = 'new-password1';
  await state.submit(); assert.equal(state.error, 'Lien invalide.'); assert.equal(state.completed, false);
  fail = false; await state.submit();
  assert.equal(state.completed, true); assert.equal(state.form.password, ''); assert.equal(state.notice, 'Mot de passe réinitialisé.'); assert.equal(calls, 2);
});
