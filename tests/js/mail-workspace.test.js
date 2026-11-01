import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, compileScript } from '@vue/compiler-sfc';
import * as Vue from 'vue';

const { descriptor } = parse(readFileSync(new URL('../../resources/js/components/MailWorkspace.vue', import.meta.url), 'utf8'));
const compiled = compileScript(descriptor, { id: 'mail-workspace-test', genDefaultAs: 'Component' }).content
  .replace(/^import \{([^}]+)\} from 'vue';/m, 'const {$1} = Vue;')
  .replace(/^import AppIcon from .*;$/m, 'const AppIcon = {};');
const Component = new Function('Vue', `${compiled}\nComponent.render = () => null; return Component;`)(Vue);
const renderer = Vue.createRenderer({
  createElement: () => ({}), createComment: () => ({}), createText: () => ({}),
  insert() {}, remove() {}, patchProp() {}, setText() {}, setElementText() {},
  parentNode: () => null, nextSibling: () => null,
});

function workspace(t) {
  const pending = new Map(), reads = [];
  const api = async (url, options) => {
    if (options?.method === 'POST') { reads.push(url); return { id: Number(url.split('/')[3]) }; }
    if (/^\/workspace\/messages\/\d+$/.test(url)) return new Promise(resolve => pending.set(Number(url.split('/')[3]), resolve));
    return { data: [], last_page: 1 };
  };
  const app = renderer.createApp(Component, { api, boxes: [{ id: 1 }], direction: 'inbound' });
  const state = app.mount({}).$.setupState;
  let mounted = true;
  const unmount = () => { if (mounted) { mounted = false; app.unmount(); } };
  t.after(unmount);
  return { state, pending, reads, unmount };
}

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
