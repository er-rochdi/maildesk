import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, compileScript } from '@vue/compiler-sfc';
import * as Vue from 'vue';

const { descriptor } = parse(readFileSync(new URL('../../resources/js/components/MailWorkspace.vue', import.meta.url), 'utf8'));
const compiled = compileScript(descriptor, { id: 'mail-workspace-test', genDefaultAs: 'Component' }).content
  .replace(/^import \{([^}]+)\} from 'vue';/m, 'const {$1} = Vue;')
  .replace(/^import (AppIcon|MessageTeam) from .*;$/gm, 'const $1 = {};');
const Component = new Function('Vue', `${compiled}\nComponent.render = () => null; return Component;`)(Vue);
const renderer = Vue.createRenderer({
  createElement: () => ({}), createComment: () => ({}), createText: () => ({}),
  insert() {}, remove() {}, patchProp() {}, setText() {}, setElementText() {},
  parentNode: () => null, nextSibling: () => null,
});

function workspace(t, props = {}) {
  const pending = new Map(), reads = [], calls = [];
  const api = async (url, options) => {
    calls.push(url);
    if (options?.method === 'POST') { reads.push(url); return { id: Number(url.split('/')[3]) }; }
    if (/^\/workspace\/messages\/\d+$/.test(url)) return new Promise(resolve => pending.set(Number(url.split('/')[3]), resolve));
    return { data: [], last_page: 1 };
  };
  const app = renderer.createApp(Component, { api, boxes: [{ id: 1 }], direction: 'inbound', ...props });
  const state = app.mount({}).$.setupState;
  let mounted = true;
  const unmount = () => { if (mounted) { mounted = false; app.unmount(); } };
  t.after(unmount);
  return { state, pending, reads, calls, unmount };
}

test('does not request the inbox when only sending is allowed', async t => {
  const { state, calls } = workspace(t, { canRead: false, canSend: true, canSync: false });
  await Vue.nextTick();
  await state.load();
  assert.deepEqual(calls, []);
  assert.deepEqual([...state.items], []);
});

test('sync-only accounts can synchronize without requesting messages', async t => {
  const { state, calls } = workspace(t, { canRead: false, canSend: false, canSync: true });
  await Vue.nextTick();
  await state.sync();
  assert.deepEqual(calls, ['/workspace/mailboxes/1/sync']);
  assert.deepEqual([...state.items], []);
});

test('moving the last message on page two reloads the first page', async t => {
  const calls = [];
  const remaining = { id: 1, subject: 'Still in the inbox' };
  const { state } = workspace(t, { api: async (url, options) => {
    calls.push([url, options?.method]);
    if (options?.method === 'PUT') return { folder: 'archive' };
    return { data: new URL(url, 'http://localhost').searchParams.get('page') === '1' ? [remaining] : [], last_page: 1 };
  } });
  await Vue.nextTick();
  state.currentPage = 2; state.lastPage = 2; state.selected = { id: 31 };
  calls.length = 0;
  await state.move('archive');
  assert.deepEqual(calls, [
    ['/workspace/messages/31/folder', 'PUT'],
    ['/workspace/mailboxes/1/messages?direction=inbound&folder=inbox&q=&page=1', undefined],
  ]);
  assert.equal(state.currentPage, 1);
  assert.equal(state.lastPage, 1);
  assert.equal(state.selected, null);
  assert.deepEqual([...state.items], [remaining]);
});

for (const folder of ['archive', 'trash', 'inbox']) {
  test(`opening the inbox from a notification clears ${folder} filters and ignores pending details`, async t => {
    const { state, calls, pending, reads } = workspace(t);
    await Vue.nextTick();
    state.folder = folder; state.search = 'old-query'; state.currentPage = 2;
    const opening = state.open({ id: 7 });
    calls.length = 0;
    await state.showInbox();
    pending.get(7)({ id: 7 });
    await opening;
    assert.equal(state.folder, 'inbox');
    assert.equal(state.search, '');
    assert.equal(state.currentPage, 1);
    assert.equal(state.selected, null);
    assert.deepEqual(reads, []);
    assert.deepEqual(calls, ['/workspace/mailboxes/1/messages?direction=inbound&folder=inbox&q=&page=1']);
  });
}

