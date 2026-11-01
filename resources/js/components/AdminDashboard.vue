<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import AppIcon from './AppIcon.vue';

const props = defineProps({ api: Function, boxes: Array });
const period = ref('all'), mailboxId = ref(''), kind = ref('all'), actorId = ref(''), search = ref('');
const dashboard = ref(null), activity = ref(null), summaryBusy = ref(false), activityBusy = ref(false);
const error = ref(''), activityError = ref(''), page = ref(1), selected = ref(null), auditDialog = ref(null);
let summaryVersion = 0, activityVersion = 0;
const format = value => new Intl.NumberFormat('fr-FR').format(value || 0);
const date = value => value ? new Date(value).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '—';
const trend = computed(() => dashboard.value?.trend || []);
const maximum = computed(() => Math.max(0, ...trend.value.flatMap(day => [day.sent, day.received])));
const peak = computed(() => Math.max(1, maximum.value));
const metrics = computed(() => [
  { key: 'sent', label: 'Emails envoyés', hint: 'Acceptés pour envoi', icon: 'send', filter: 'sent' },
  { key: 'received', label: 'Emails reçus', hint: 'Dans vos boîtes synchronisées', icon: 'inbox', filter: 'received' },
  { key: 'unopened', label: 'Non ouverts', hint: 'Par aucun membre dans Maildesk', icon: 'mail', filter: 'unread' },
  { key: 'issues', label: 'Envois à vérifier', hint: 'Refus, erreurs ou envois incertains', icon: 'alert', filter: 'issues' },
]);
const statusLabel = message => message.delivery_error ? 'Non-remise signalée'
  : ({ delivered: 'Livré au serveur destinataire', partial: 'Livraison partielle', delayed: 'Livraison retardée', failed: 'Échec de livraison' }[message.delivery_status]
    || { sent: 'Accepté pour envoi', queued: 'En attente', sending: 'En cours', received: 'Reçu', uncertain: 'Envoi à vérifier', failed: 'Annulé' }[message.status] || message.status);

function params(journal = false) {
  const query = new URLSearchParams({ period: period.value });
  if (mailboxId.value) query.set('mailbox_id', mailboxId.value);
  if (journal) {
    query.set('page', page.value); query.set('kind', kind.value);
    if (actorId.value) query.set('user_id', actorId.value);
    if (search.value.trim()) query.set('q', search.value.trim());
  }
  return query;
}
async function loadSummary() {
  const version = ++summaryVersion; summaryBusy.value = true; error.value = '';
  try {
    const data = await props.api('/workspace/dashboard?' + params());
    if (version === summaryVersion) dashboard.value = data;
  } catch (e) { if (version === summaryVersion) { dashboard.value = null; error.value = e.message; } }
  finally { if (version === summaryVersion) summaryBusy.value = false; }
}
async function loadActivity() {
  const version = ++activityVersion; activityBusy.value = true; activityError.value = '';
  try {
    const data = await props.api('/workspace/admin/activity?' + params(true));
    if (version === activityVersion) activity.value = data;
  } catch (e) { if (version === activityVersion) { activity.value = null; activityError.value = e.message; } }
  finally { if (version === activityVersion) activityBusy.value = false; }
}
function refresh() { return Promise.all([loadSummary(), loadActivity()]); }
function filterJournal(value, userId = '') {
  const changed = kind.value !== value || actorId.value !== String(userId);
  kind.value = value; actorId.value = String(userId);
  if (!changed) searchJournal();
  document.getElementById('admin-journal')?.scrollIntoView({ block: 'start' });
}
function searchJournal() { page.value = 1; loadActivity(); }
async function inspect(message) { selected.value = message; await nextTick(); auditDialog.value.showModal(); }
function closeAudit() { auditDialog.value.close(); }
function shortDay(value) { const [year, month, day] = value.split('-'); return `${day}/${month}`; }
watch([period, mailboxId], () => { page.value = 1; activity.value = null; refresh(); }, { immediate: true });
watch([kind, actorId], searchJournal);
</script>

