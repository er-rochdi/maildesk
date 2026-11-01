<script setup>
import { ref, computed, watch, onBeforeUnmount, nextTick } from 'vue';
import AppIcon from './AppIcon.vue';
const props = defineProps({ api: Function, boxes: Array, direction: String });
const emit = defineEmits(['notice', 'error']);
const mailboxId = ref(''), items = ref([]), selected = ref(null), search = ref(''), currentPage = ref(1), lastPage = ref(1), busy = ref(false), syncing = ref(false);
const compose = ref(null), sendBusy = ref(false), composeError = ref(''), attachments = ref([]), statusBox = ref(null);
let requestVersion = 0, detailVersion = 0;
const box = computed(() => props.boxes.find(b => b.id === Number(mailboxId.value)));
const statusLabel = { queued: 'En attente', sending: 'En cours', sent: 'Accepté par le serveur', received: 'Reçu', failed: 'Annulé', uncertain: 'Envoi à vérifier' };
const deliveryLabel = { pending: 'Livraison en attente', delayed: 'Livraison retardée', partial: 'Livraison partielle', delivered: 'Livré au serveur destinataire', failed: 'Échec de livraison' };
const messageStatus = message => message.delivery_error ? (message.delivery_status === 'partial' ? 'Livraison partielle — refus signalé' : 'Non-remise signalée') : deliveryLabel[message.delivery_status] || statusLabel[message.status];
const date = value => value ? new Date(value).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '';
async function load(quiet = false) {
  const version = ++requestVersion;
  if (!mailboxId.value) { items.value = []; return; }
  if (!quiet) busy.value = true;
  try {
    const data = await props.api(`/workspace/mailboxes/${mailboxId.value}/messages?direction=${props.direction}&q=${encodeURIComponent(search.value)}&page=${currentPage.value}`);
    if (version !== requestVersion) return;
    items.value = data.data; lastPage.value = data.last_page;
  } catch (e) { if (version === requestVersion) emit('error', e.message); }
  finally { if (version === requestVersion) busy.value = false; }
}
async function open(message) {
  const version = ++detailVersion; selected.value = null;
  try {
    const detail = await props.api(`/workspace/messages/${message.id}`);
    if (version !== detailVersion) return;
    selected.value = detail; await nextTick();
    if (version !== detailVersion || selected.value?.id !== message.id) return;
    const updated = await props.api(`/workspace/messages/${message.id}/read`, { method: 'POST' });
    if (version !== detailVersion) return;
    selected.value = updated; message.read_by_me = true;
  } catch (e) { if (version === detailVersion) emit('error', e.message); }
}
async function sync() {
  syncing.value = true;
  try { const data = await props.api(`/workspace/mailboxes/${mailboxId.value}/sync`, { method: 'POST' }); emit('notice', data.message); }
  catch (e) { emit('error', e.message); } finally { syncing.value = false; }
}
function requestId() {
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
  const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
}
function newMessage(mode = 'new') {
  const original = selected.value;
  composeError.value = ''; attachments.value = [];
  compose.value = { mailbox_id: Number(mailboxId.value), request_id: requestId(), to: mode === 'reply' ? original.from_address : '', cc: '', subject: mode === 'new' ? '' : (mode === 'reply' ? 'Re: ' : 'Fwd: ') + original.subject.replace(/^(Re:|Fwd:)\s*/i, ''), body: mode === 'new' ? '' : `\n\n--- ${mode === 'reply' ? 'Message original' : 'Message transféré'} ---\nDe : ${original.from_address}\nDate : ${date(original.message_at)}\nObjet : ${original.subject}\n\n${original.body}`, reply_to_id: mode === 'reply' ? original.id : null, forwarded: mode === 'forward' ? [...original.attachments] : [] };
}
async function send() {
  sendBusy.value = true; composeError.value = '';
  const form = new FormData();
  const message = compose.value;
  for (const key of ['request_id', 'subject', 'body']) form.append(key, message[key]);
  for (const key of ['to', 'cc']) message[key].split(/[;,]/).map(x => x.trim()).filter(Boolean).forEach(x => form.append(key + '[]', x));
  if (message.reply_to_id) form.append('reply_to_id', message.reply_to_id);
  message.forwarded.forEach(file => form.append('forward_attachment_ids[]', file.id));
  attachments.value.forEach(file => form.append('attachments[]', file));
  try {
    await props.api(`/workspace/mailboxes/${message.mailbox_id}/messages`, { method: 'POST', body: form });
    compose.value = null; emit('notice', 'Message pris en charge. Suivez son état dans Envoyés.'); await load();
  } catch (e) { composeError.value = e.message; } finally { sendBusy.value = false; }
}
function reset() { if (compose.value) { compose.value = null; emit('error', 'La boîte a changé. Ouvrez un nouveau message depuis la boîte souhaitée.'); } ++detailVersion; selected.value = null; items.value = []; statusBox.value = null; currentPage.value = 1; search.value = ''; load(); }
watch(() => props.boxes, boxes => { if (!boxes.some(b => b.id === Number(mailboxId.value))) mailboxId.value = boxes[0]?.id || ''; }, { immediate: true });
watch(mailboxId, reset, { immediate: true }); watch(() => props.direction, reset);
const timer = setInterval(async () => {
  if (document.hidden || !mailboxId.value || compose.value) return;
  await load(true);
  try {
    const boxes = await props.api('/workspace/mailboxes'); statusBox.value = boxes.find(b => b.id === Number(mailboxId.value));
    if (selected.value) { const id = selected.value.id, version = detailVersion; const data = await props.api(`/workspace/messages/${id}`); if (version === detailVersion && selected.value?.id === id) selected.value = data; }
  } catch (e) { emit('error', e.message); }
}, 15000);
onBeforeUnmount(() => { ++requestVersion; ++detailVersion; clearInterval(timer); });
</script>
<template>
  <div class="mail-workspace">
    <header class="mail-toolbar"><div><h1>{{ direction === 'inbound' ? 'Boîte de réception' : 'Envoyés' }}</h1><p class="muted">{{ direction === 'inbound' ? 'Messages reçus et activité de votre équipe.' : 'Messages envoyés depuis Maildesk.' }}</p></div><button class="primary" :disabled="!mailboxId" @click="newMessage()"><AppIcon name="plus" />Nouveau message</button></header>
    <div v-if="!boxes.length" class="empty-card"><div class="empty-icon"><AppIcon name="inbox" /></div><h3>Aucune boîte accessible</h3><p>Ajoutez une boîte ou demandez un accès à votre administrateur.</p></div>
    <template v-else>
      <div class="mail-filters"><label>Boîte<select v-model="mailboxId"><option v-for="b in boxes" :key="b.id" :value="b.id">{{ b.email }}</option></select></label><form @submit.prevent="currentPage = 1; load()"><input v-model="search" aria-label="Rechercher un message" placeholder="Rechercher un message…"><button class="secondary">Rechercher</button></form><button class="secondary" :disabled="syncing || !mailboxId" @click="sync">{{ syncing ? 'Patientez…' : 'Synchroniser' }}</button></div>
      <div class="sync-info" role="status"><span v-if="(statusBox || box)?.sync_error" class="sync-error">{{ (statusBox || box).sync_error }}</span><span v-else>{{ (statusBox || box)?.last_synced_at ? 'Dernière synchronisation : ' + date((statusBox || box).last_synced_at) : 'Aucune synchronisation terminée.' }}</span></div>
      <div class="conversation-layout" :class="{'has-selection': selected}">
        <section class="conversation-list" aria-label="Messages"><p v-if="busy" class="list-state">Chargement…</p><p v-else-if="!items.length" class="list-state">Aucun message trouvé.</p><button v-for="m in items" :key="m.id" class="message-row" :class="{chosen: selected?.id === m.id, unread: !m.read_by_me}" @click="open(m)"><div class="row-meta"><strong>{{ direction === 'inbound' ? (m.from_name || m.from_address) : m.to.join(', ') }}</strong><time>{{ date(m.message_at) }}</time></div><div class="row-subject">{{ m.subject || '(Sans objet)' }}</div><div class="row-bottom"><span v-if="direction === 'outbound'" :class="{'sync-error': ['uncertain','failed'].includes(m.status) || !!m.delivery_error}">{{ messageStatus(m) }}</span><span v-else>{{ m.from_address }}</span><span v-if="m.archive_error" class="sync-error">Copie serveur à vérifier</span><span v-if="m.send_error" class="sync-error">{{ m.send_error }}</span><span v-if="m.attachments_count">{{ m.attachments_count }} fichier(s)</span></div></button><div v-if="lastPage > 1" class="pagination"><button class="secondary" :disabled="currentPage === 1" @click="currentPage--; load()">Précédent</button><span>{{ currentPage }} / {{ lastPage }}</span><button class="secondary" :disabled="currentPage === lastPage" @click="currentPage++; load()">Suivant</button></div></section>
        <article v-if="selected" class="conversation"><button class="link back-to-list" @click="selected = null; ++detailVersion">← Messages</button><div class="conversation-title"><h2>{{ selected.subject || '(Sans objet)' }}</h2><span class="status">{{ messageStatus(selected) }}</span></div><div class="message-addresses"><strong>{{ selected.from_name }} &lt;{{ selected.from_address }}&gt;</strong><p>À : {{ selected.to.join(', ') }}</p><p v-if="selected.cc?.length">Cc : {{ selected.cc.join(', ') }}</p><time>{{ date(selected.message_at) }}</time></div><div v-if="selected.delivery_error" class="alert" role="alert">{{ selected.delivery_error }} <button v-if="selected.delivery_report_id" type="button" class="link" @click="open({ id: selected.delivery_report_id })">Voir le rapport reçu</button></div><div v-if="selected.send_error" class="alert">{{ selected.send_error }}</div><div v-if="selected.archive_error" class="alert">{{ selected.archive_error }}</div><pre class="message-body">{{ selected.body }}</pre><div class="attachment-list"><a v-for="file in selected.attachments" :key="file.id" :href="`/workspace/attachments/${file.id}`">{{ file.name }} <small>{{ Math.ceil(file.size / 1024) }} Ko</small></a></div><div class="message-actions"><button v-if="selected.direction === 'inbound'" class="primary" @click="newMessage('reply')">Répondre</button><button class="secondary" @click="newMessage('forward')">Transférer</button></div><section class="activity"><h3>Activité dans Maildesk</h3><p v-if="selected.sender"><strong>{{ selected.sender.name }}</strong> a demandé l’envoi · {{ date(selected.created_at) }}</p><p v-if="selected.delivery_status">Suivi de livraison : {{ deliveryLabel[selected.delivery_status] }}. Ce statut ne confirme pas la lecture du message.</p><p v-if="selected.sent_at">{{ selected.provider === 'resend' ? 'Accepté par le service d’envoi' : 'Accepté par le serveur SMTP' }} · {{ date(selected.sent_at) }}</p><p v-for="reader in selected.readers" :key="reader.id"><strong>{{ reader.name }}</strong> a ouvert ce message · {{ date(reader.pivot.read_at) }}</p></section></article>
        <div v-else class="conversation-placeholder"><AppIcon name="mail" /><h2>Vos conversations, en équipe</h2><p>Sélectionnez un message pour le lire et consulter son activité.</p></div>
      </div>
    </template>
    <div v-if="compose" class="modal-bg" @click.self="!sendBusy && (compose = null)"><form class="modal compose-modal" @submit.prevent="send"><div class="modal-title"><div><h2>Nouveau message</h2><p class="muted">De : {{ box?.email }}</p></div><button type="button" class="close" :disabled="sendBusy" aria-label="Fermer" @click="compose = null"><AppIcon name="close" /></button></div><div v-if="composeError" role="alert" class="alert">{{ composeError }}</div><fieldset :disabled="sendBusy"><label>À<input v-model="compose.to" required placeholder="destinataire@entreprise.com ; autre@entreprise.com"></label><label>Cc<input v-model="compose.cc" placeholder="Optionnel"></label><label>Objet<input v-model="compose.subject" required maxlength="255"></label><label>Message<textarea v-model="compose.body" required rows="10" maxlength="200000"></textarea></label><label>Pièces jointes<input type="file" multiple @change="attachments = Array.from($event.target.files)"></label><p class="form-hint">10 fichiers maximum, 10 Mo par fichier, 20 Mo au total.</p><div v-if="compose.forwarded.length" class="forward-files"><label v-for="file in compose.forwarded" :key="file.id">{{ file.name }}<button type="button" class="link" @click="compose.forwarded = compose.forwarded.filter(f => f.id !== file.id)">Retirer</button></label></div></fieldset><div class="modal-actions"><button type="button" class="secondary" :disabled="sendBusy" @click="compose = null">Annuler</button><button class="primary" :disabled="sendBusy">{{ sendBusy ? 'Prise en charge…' : 'Envoyer' }}</button></div></form></div>
  </div>
</template>
