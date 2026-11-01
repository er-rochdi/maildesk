<script setup>
import { ref, computed, onMounted } from 'vue';
import AppIcon from './components/AppIcon.vue';
import MailWorkspace from './components/MailWorkspace.vue';
import AdminDashboard from './components/AdminDashboard.vue';
import ProfileSettings from './components/ProfileSettings.vue';
import PasswordRecovery from './components/PasswordRecovery.vue';
import { api as request } from './api.js';

const user = ref(null), page = ref('inbox'), loading = ref(true), error = ref(''), notice = ref('');
const authMode = ref('login'), boxes = ref([]), users = ref([]), selected = ref(null);
const auth = ref({ company: '', name: '', email: '', password: '', password_confirmation: '' });
const defaultPermissions = ['read_emails', 'send_emails', 'sync_mailboxes'];
const person = ref({ name: '', email: '', password: '', role: 'member', permissions: [...defaultPermissions] });
const editingPermissions = ref(null), permissionDraft = ref([]), permissionError = ref(''), permissionsSaving = ref(false);
const editingAccount = ref(null), accountDraft = ref({ name: '', email: '', current_password: '' }), accountError = ref(''), accountSaving = ref(false), statusTarget = ref(null);
const resetToken = ref(''), resetEmail = ref('');
const permissionOptions = [
  { value: 'read_emails', label: 'Lire les emails', description: 'Consulter les messages et télécharger leurs pièces jointes dans les boîtes attribuées.' },
  { value: 'send_emails', label: 'Envoyer des emails', description: 'Créer des envois ; répondre et transférer nécessite aussi la permission de lecture.' },
  { value: 'sync_mailboxes', label: 'Synchroniser les boîtes', description: 'Lancer manuellement la synchronisation des boîtes attribuées.' },
  { value: 'view_send_activity', label: 'Voir qui a demandé l’envoi', description: 'Afficher l’auteur des emails envoyés depuis les boîtes accessibles.' },
  { value: 'view_read_activity', label: 'Voir qui a lu les emails', description: 'Afficher les utilisateurs et leur première date d’ouverture dans Maildesk.' },
];
permissionOptions.push(
  { value: 'view_dashboard', label: 'Voir le tableau de bord et le journal', description: 'Consulter les statistiques et le journal des boîtes attribuées, selon les permissions d’activité.' },
  { value: 'manage_users', label: 'Gérer les utilisateurs', description: 'Lister et créer des comptes membres. Seul un administrateur peut attribuer des rôles et permissions.' },
  { value: 'manage_mailboxes', label: 'Gérer les boîtes et les accès', description: 'Créer et configurer toutes les boîtes de la société, et attribuer leurs accès.' },
);
const box = ref({ name: '', email: '', imap_host: '', imap_port: 993, smtp_host: '', smtp_port: 587, sent_folder: 'Sent', password: '', user_ids: [] });
const editingBox = ref(null);
const showingBox = ref(false), showingUser = ref(false), saving = ref(false);
const isAdmin = computed(() => user.value?.role === 'admin');
const permissionsFor = member => member.role === 'admin' ? permissionOptions.map(option => option.value) : (member.permissions ?? defaultPermissions);
const can = permission => !!user.value && permissionsFor(user.value).includes(permission);
const canUseMail = computed(() => ['read_emails', 'send_emails', 'sync_mailboxes'].some(can));
const accessibleBoxes = computed(() => isAdmin.value ? boxes.value : boxes.value.filter(mailbox => mailbox.users.some(member => member.id === user.value?.id)));
const initialPage = () => can('view_dashboard') ? 'dashboard' : (canUseMail.value ? 'inbox' : (can('manage_users') ? 'users' : 'boxes'));
async function api(url, options) {
  try { return await request(url, options); }
  catch (e) {
    if (e.status === 401 && user.value) {
      user.value = null; boxes.value = []; users.value = []; selected.value = null;
      showingBox.value = false; showingUser.value = false; editingAccount.value = null; editingPermissions.value = null; statusTarget.value = null;
      authMode.value = 'login'; notice.value = ''; error.value = e.message;
    }
    throw e;
  }
}
async function refresh() {
  boxes.value = await api('/workspace/mailboxes');
  if (can('manage_users') || can('manage_mailboxes')) users.value = await api('/workspace/users');
}
async function init() {
  if (location.pathname?.startsWith('/reset-password/')) {
    resetToken.value = location.pathname.slice('/reset-password/'.length);
    resetEmail.value = new URLSearchParams(location.search).get('email') || ''; authMode.value = 'reset'; loading.value = false; return;
  }
  try { user.value = await api('/auth/me'); page.value = initialPage(); await refresh(); } catch (e) { user.value = null; if (e.status !== 401) error.value = 'Impossible de charger l’espace. Vérifiez la connexion et réessayez.'; } finally { loading.value = false; }
}
function backToLogin() { authMode.value = 'login'; resetToken.value = ''; resetEmail.value = ''; error.value = ''; history.replaceState({}, '', '/'); }
async function submitAuth() {
  error.value = ''; saving.value = true;
  try { user.value = await api('/auth/' + (authMode.value === 'login' ? 'login' : 'register'), { method: 'POST', body: JSON.stringify(auth.value) }); page.value = initialPage(); await refresh(); }
  catch (e) { error.value = e.message; } finally { saving.value = false; }
}
async function logout() {
  error.value = '';
  try { await api('/auth/logout', { method: 'POST' }); location.reload(); }
  catch (e) { error.value = e.message; }
}
async function createUser() {
  error.value = ''; saving.value = true;
  try { await api('/workspace/users', { method: 'POST', body: JSON.stringify(isAdmin.value ? person.value : { ...person.value, role: 'member', permissions: [] }) }); person.value = { name: '', email: '', password: '', role: 'member', permissions: [...defaultPermissions] }; showingUser.value = false; await refresh(); notice.value = 'Utilisateur ajouté.'; }
  catch (e) { error.value = e.message; } finally { saving.value = false; }
}
function editAccount(member) { editingAccount.value = member; accountDraft.value = { name: member.name, email: member.email, current_password: '' }; accountError.value = ''; }
async function saveAccount() {
  accountSaving.value = true; accountError.value = '';
  try { const updated = await api(`/workspace/users/${editingAccount.value.id}`, { method: 'PUT', body: JSON.stringify(accountDraft.value) }); users.value = users.value.map(member => member.id === updated.id ? updated : member); editingAccount.value = null; await refresh(); notice.value = 'Compte utilisateur enregistré.'; }
  catch (e) { accountError.value = e.message; } finally { accountSaving.value = false; }
}
async function changeAccountStatus() {
  accountSaving.value = true; accountError.value = '';
  try { const target = statusTarget.value; const updated = await api(`/workspace/users/${target.id}/status`, { method: 'PUT', body: JSON.stringify({ is_active: target.is_active === false }) }); users.value = users.value.map(member => member.id === updated.id ? updated : member); statusTarget.value = null; notice.value = updated.is_active ? 'Compte réactivé.' : 'Compte désactivé. Ses sessions et ses envois en attente ont été annulés.'; }
  catch (e) { accountError.value = e.message; } finally { accountSaving.value = false; }
}
function editPermissions(member) {
  editingPermissions.value = member; permissionDraft.value = [...permissionsFor(member)]; permissionError.value = '';
}
async function savePermissions() {
  permissionsSaving.value = true; permissionError.value = '';
  try {
    const updated = await api(`/workspace/users/${editingPermissions.value.id}/permissions`, { method: 'PUT', body: JSON.stringify({ permissions: permissionDraft.value }) });
    users.value = users.value.map(member => member.id === updated.id ? updated : member);
    editingPermissions.value = null; notice.value = 'Permissions enregistrées.';
  } catch (e) { permissionError.value = e.message; } finally { permissionsSaving.value = false; }
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
    <div class="auth-main"><PasswordRecovery v-if="authMode === 'forgot' || authMode === 'reset'" :api="api" :token="resetToken" :email="resetEmail" @back="backToLogin" /><form v-else class="auth-form" @submit.prevent="submitAuth"><p class="overline">BIENVENUE SUR MAILDESK</p><h2>{{ authMode === 'login' ? 'Heureux de vous retrouver' : 'Créez votre espace' }}</h2><p class="muted">{{ authMode === 'login' ? 'Connectez-vous à votre espace de travail.' : 'Commencez par créer votre société et votre compte administrateur.' }}</p>
      <div v-if="error" class="alert">{{ error }}</div>
      <template v-if="authMode === 'register'"><label>Société<input v-model="auth.company" required placeholder="Nom de votre société"></label><label>Votre nom<input v-model="auth.name" required placeholder="Votre nom complet"></label></template>
      <label>Adresse email<input v-model="auth.email" type="email" required placeholder="vous@entreprise.com"></label><label>Mot de passe<input v-model="auth.password" type="password" minlength="8" required placeholder="8 caractères minimum"></label>
      <label v-if="authMode === 'register'">Confirmer le mot de passe<input v-model="auth.password_confirmation" type="password" required></label>
      <button class="primary wide" :disabled="saving">{{ authMode === 'login' ? 'Se connecter' : 'Créer mon espace' }}</button>
      <p v-if="authMode === 'login'" class="switch"><button type="button" class="link" @click="authMode = 'forgot'; error = ''">Mot de passe oublié ?</button></p>
      <p class="switch">{{ authMode === 'login' ? 'Pas encore de compte ?' : 'Déjà un compte ?' }} <button type="button" class="link" @click="authMode = authMode === 'login' ? 'register' : 'login'; error = ''">{{ authMode === 'login' ? 'Créer un espace' : 'Se connecter' }}</button></p>
    </form></div>
  </div>
  <div v-else class="app-shell">
    <aside class="sidebar">
      <div>
        <div class="brand"><AppIcon name="mail" /> <span>Maildesk</span></div>
        <div class="company"><div class="company-avatar">{{ user.company?.name?.slice(0,1).toUpperCase() }}</div><div class="company-details"><strong>{{ user.company?.name }}</strong><small>Espace de travail</small></div></div>
        <nav aria-label="Navigation principale">
          <button v-if="can('view_dashboard')" :class="{active: page === 'dashboard'}" :aria-current="page === 'dashboard' ? 'page' : undefined" title="Tableau de bord" @click="changePage('dashboard')"><AppIcon name="dashboard" /><span>Tableau de bord</span></button>
          <button v-if="canUseMail" :class="{active: page === 'inbox'}" :aria-current="page === 'inbox' ? 'page' : undefined" title="Boîte de réception" @click="changePage('inbox')"><AppIcon name="mail" /><span>Boîte de réception</span></button>
          <button v-if="can('read_emails')" :class="{active: page === 'sent'}" :aria-current="page === 'sent' ? 'page' : undefined" title="Envoyés" @click="changePage('sent')"><AppIcon name="send" /><span>Envoyés</span></button>
          <button :class="{active: page === 'boxes'}" :aria-current="page === 'boxes' ? 'page' : undefined" title="Boîtes mail" @click="changePage('boxes')"><AppIcon name="inbox" /><span>Boîtes mail</span></button>
          <button v-if="can('manage_users')" :class="{active: page === 'users'}" :aria-current="page === 'users' ? 'page' : undefined" title="Utilisateurs" @click="changePage('users')"><AppIcon name="users" /><span>Utilisateurs</span></button>
          <button :class="{active: page === 'profile'}" :aria-current="page === 'profile' ? 'page' : undefined" title="Mon profil" @click="changePage('profile')"><AppIcon name="users" /><span>Mon profil</span></button>
        </nav>
      </div>
      <div class="profile"><div class="avatar">{{ user.name?.slice(0,2).toUpperCase() }}</div><div class="profile-details"><strong>{{ user.name }}</strong><small>{{ isAdmin ? 'Administrateur' : 'Membre' }}</small></div><button class="logout" @click="logout" title="Se déconnecter" aria-label="Se déconnecter"><AppIcon name="logout" /></button></div>
    </aside>
    <main class="main"><div v-if="notice" class="notice">{{ notice }} <button @click="notice = ''">×</button></div><div v-if="error" class="alert top-alert">{{ error }}</div>
      <ProfileSettings v-if="page === 'profile'" :api="api" :user="user" @updated="user = $event" @notice="notice = $event" />
      <AdminDashboard v-if="page === 'dashboard' && can('view_dashboard')" :api="api" :boxes="accessibleBoxes" :can-view-send-activity="can('view_send_activity')" :can-view-read-activity="can('view_read_activity')" />
      <MailWorkspace v-if="(page === 'inbox' || page === 'sent') && canUseMail" :api="api" :boxes="accessibleBoxes" :signature="user.signature || ''" :can-read="can('read_emails')" :can-send="can('send_emails')" :can-sync="can('sync_mailboxes')" :direction="page === 'inbox' ? 'inbound' : 'outbound'" @notice="notice = $event" @error="error = $event" />
      <section v-if="page === 'boxes'" class="content"><header class="page-header"><div><h1>Boîtes mail</h1><p class="muted">Gérez vos adresses et les accès de votre équipe.</p></div><button v-if="can('manage_mailboxes')" class="primary" @click="addBox"><AppIcon name="plus" />Ajouter une boîte</button></header>
        <div v-if="!boxes.length" class="empty-card"><div class="empty-icon"><AppIcon name="inbox" /></div><h3>Aucune boîte pour le moment</h3><p>Ajoutez votre première adresse professionnelle pour commencer.</p><button v-if="can('manage_mailboxes')" class="primary" @click="addBox"><AppIcon name="plus" />Ajouter une boîte</button></div>
        <div v-for="mailbox in boxes" :key="mailbox.id" class="box-card"><div class="box-head"><div class="box-avatar"><AppIcon name="mail" /></div><div><h3>{{ mailbox.name }}</h3><p>{{ mailbox.email }}</p></div><button v-if="can('manage_mailboxes')" class="secondary mailbox-edit" @click="editBox(mailbox)">Configurer</button></div><div v-if="can('manage_mailboxes')" class="access"><strong>Accès à la boîte</strong><p class="muted">Choisissez les membres qui peuvent accéder à cette boîte.</p><div class="checks"><label v-for="u in users" :key="u.id"><input type="checkbox" :checked="mailbox.users.some(x => x.id === u.id)" @change="toggleAccess(mailbox, u, $event.target.checked)"> {{ u.name }} <small>({{ u.email }})</small></label></div><button class="secondary" :disabled="saving" @click="saveAccess(mailbox)">Enregistrer les accès</button></div></div>
      </section>
      <section v-if="page === 'users' && can('manage_users')" class="content">
        <header class="page-header"><div><h1>Utilisateurs</h1><p class="muted">Les membres de votre société.</p></div><button class="primary" @click="showingUser = true; error = ''"><AppIcon name="plus" />Ajouter un utilisateur</button></header>
        <div class="table users-table"><div class="table-head"><span>MEMBRE</span><span>RÔLE / STATUT</span><span>PERMISSIONS</span><span>ACTIONS</span></div>
          <div v-for="u in users" :key="u.id" class="table-row">
            <div class="member"><div class="avatar light">{{ u.name.slice(0,2).toUpperCase() }}</div><div><strong>{{ u.name }}</strong><small>{{ u.email }}</small></div></div>
            <div><span>{{ u.role === 'admin' ? 'Administrateur' : 'Membre' }}</span><small class="account-status" :class="{inactive: u.is_active === false}">{{ u.is_active === false ? 'Désactivé' : 'Actif' }}</small></div>
            <span v-if="u.role === 'admin'" class="muted">Tous les droits</span><button v-else-if="isAdmin" class="secondary" :aria-label="`Permissions de ${u.name}`" @click="editPermissions(u)">Permissions ({{ permissionsFor(u).length }}/{{ permissionOptions.length }})</button><span v-else class="muted">Gérées par un administrateur</span>
            <div v-if="isAdmin && u.id !== user.id" class="account-actions"><button class="secondary" :aria-label="`Modifier ${u.name}`" @click="editAccount(u)">Modifier</button><button class="secondary" :class="{danger: u.is_active !== false}" :aria-label="`${u.is_active === false ? 'Réactiver' : 'Désactiver'} ${u.name}`" @click="statusTarget = u; accountError = ''">{{ u.is_active === false ? 'Réactiver' : 'Désactiver' }}</button></div><button v-else-if="u.id === user.id" class="link" @click="changePage('profile')">Mon profil</button><span v-else class="muted">Administrateur uniquement</span>
          </div>
        </div>
      </section>
    </main>
    <div v-if="editingAccount" class="modal-bg" @click.self="!accountSaving && (editingAccount = null)"><form class="modal" role="dialog" aria-modal="true" aria-labelledby="edit-account-title" @submit.prevent="saveAccount"><div class="modal-title"><div><h2 id="edit-account-title">Modifier le compte</h2><p class="muted">{{ editingAccount.email }}</p></div><button type="button" class="close" :disabled="accountSaving" aria-label="Fermer" @click="editingAccount = null"><AppIcon name="close" /></button></div><div v-if="accountError" class="alert" role="alert">{{ accountError }}</div><fieldset class="auth-fields" :disabled="accountSaving"><label>Nom complet<input v-model="accountDraft.name" required maxlength="150"></label><label>Adresse email<input v-model="accountDraft.email" type="email" required maxlength="255"></label><label v-if="accountDraft.email !== editingAccount.email">Votre mot de passe administrateur<input v-model="accountDraft.current_password" type="password" required autocomplete="current-password"></label><p class="form-hint">Un changement d’adresse invalide les sessions et les liens de récupération de ce compte.</p><div class="modal-actions"><button type="button" class="secondary" @click="editingAccount = null">Annuler</button><button class="primary" :disabled="accountSaving">Enregistrer le compte</button></div></fieldset></form></div>
    <div v-if="statusTarget" class="modal-bg" @click.self="!accountSaving && (statusTarget = null)"><form class="modal" role="dialog" aria-modal="true" aria-labelledby="status-account-title" @submit.prevent="changeAccountStatus"><div class="modal-title"><h2 id="status-account-title">{{ statusTarget.is_active === false ? 'Réactiver ce compte ?' : 'Désactiver ce compte ?' }}</h2><button type="button" class="close" :disabled="accountSaving" aria-label="Fermer" @click="statusTarget = null"><AppIcon name="close" /></button></div><p><strong>{{ statusTarget.name }}</strong> · {{ statusTarget.email }}</p><p class="form-hint">{{ statusTarget.is_active === false ? 'L’utilisateur pourra se reconnecter et retrouver ses accès existants.' : 'L’utilisateur sera déconnecté et ses emails encore en attente seront annulés. Son historique restera conservé. Un envoi déjà commencé ne peut pas être annulé.' }}</p><div v-if="accountError" class="alert" role="alert">{{ accountError }}</div><div class="modal-actions"><button type="button" class="secondary" :disabled="accountSaving" @click="statusTarget = null">Annuler</button><button class="primary" :disabled="accountSaving">{{ statusTarget.is_active === false ? 'Confirmer la réactivation' : 'Confirmer la désactivation' }}</button></div></form></div>
    <div v-if="showingBox || showingUser" class="modal-bg" @click.self="showingBox = false; showingUser = false"><form class="modal" @submit.prevent="showingBox ? createBox() : createUser()"><div class="modal-title"><div><h2>{{ showingBox ? (editingBox ? 'Configurer la boîte mail' : 'Ajouter une boîte mail') : 'Ajouter un utilisateur' }}</h2><p class="muted">{{ showingBox ? 'Renseignez les paramètres de votre boîte Hestia.' : 'Créez un accès pour un membre de votre équipe.' }}</p></div><button type="button" class="close" aria-label="Fermer" @click="showingBox = false; showingUser = false"><AppIcon name="close" /></button></div><div v-if="error" class="alert">{{ error }}</div>
      <template v-if="showingBox"><label>Nom de la boîte<input v-model="box.name" required placeholder="Contact"></label><label>Adresse email<input v-model="box.email" type="email" :readonly="!!editingBox" required placeholder="contact@entreprise.com"></label><div class="form-grid"><label>Serveur IMAP<input v-model="box.imap_host" required placeholder="mail.entreprise.com"></label><label>Port IMAP<input v-model.number="box.imap_port" type="number" min="1" max="65535" required></label><label>Serveur SMTP<input v-model="box.smtp_host" required placeholder="mail.entreprise.com"></label><label>Port SMTP<input v-model.number="box.smtp_port" type="number" min="1" max="65535" required></label></div><label>Dossier IMAP des messages envoyés<input v-model="box.sent_folder" required placeholder="Sent"></label><label>{{ editingBox ? 'Nouveau mot de passe (laisser vide pour conserver)' : 'Mot de passe de la boîte' }}<input v-model="box.password" type="password" :required="!editingBox"></label><div v-if="!editingBox" class="checks"><strong>Donner accès à</strong><label v-for="u in users" :key="u.id"><input type="checkbox" v-model="box.user_ids" :value="u.id"> {{ u.name }}</label></div><p class="form-hint">IMAP : SSL sur le port 993. SMTP : TLS sur 587 ou SSL sur 465. Les mots de passe sont chiffrés.</p></template>
      <template v-else><label>Nom complet<input v-model="person.name" required></label><label>Adresse email<input v-model="person.email" type="email" required></label><label>Mot de passe initial<input v-model="person.password" type="password" minlength="8" required></label><label v-if="isAdmin">Rôle<select v-model="person.role"><option value="member">Membre</option><option value="admin">Administrateur</option></select></label><fieldset v-if="isAdmin && person.role === 'member'" class="permission-choices"><legend>Permissions du membre</legend><label v-for="option in permissionOptions" :key="option.value"><input type="checkbox" v-model="person.permissions" :value="option.value"><span>{{ option.label }}<small>{{ option.description }}</small></span></label></fieldset><p v-else class="form-hint">{{ isAdmin ? 'Les administrateurs disposent de tous les droits dans leur société.' : 'Le compte sera créé sans permissions ; un administrateur pourra lui attribuer ses droits.' }}</p></template>
      <div class="modal-actions"><button type="button" class="secondary" @click="showingBox = false; showingUser = false">Annuler</button><button class="primary" :disabled="saving">Enregistrer</button></div>
    </form></div>
    <div v-if="editingPermissions" class="modal-bg" @click.self="!permissionsSaving && (editingPermissions = null)"><form class="modal permissions-modal" @submit.prevent="savePermissions"><div class="modal-title"><div><h2>Permissions de l’utilisateur</h2><p class="muted">{{ editingPermissions.name }} · {{ editingPermissions.email }}</p></div><button type="button" class="close" :disabled="permissionsSaving" aria-label="Fermer les permissions" @click="editingPermissions = null"><AppIcon name="close" /></button></div><div v-if="permissionError" class="alert" role="alert">{{ permissionError }}</div><fieldset class="permission-choices" :disabled="permissionsSaving"><legend>Ce membre peut :</legend><label v-for="option in permissionOptions" :key="option.value"><input type="checkbox" v-model="permissionDraft" :value="option.value"><span>{{ option.label }}<small>{{ option.description }}</small></span></label></fieldset><p class="form-hint">Les droits de messagerie et le tableau de bord portent sur les boîtes attribuées. La gestion des boîtes concerne toute la société. Seul un administrateur peut modifier ces permissions.</p><div class="modal-actions"><button type="button" class="secondary" :disabled="permissionsSaving" @click="editingPermissions = null">Annuler</button><button class="primary" :disabled="permissionsSaving">{{ permissionsSaving ? 'Enregistrement…' : 'Enregistrer les permissions' }}</button></div></form></div>
  </div>
</template>