<template>
  <div class="admin-dashboard">
    <header class="dashboard-header">
      <div><h1>Tableau de bord</h1><p class="muted">Vos emails et l’activité de votre équipe, au même endroit.</p></div>
      <button class="secondary" :disabled="summaryBusy || activityBusy" @click="refresh"><AppIcon name="refresh" />Actualiser</button>
    </header>
    <div class="dashboard-filters">
      <label>Période<select v-model="period"><option value="all">Toute la période</option><option value="7">7 derniers jours</option><option value="30">30 derniers jours</option><option value="90">90 derniers jours</option></select></label>
      <label>Boîte mail<select v-model="mailboxId"><option value="">Toutes les boîtes</option><option v-for="box in boxes" :key="box.id" :value="String(box.id)">{{ box.email }}</option></select></label>
      <span v-if="dashboard" class="dashboard-updated">Mis à jour {{ date(dashboard.generated_at) }}</span>
    </div>
    <div v-if="error" class="alert" role="alert">{{ error }}</div>
    <p v-if="summaryBusy && !dashboard" class="dashboard-loading" role="status">Chargement du tableau de bord…</p>
    <template v-if="dashboard">
      <section class="dashboard-metrics" aria-label="Chiffres clés" :aria-busy="summaryBusy">
        <button v-for="metric in metrics" :key="metric.key" class="dashboard-metric" @click="filterJournal(metric.filter)">
          <div class="metric-title"><span>{{ metric.label }}</span><AppIcon :name="metric.icon" /></div>
          <strong :class="{ 'metric-warning': metric.key === 'issues' && dashboard.stats.issues }">{{ format(dashboard.stats[metric.key]) }}</strong>
          <small>{{ metric.hint }}</small>
        </button>
      </section>
      <div class="dashboard-context"><span><strong>{{ format(dashboard.stats.delivered) }}</strong> livrés au serveur destinataire</span><button class="link" @click="filterJournal('pending')">{{ format(dashboard.stats.pending) }} en attente d’envoi</button><span><strong>{{ format(dashboard.stats.mailboxes) }}</strong> boîte(s)</span><span><strong>{{ format(dashboard.stats.users) }}</strong> membre(s)</span></div>
      <div class="dashboard-overview">
        <section class="dashboard-panel trend-panel">
          <div class="panel-heading"><div><h2>Activité email</h2><p>{{ trend.length }} derniers jours</p></div><div class="chart-legend"><span><i class="sent-dot"></i>Envoyés</span><span><i class="received-dot"></i>Reçus</span></div></div>
          <div class="trend-chart" role="img" :aria-label="`Emails envoyés et reçus au cours des ${trend.length} derniers jours. Maximum quotidien : ${maximum}.`">
            <div class="chart-scale"><span>{{ format(peak) }}</span><span>{{ format(peak / 2) }}</span><span>0</span></div>
            <div class="chart-columns">
              <div v-for="(day, index) in trend" :key="day.day" class="chart-day" :title="`${shortDay(day.day)} : ${day.sent} envoyé(s), ${day.received} reçu(s)`">
                <div class="chart-bars"><span class="sent-bar" :style="{ height: `${day.sent / peak * 100}%` }"></span><span class="received-bar" :style="{ height: `${day.received / peak * 100}%` }"></span></div>
                <span class="chart-label" :class="{ 'chart-label-visible': index === 0 || index === trend.length - 1 || index % Math.ceil(trend.length / 6) === 0 }">{{ shortDay(day.day) }}</span>
              </div>
            </div>
          </div>
          <div class="sr-only"><table><caption>Activité email quotidienne</caption><thead><tr><th>Date</th><th>Envoyés</th><th>Reçus</th></tr></thead><tbody><tr v-for="day in trend" :key="day.day"><td>{{ day.day }}</td><td>{{ day.sent }}</td><td>{{ day.received }}</td></tr></tbody></table></div>
        </section>
        <section class="dashboard-panel team-panel">
          <div class="panel-heading"><div><h2>Votre équipe</h2><p>Emails de la période sélectionnée</p></div><AppIcon name="users" /></div>
          <div class="team-heading"><span>MEMBRE</span><span>ENVOYÉS</span><span>OUVERTS</span></div>
          <div class="team-list">
            <button v-for="member in dashboard.team" :key="member.id" class="team-row" :aria-label="`Voir l’activité de ${member.name}`" @click="filterJournal('all', member.id)">
              <span class="team-person"><span class="team-avatar">{{ member.name.slice(0, 2).toUpperCase() }}</span><span>{{ member.name }}<small>{{ member.role === 'admin' ? 'Administrateur' : 'Membre' }}</small></span></span><strong>{{ format(member.sent_count) }}</strong><strong>{{ format(member.read_count) }}</strong>
            </button>
          </div>
        </section>
      </div>
    </template>
    <section id="admin-journal" class="dashboard-panel journal-panel">
      <div class="panel-heading"><div><h2>Journal d’activité</h2><p>Qui a envoyé et qui a ouvert chaque email dans Maildesk.</p></div><span v-if="activity" class="journal-count">{{ format(activity.total) }} email(s)</span></div>
      <form class="journal-filters" @submit.prevent="searchJournal">
        <label class="search-label"><span class="sr-only">Rechercher dans le journal</span><input v-model="search" type="search" maxlength="150" placeholder="Objet ou adresse email…"></label>
        <label><span class="sr-only">Type d’activité</span><select v-model="kind"><option value="all">Tous les emails</option><option value="sent">Emails envoyés</option><option value="received">Emails reçus</option><option value="unread">Non ouverts</option><option value="issues">Envois à vérifier</option><option value="pending">En attente</option></select></label>
        <label><span class="sr-only">Utilisateur du journal</span><select v-model="actorId"><option value="">Tous les utilisateurs</option><option v-for="member in dashboard?.team || []" :key="member.id" :value="String(member.id)">{{ member.name }}</option></select></label>
        <button class="secondary">Rechercher</button>
      </form>
      <div v-if="activityError" class="alert" role="alert">{{ activityError }} <button class="link" @click="loadActivity">Réessayer</button></div>
      <p v-if="activityBusy" class="journal-empty" role="status">Chargement du journal…</p>
      <div v-else-if="activity?.data.length" class="journal-table-wrap">
        <table class="journal-table"><thead><tr><th>Email</th><th>Envoyé par</th><th>Ouvert par</th><th>État</th><th><span class="sr-only">Détails</span></th></tr></thead><tbody>
          <tr v-for="message in activity.data" :key="message.id">
            <td class="journal-email"><strong>{{ message.subject || '(Sans objet)' }}</strong><span>{{ message.mailbox.email }} · {{ message.direction === 'inbound' ? 'Reçu' : 'Envoi' }}</span><small>{{ date(message.sent_at || message.message_at) }}</small></td>
            <td data-label="Envoyé par"><span v-if="message.direction === 'outbound'">{{ message.sender?.name || 'Auteur non renseigné' }}</span><span v-else class="journal-muted">{{ message.from_address }}</span></td>
            <td data-label="Ouvert par"><span v-if="message.readers.length">{{ message.readers.slice(0, 2).map(reader => reader.name).join(', ') }}<small v-if="message.readers.length > 2"> +{{ message.readers.length - 2 }}</small></span><span v-else class="journal-muted">Aucune ouverture</span></td>
            <td data-label="État"><span class="audit-status" :class="{ warning: message.delivery_error || ['failed', 'uncertain'].includes(message.status), delivered: message.delivery_status === 'delivered' && !message.delivery_error }">{{ statusLabel(message) }}</span></td>
            <td><button class="link" :aria-label="`Voir l’activité de l’email ${message.subject || 'sans objet'}`" @click="inspect(message)">Voir l’activité <AppIcon name="arrow" /></button></td>
          </tr>
        </tbody></table>
      </div>
      <p v-else-if="!activityError" class="journal-empty">Aucun email ne correspond à ces filtres.</p>
      <div v-if="activity && activity.last_page > 1" class="pagination"><button class="secondary" :disabled="page === 1 || activityBusy" @click="page--; loadActivity()">Précédent</button><span>{{ page }} / {{ activity.last_page }}</span><button class="secondary" :disabled="page === activity.last_page || activityBusy" @click="page++; loadActivity()">Suivant</button></div>
    </section>
    <dialog ref="auditDialog" class="audit-dialog" aria-labelledby="audit-title" @close="selected = null" @click.self="closeAudit">
      <template v-if="selected"><div class="modal-title"><div><h2 id="audit-title">Activité de l’email</h2><p class="muted">{{ selected.subject || '(Sans objet)' }}</p></div><button class="close" aria-label="Fermer l’activité" @click="closeAudit"><AppIcon name="close" /></button></div>
        <dl class="audit-summary"><div><dt>Boîte</dt><dd>{{ selected.mailbox.email }}</dd></div><div><dt>État</dt><dd>{{ statusLabel(selected) }}</dd></div><div v-if="selected.direction === 'inbound'"><dt>Expéditeur</dt><dd>{{ selected.from_address }}</dd></div><div><dt>Destinataires</dt><dd>{{ selected.to.join(', ') }}</dd></div><div v-if="selected.direction === 'outbound'"><dt>Envoi demandé par</dt><dd>{{ selected.sender?.name || 'Auteur non renseigné' }}<small>{{ date(selected.created_at) }}</small></dd></div><div v-if="selected.sent_at"><dt>Accepté pour envoi</dt><dd>{{ date(selected.sent_at) }}</dd></div></dl>
        <h3>Ouvertures dans Maildesk</h3><p class="audit-hint">Première ouverture enregistrée pour chaque utilisateur.</p>
        <ul v-if="selected.readers.length" class="audit-readers"><li v-for="reader in selected.readers" :key="reader.id"><span class="team-avatar">{{ reader.name.slice(0, 2).toUpperCase() }}</span><strong>{{ reader.name }}</strong><time>{{ date(reader.pivot.read_at) }}</time></li></ul><p v-else class="audit-no-read">Aucun utilisateur n’a encore ouvert cet email dans Maildesk.</p>
        <p v-if="selected.delivery_error || selected.send_error" class="alert">{{ selected.delivery_error || selected.send_error }}</p>
      </template>
    </dialog>
  </div>