test('adds the personal signature when composing and preserves the quoted message', async t => {
  const { state } = workspace(t, { signature: 'Cordialement,\nAmina' });
  state.newMessage();
  assert.equal(state.compose.body, '\n\nCordialement,\nAmina');
  state.selected = { id: 7, from_address: 'client@example.test', subject: 'Devis', body: 'Texte original', message_at: '2026-11-01T10:00:00Z', attachments: [] };
  state.newMessage('reply');
  assert.ok(state.compose.body.indexOf('Cordialement,\nAmina') < state.compose.body.indexOf('Texte original'));
  assert.equal(state.compose.reply_to_id, 7);
});

test('opens HTML automatically when available and falls back to text for the next message', async t => {
  const messages = {
    7: { id: 7, body: 'Texte', html_body: '<p>Version HTML</p>' },
    8: { id: 8, body: 'Texte seulement', html_body: null },
  };
  const { state } = workspace(t, { api: async url => {
    return messages[Number(url.split('/')[3])] || { data: [], last_page: 1 };
  } });
  await state.open({ id: 7 });
  assert.equal(state.showHtml, true);
  assert.ok(state.htmlDocument.includes('<p>Version HTML</p>'));
  state.showHtml = false;
  await state.open({ id: 7 });
  assert.equal(state.showHtml, true);
  await state.open({ id: 8 });
  assert.equal(state.showHtml, false);
  assert.equal(state.selected.body, 'Texte seulement');
});

test('preserves the chosen text view when the read response arrives', async t => {
  const detail = { id: 7, html_body: '<p>Version HTML</p>' };
  let finishRead, notifyRead;
  const readStarted = new Promise(resolve => { notifyRead = resolve; });
  const { state } = workspace(t, { api: async (url, options) => {
    if (options?.method === 'POST') {
      return new Promise(resolve => { finishRead = resolve; notifyRead(); });
    }
    return url === '/workspace/messages/7' ? detail : { data: [], last_page: 1 };
  } });
  const opening = state.open({ id: 7 });
  await readStarted;
  assert.equal(state.showHtml, true);
  state.showHtml = false;
  finishRead(detail);
  await opening;
  assert.equal(state.showHtml, false);
});

test('does not record a read when the user leaves before message details arrive', async t => {
  const { state, pending, reads, unmount } = workspace(t);
  const opening = state.open({ id: 7 });
  unmount();
  pending.get(7)({ id: 7 });
  await opening;
  assert.deepEqual(reads, []);
  assert.equal(state.selected, null);
});

test('does not record a read when navigation unmounts the component during the render tick', async t => {
  const { state, pending, reads, unmount } = workspace(t);
  const opening = state.open({ id: 7 });
  const stop = Vue.watch(() => state.selected, detail => { if (detail) unmount(); }, { flush: 'sync' });
  pending.get(7)({ id: 7 });
  await opening;
  stop();
  assert.deepEqual(reads, []);
});

test('records only the latest message after overlapping detail requests', async t => {
  const { state, pending, reads } = workspace(t);
  const first = state.open({ id: 7 });
  const latest = state.open({ id: 8 });
  pending.get(7)({ id: 7 });
  pending.get(8)({ id: 8 });
  await Promise.all([first, latest]);
  assert.deepEqual(reads, ['/workspace/messages/8/read']);
  assert.equal(state.selected.id, 8);
});

test('records a read after displaying the active message', async t => {
  const { state, pending, reads } = workspace(t);
  const message = { id: 7 };
  const opening = state.open(message);
  pending.get(7)({ id: 7 });
  await opening;
  assert.deepEqual(reads, ['/workspace/messages/7/read']);
  assert.equal(message.read_by_me, true);
});
