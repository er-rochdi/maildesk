<script setup>
import { ref, computed, onMounted } from 'vue';
import AppIcon from './components/AppIcon.vue';
import MailWorkspace from './components/MailWorkspace.vue';
import AdminDashboard from './components/AdminDashboard.vue';
import { api } from './api.js';

const user = ref(null), page = ref('inbox'), loading = ref(true), error = ref(''), notice = ref('');
const authMode = ref('login'), boxes = ref([]), users = ref([]), selected = ref(null);
const auth = ref({ company: '', name: '', email: '', password: '', password_confirmation: '' });
const person = ref({ name: '', email: '', password: '', role: 'member' });
const box = ref({ name: '', email: '', imap_host: '', imap_port: 993, smtp_host: '', smtp_port: 587, sent_folder: 'Sent', password: '', user_ids: [] });
const editingBox = ref(null);
const showingBox = ref(false), showingUser = ref(false), saving = ref(false);
const isAdmin = computed(() => user.value?.role === 'admin');
async function refresh() {
  boxes.value = await api('/workspace/mailboxes');
  if (isAdmin.value) users.value = await api('/workspace/users');
}
async function init() { try { user.value = await api('/auth/me'); page.value = isAdmin.value ? 'dashboard' : 'inbox'; await refresh(); } catch (e) { user.value = null; if (e.status !== 401) error.value = 'Impossible de charger l’espace. Vérifiez la connexion et réessayez.'; } finally { loading.value = false; } }
async function submitAuth() {
  error.value = ''; saving.value = true;
  try { user.value = await api('/auth/' + (authMode.value === 'login' ? 'login' : 'register'), { method: 'POST', body: JSON.stringify(auth.value) }); page.value = isAdmin.value ? 'dashboard' : 'inbox'; await refresh(); }
  catch (e) { error.value = e.message; } finally { saving.value = false; }
}
async function logout() { await api('/auth/logout', { method: 'POST' }); location.reload(); }
async function createUser() {
  error.value = ''; saving.value = true;
  try { await api('/workspace/users', { method: 'POST', body: JSON.stringify(person.value) }); person.value = { name: '', email: '', password: '', role: 'member' }; showingUser.value = false; await refresh(); notice.value = 'Utilisateur ajouté.'; }
  catch (e) { error.value = e.message; } finally { saving.value = false; }
}
async function createBox() {
  error.value = ''; saving.value = true;
  try { await api('/workspace/mailboxes' + (editingBox.value ? '/' + editingBox.value : ''), { method: editingBox.value ? 'PUT' : 'POST', body: JSON.stringify(box.value) }); box.value = { name: '', email: '', imap_host: '', imap_port: 993, smtp_host: '', smtp_port: 587, sent_folder: 'Sent', password: '', user_ids: [] }; showingBox.value = false; await refresh(); notice.value = 'Boîte enregistrée. Lancez la synchronisation depuis la boîte de réception.'; editingBox.value = null; }
  catch (e) { error.value = e.message; } finally { saving.value = false; }
}
async function saveAccess(mailbox) {
  error.value = ''; saving.value = true;
  try { await api('/workspace/mailboxes/' + mailbox.id + '/access', { method: 'PUT', body: JSON.stringify({ user_ids: mailbox.users.map(u => u.id) }) }); await refresh(); notice.value = 'Accès enregistrés.'; }
  catch (e) { error.value = e.message; } finally { saving.value = false; }
}
function editBox(mailbox) {
  editingBox.value = mailbox.id;
  box.value = { name: mailbox.name, email: mailbox.email, imap_host: mailbox.imap_host, imap_port: mailbox.imap_port, smtp_host: mailbox.smtp_host, smtp_port: mailbox.smtp_port, sent_folder: mailbox.sent_folder, password: '', user_ids: mailbox.users.map(u => u.id) };
  error.value = ''; showingBox.value = true;
}
function addBox() {
  editingBox.value = null;
  box.value = { name: '', email: '', imap_host: '', imap_port: 993, smtp_host: '', smtp_port: 587, sent_folder: 'Sent', password: '', user_ids: [] };
  error.value = ''; showingBox.value = true;
}
function toggleAccess(mailbox, u, checked) { mailbox.users = checked ? [...mailbox.users, u] : mailbox.users.filter(x => x.id !== u.id); }
function changePage(value) { page.value = value; error.value = ''; notice.value = ''; selected.value = null; }
onMounted(init);
</script>
<template>
  <div v-if="loading" class="loading">Chargement…</div>
  <div v-else-if="!user" class="auth-wrap">
    <div class="auth-intro"><div class="brand"><span class="brand-icon"><AppIcon name="mail" /></span> Maildesk</div><div><p class="intro-label">VOTRE ESPACE DE TRAVAIL</p><h1>Vos emails.<br>En équipe.</h1><p>Un espace pour vos boîtes professionnelles et les personnes qui travaillent avec vous.</p></div><small>Une boîte partagée, une équipe connectée.</small></div>
    <div class="auth-main"><form class="auth-form" @submit.prevent="submitAuth"><p class="overline">BIENVENUE SUR MAILDESK</p><h2>{{ authMode === 'login' ? 'Heureux de vous retrouver' : 'Créez votre espace' }}</h2><p class="muted">{{ authMode === 'login' ? 'Connectez-vous à votre espace de travail.' : 'Commencez par créer votre société et votre compte administrateur.' }}</p>
      <div v-if="error" class="alert">{{ error }}</div>
      <template v-if="authMode === 'register'"><label>Société<input v-model="auth.company" required placeholder="Nom de votre société"></label><label>Votre nom<input v-model="auth.name" required placeholder="Votre nom complet"></label></template>
      <label>Adresse email<input v-model="auth.email" type="email" required placeholder="vous@entreprise.com"></label><label>Mot de passe<input v-model="auth.password" type="password" minlength="8" required placeholder="8 caractères minimum"></label>
      <label v-if="authMode === 'register'">Confirmer le mot de passe<input v-model="auth.password_confirmation" type="password" required></label>
      <button class="primary wide" :disabled="saving">{{ authMode === 'login' ? 'Se connecter' : 'Créer mon espace' }}</button>
      <p class="switch">{{ authMode === 'login' ? 'Pas encore de compte ?' : 'Déjà un compte ?' }} <button type="button" class="link" @click="authMode = authMode === 'login' ? 'register' : 'login'; error = ''">{{ authMode === 'login' ? 'Créer un espace' : 'Se connecter' }}</button></p>
    </form></div>
  </div>
  <div v-else class="app-shell">
    <aside class="sidebar">
      <div>
        <div class="brand"><AppIcon name="mail" /> <span>Maildesk</span></div>
        <div class="company"><div class="company-avatar">{{ user.company?.name?.slice(0,1).toUpperCase() }}</div><div class="company-details"><strong>{{ user.company?.name }}</strong><small>Espace de travail</small></div></div>
        <nav aria-label="Navigation principale">
          <button v-if="isAdmin" :class="{active: page === 'dashboard'}" :aria-current="page === 'dashboard' ? 'page' : undefined" title="Tableau de bord" @click="changePage('dashboard')"><AppIcon name="dashboard" /><span>Tableau de bord</span></button>
          <button :class="{active: page === 'inbox'}" :aria-current="page === 'inbox' ? 'page' : undefined" title="Boîte de réception" @click="changePage('inbox')"><AppIcon name="mail" /><span>Boîte de réception</span></button>
          <button :class="{active: page === 'sent'}" :aria-current="page === 'sent' ? 'page' : undefined" title="Envoyés" @click="changePage('sent')"><AppIcon name="send" /><span>Envoyés</span></button>
          <button :class="{active: page === 'boxes'}" :aria-current="page === 'boxes' ? 'page' : undefined" title="Boîtes mail" @click="changePage('boxes')"><AppIcon name="inbox" /><span>Boîtes mail</span></button>
          <button v-if="isAdmin" :class="{active: page === 'users'}" :aria-current="page === 'users' ? 'page' : undefined" title="Utilisateurs" @click="changePage('users')"><AppIcon name="users" /><span>Utilisateurs</span></button>
        </nav>
      </div>
      <div class="profile"><div class="avatar">{{ user.name?.slice(0,2).toUpperCase() }}</div><div class="profile-details"><strong>{{ user.name }}</strong><small>{{ isAdmin ? 'Administrateur' : 'Membre' }}</small></div><button class="logout" @click="logout" title="Se déconnecter" aria-label="Se déconnecter"><AppIcon name="logout" /></button></div>
    </aside>
    <main class="main"><div v-if="notice" class="notice">{{ notice }} <button @click="notice = ''">×</button></div><div v-if="error" class="alert top-alert">{{ error }}</div>
      <AdminDashboard v-if="page === 'dashboard' && isAdmin" :api="api" :boxes="boxes" />
      <MailWorkspace v-if="page === 'inbox' || page === 'sent'" :api="api" :boxes="boxes" :direction="page === 'inbox' ? 'inbound' : 'outbound'" @notice="notice = $event" @error="error = $event" />
      <section v-if="page === 'boxes'" class="content"><header class="page-header"><div><h1>Boîtes mail</h1><p class="muted">Gérez vos adresses et les accès de votre équipe.</p></div><button v-if="isAdmin" class="primary" @click="addBox"><AppIcon name="plus" />Ajouter une boîte</button></header>
        <div v-if="!boxes.length" class="empty-card"><div class="empty-icon"><AppIcon name="inbox" /></div><h3>Aucune boîte pour le moment</h3><p>Ajoutez votre première adresse professionnelle pour commencer.</p><button v-if="isAdmin" class="primary" @click="addBox"><AppIcon name="plus" />Ajouter une boîte</button></div>
        <div v-for="mailbox in boxes" :key="mailbox.id" class="box-card"><div class="box-head"><div class="box-avatar"><AppIcon name="mail" /></div><div><h3>{{ mailbox.name }}</h3><p>{{ mailbox.email }}</p></div><button v-if="isAdmin" class="secondary mailbox-edit" @click="editBox(mailbox)">Configurer</button></div><div v-if="isAdmin" class="access"><strong>Accès à la boîte</strong><p class="muted">Choisissez les membres qui peuvent accéder à cette boîte.</p><div class="checks"><label v-for="u in users" :key="u.id"><input type="checkbox" :checked="mailbox.users.some(x => x.id === u.id)" @change="toggleAccess(mailbox, u, $event.target.checked)"> {{ u.name }} <small>({{ u.email }})</small></label></div><button class="secondary" :disabled="saving" @click="saveAccess(mailbox)">Enregistrer les accès</button></div></div>
      </section>
      <section v-if="page === 'users' && isAdmin" class="content"><header class="page-header"><div><h1>Utilisateurs</h1><p class="muted">Les membres de votre société.</p></div><button class="primary" @click="showingUser = true; error = ''"><AppIcon name="plus" />Ajouter un utilisateur</button></header><div class="table"><div class="table-head"><span>MEMBRE</span><span>RÔLE</span></div><div v-for="u in users" :key="u.id" class="table-row"><div class="member"><div class="avatar light">{{ u.name.slice(0,2).toUpperCase() }}</div><div><strong>{{ u.name }}</strong><small>{{ u.email }}</small></div></div><span>{{ u.role === 'admin' ? 'Administrateur' : 'Membre' }}</span></div></div></section>
    </main>
    <div v-if="showingBox || showingUser" class="modal-bg" @click.self="showingBox = false; showingUser = false"><form class="modal" @submit.prevent="showingBox ? createBox() : createUser()"><div class="modal-title"><div><h2>{{ showingBox ? (editingBox ? 'Configurer la boîte mail' : 'Ajouter une boîte mail') : 'Ajouter un utilisateur' }}</h2><p class="muted">{{ showingBox ? 'Renseignez les paramètres de votre boîte Hestia.' : 'Créez un accès pour un membre de votre équipe.' }}</p></div><button type="button" class="close" aria-label="Fermer" @click="showingBox = false; showingUser = false"><AppIcon name="close" /></button></div><div v-if="error" class="alert">{{ error }}</div>
      <template v-if="showingBox"><label>Nom de la boîte<input v-model="box.name" required placeholder="Contact"></label><label>Adresse email<input v-model="box.email" type="email" :readonly="!!editingBox" required placeholder="contact@entreprise.com"></label><div class="form-grid"><label>Serveur IMAP<input v-model="box.imap_host" required placeholder="mail.entreprise.com"></label><label>Port IMAP<input v-model.number="box.imap_port" type="number" min="1" max="65535" required></label><label>Serveur SMTP<input v-model="box.smtp_host" required placeholder="mail.entreprise.com"></label><label>Port SMTP<input v-model.number="box.smtp_port" type="number" min="1" max="65535" required></label></div><label>Dossier IMAP des messages envoyés<input v-model="box.sent_folder" required placeholder="Sent"></label><label>{{ editingBox ? 'Nouveau mot de passe (laisser vide pour conserver)' : 'Mot de passe de la boîte' }}<input v-model="box.password" type="password" :required="!editingBox"></label><div v-if="!editingBox" class="checks"><strong>Donner accès à</strong><label v-for="u in users" :key="u.id"><input type="checkbox" v-model="box.user_ids" :value="u.id"> {{ u.name }}</label></div><p class="form-hint">IMAP : SSL sur le port 993. SMTP : TLS sur 587 ou SSL sur 465. Les mots de passe sont chiffrés.</p></template>
      <template v-else><label>Nom complet<input v-model="person.name" required></label><label>Adresse email<input v-model="person.email" type="email" required></label><label>Mot de passe initial<input v-model="person.password" type="password" minlength="8" required></label><label>Rôle<select v-model="person.role"><option value="member">Membre</option><option value="admin">Administrateur</option></select></label></template>
      <div class="modal-actions"><button type="button" class="secondary" @click="showingBox = false; showingUser = false">Annuler</button><button class="primary" :disabled="saving">Enregistrer</button></div>
    </form></div>
  </div>
</template>