</template>

<style scoped>
.admin-dashboard{padding:30px;max-width:1600px;margin:auto}.dashboard-header{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:25px}.dashboard-header .muted{margin-top:7px}.dashboard-filters{display:flex;align-items:flex-end;gap:14px;margin-bottom:24px}.dashboard-filters label{display:grid;gap:6px;font-size:12px;color:#78859a;width:210px;min-width:180px;max-width:100%}.dashboard-filters label:nth-child(2){width:320px}.journal-filters input{background:#fff;border:1px solid #dfe4ee;border-radius:8px;padding:10px 12px;min-height:43px;color:#45526a;font-size:13px;max-width:100%}.dashboard-updated{margin-left:auto;color:#8994a5;font-size:11px;padding-bottom:12px}.dashboard-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));border:1px solid #e5e8f1;border-radius:12px;overflow:hidden}.dashboard-metric{border:0;border-right:1px solid #e5e8f1;background:white;padding:22px;text-align:left;color:#657187;min-width:0}.dashboard-metric:last-child{border:0}.dashboard-metric:hover{background:#fafaff}.metric-title{display:flex;justify-content:space-between;align-items:center;gap:12px;font-size:12px;font-weight:500}.metric-title .app-icon{color:#7375db;width:18px;height:18px}.dashboard-metric>strong{display:block;color:#25324b;font-size:34px;letter-spacing:-1px;font-weight:600;margin:13px 0 8px}.dashboard-metric .metric-warning{color:#b85a4c}.dashboard-metric small{font-size:10px;color:#8c96a7;display:block;line-height:1.6}.dashboard-context{display:flex;align-items:center;gap:22px;flex-wrap:wrap;font-size:11px;color:#8490a3;padding:17px 0 25px}.dashboard-context strong{font-weight:600;color:#5e6980}.dashboard-context .link{font-size:11px}.dashboard-overview{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(280px,1fr);gap:20px;margin-bottom:25px}.dashboard-panel{border:1px solid #e5e8f1;border-radius:12px;min-width:0;background:white;overflow:hidden}.panel-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:22px 22px 18px}.panel-heading h2{font-size:15px;font-weight:600;letter-spacing:-.2px}.panel-heading p{font-size:11px;color:#8994a6;margin-top:5px}.panel-heading>.app-icon{color:#929db1}.chart-legend{display:flex;gap:13px;flex-wrap:wrap;font-size:10px;color:#8590a3}.chart-legend span{display:flex;align-items:center;gap:5px}.chart-legend i{width:6px;height:6px;border-radius:50%;display:inline-block}.sent-dot,.sent-bar{background:#6364dd}.received-dot,.received-bar{background:#5bbda8}.trend-chart{display:flex;height:205px;margin:5px 22px 22px;gap:12px}.chart-scale{display:flex;flex-direction:column;justify-content:space-between;padding-bottom:26px;font-size:10px;color:#9aa3b2;min-width:15px}.chart-columns{display:flex;gap:5px;flex:1;min-width:0;background:repeating-linear-gradient(to top,transparent 0,transparent calc(50% - 1px),#f0f2f7 calc(50% - 1px),#f0f2f7 50%);background-size:100% calc(100% - 26px)}.chart-day{flex:1;min-width:0;display:flex;flex-direction:column;justify-content:flex-end;gap:12px}.chart-bars{display:flex;gap:3px;align-items:flex-end;justify-content:center;height:100%;border-bottom:1px solid #e8ebf2}.chart-bars span{width:38%;max-width:15px;border-radius:3px 3px 0 0;min-height:0}.chart-label{height:14px;font-size:9px;color:#96a0b0;text-align:center;visibility:hidden;white-space:nowrap}.chart-label-visible{visibility:visible}.team-heading,.team-row{display:grid;grid-template-columns:minmax(0,1fr) 58px 58px;align-items:center;gap:7px}.team-heading{font-size:8px;color:#96a1b3;letter-spacing:.5px;padding:5px 22px 12px}.team-heading span:not(:first-child){text-align:right}.team-list{max-height:220px;overflow:auto}.team-row{width:100%;padding:14px 22px;background:white;border:0;border-top:1px solid #f0f2f7;text-align:left;color:#5e6a81;font-size:11px}.team-row:hover{background:#fafaff}.team-row>strong{text-align:right;font-size:13px;font-weight:500}.team-person{display:flex;align-items:center;gap:9px;min-width:0}.team-person>span:last-child{overflow-wrap:anywhere}.team-person small{display:block;color:#98a2b3;font-size:9px;margin-top:4px}.team-avatar{display:grid;place-items:center;width:31px;height:31px;flex-shrink:0;background:#f0f0fc;border-radius:9px;color:#6869c9;font-size:10px;font-weight:600}.journal-count{font-size:11px;color:#8a95a8;white-space:nowrap}.journal-filters{display:flex;align-items:center;gap:9px;padding:0 22px 20px;flex-wrap:wrap}.journal-filters .search-label{flex:1;min-width:170px}.journal-filters input{width:100%}.journal-filters label:not(.search-label){flex:0 1 210px;min-width:180px;max-width:100%}.journal-filters>.secondary{margin-left:auto}.journal-filters .secondary{font-size:12px}.journal-table-wrap{overflow:auto}.journal-table{border-collapse:collapse;width:100%;text-align:left;font-size:11px}.journal-table th{padding:13px 20px;background:#fafbfe;color:#98a1b3;font-weight:500;font-size:10px;white-space:nowrap}.journal-table td{padding:18px 20px;border-top:1px solid #edf0f5;color:#66738a;vertical-align:middle;line-height:1.7}.journal-email{min-width:210px;max-width:330px}.journal-email strong{display:block;color:#344259;font-weight:500;overflow-wrap:anywhere}.journal-email span,.journal-email small{display:block;color:#97a1b1;font-size:10px;overflow-wrap:anywhere}.journal-email small{font-size:9px}.journal-table .link{font-size:10px;white-space:nowrap}.journal-table .link .app-icon{width:14px;height:14px}.journal-muted{color:#a0a8b7}.audit-status{display:inline-block;font-size:10px;color:#758099;max-width:150px;line-height:1.6}.audit-status.warning{color:#b56253}.audit-status.delivered{color:#33957e}.journal-empty,.dashboard-loading{text-align:center;padding:40px 20px;color:#8c97a9;font-size:13px}.journal-panel>.alert{margin:0 22px 20px}.audit-dialog{width:min(560px,calc(100% - 30px));max-height:85vh;overflow:auto;border:1px solid #e2e6ef;border-radius:14px;padding:26px;color:#344259;box-shadow:0 25px 80px #12213a26}.audit-dialog::backdrop{background:#14223b78}.audit-dialog h2{font-size:20px}.audit-dialog h3{font-size:13px;margin-top:25px}.audit-dialog .modal-title .muted{overflow-wrap:anywhere}.audit-summary{display:grid;gap:16px;margin:25px 0}.audit-summary>div{display:grid;grid-template-columns:145px minmax(0,1fr);gap:12px;font-size:12px}.audit-summary dt{color:#8e98aa}.audit-summary dd{margin:0;overflow-wrap:anywhere}.audit-summary small{display:block;color:#8e98aa;font-size:11px;margin-top:4px}.audit-hint{font-size:11px;color:#8c98aa;margin-top:7px}.audit-readers{list-style:none;padding:0;margin:15px 0 20px}.audit-readers li{display:flex;align-items:center;gap:10px;padding:13px 0;border-bottom:1px solid #edf0f5;font-size:12px}.audit-readers strong{font-weight:500}.audit-readers time{margin-left:auto;color:#8c98aa;font-size:10px}.audit-no-read{font-size:12px;color:#909bad;margin:18px 0}.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap}
@media(max-width:1150px){.dashboard-overview{grid-template-columns:1fr}.team-list{max-height:210px}.dashboard-metric{padding:18px 14px}.metric-title{font-size:11px}.dashboard-filters{flex-wrap:wrap}.dashboard-updated{margin-left:0}.journal-table td,.journal-table th{padding-left:14px;padding-right:14px}}
@media(max-width:760px){.admin-dashboard{padding:24px 15px}.dashboard-header{align-items:flex-start;flex-direction:column}.dashboard-header h1{font-size:23px}.dashboard-header .muted{font-size:12px}.dashboard-filters{gap:10px}.dashboard-filters label,.dashboard-filters label:nth-child(2){width:100%;min-width:0}.dashboard-filters select{width:100%}.dashboard-updated{font-size:10px;padding:0}.dashboard-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.dashboard-metric:nth-child(2){border-right:0}.dashboard-metric:nth-child(-n+2){border-bottom:1px solid #e5e8f1}.dashboard-metric>strong{font-size:29px}.metric-title .app-icon{display:none}.dashboard-context{gap:12px;font-size:10px}.panel-heading{padding:18px 15px}.panel-heading p{font-size:10px}.chart-legend{gap:8px;font-size:9px}.trend-chart{margin-left:12px;margin-right:12px;height:170px;gap:7px}.chart-columns{gap:2px}.chart-bars{gap:2px}.chart-label{font-size:7px}.journal-filters{padding:0 15px 15px;gap:8px}.journal-filters label,.journal-filters label:not(.search-label){width:100%;min-width:0;max-width:100%;flex:1 1 100%}.journal-table thead{display:none}.journal-table,.journal-table tbody,.journal-table tr,.journal-table td{display:block}.journal-table tr{padding:15px;border-top:1px solid #edf0f5}.journal-table td{border:0;padding:5px 0;font-size:11px}.journal-table td[data-label]::before{content:attr(data-label) ' : ';color:#9ca5b4;font-size:10px}.journal-email{max-width:100%;min-width:0}.journal-table td:last-child{padding-top:10px}.journal-table .link{font-size:11px}.audit-summary>div{grid-template-columns:1fr;gap:4px}.audit-readers li{flex-wrap:wrap}.audit-readers time{margin-left:41px;width:100%}.audit-dialog{padding:21px}.team-heading,.team-row{padding-left:15px;padding-right:15px}}
</style>
