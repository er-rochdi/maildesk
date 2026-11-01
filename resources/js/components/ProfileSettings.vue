<script setup>
import { ref } from 'vue';

const props = defineProps({ api: Function, user: Object });
const emit = defineEmits(['updated', 'notice']);
const profile = ref({ name: props.user.name, email: props.user.email, current_password: '', signature: props.user.signature || '' });
const password = ref({ current_password: '', password: '', password_confirmation: '' });
const busy = ref(false), error = ref('');
async function saveProfile() {
  busy.value = true; error.value = '';
  try {
    const updated = await props.api('/account/profile', { method: 'PUT', body: JSON.stringify(profile.value) });
    emit('updated', updated); profile.value.current_password = ''; emit('notice', 'Profil enregistré.');
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function savePassword() {
  busy.value = true; error.value = '';
  try {
    const result = await props.api('/account/password', { method: 'PUT', body: JSON.stringify(password.value) });
    password.value = { current_password: '', password: '', password_confirmation: '' }; emit('notice', result.message);
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
</script>
<template>
  <section class="content account-settings">
    <header class="page-header"><div><h1>Mon profil</h1><p class="muted">Vos informations et la sécurité de votre compte Maildesk.</p></div></header>
    <div v-if="error" class="alert" role="alert">{{ error }}</div>
    <div class="account-grid">
      <form class="account-card" @submit.prevent="saveProfile"><h2>Informations personnelles</h2><p class="muted">Ces informations identifient votre compte dans votre société.</p><fieldset :disabled="busy">
        <label>Nom complet<input v-model="profile.name" required maxlength="150" autocomplete="name"></label>
        <label>Adresse email<input v-model="profile.email" type="email" required maxlength="255" autocomplete="email"></label>
        <label>Signature email<textarea v-model="profile.signature" maxlength="5000" rows="4" placeholder="Votre nom et vos coordonnées"></textarea></label>
        <label v-if="profile.email !== user.email">Mot de passe actuel<input v-model="profile.current_password" type="password" required autocomplete="current-password"></label>
        <p class="form-hint">Un changement d’adresse nécessite votre mot de passe actuel et déconnecte les autres sessions.</p>
        <button class="primary" :disabled="busy">Enregistrer mon profil</button>
      </fieldset></form>
      <form class="account-card" @submit.prevent="savePassword"><h2>Mot de passe</h2><p class="muted">Choisissez un mot de passe propre à votre compte Maildesk.</p><fieldset :disabled="busy">
        <label>Mot de passe actuel<input v-model="password.current_password" type="password" required autocomplete="current-password"></label>
        <label>Nouveau mot de passe<input v-model="password.password" type="password" required minlength="8" autocomplete="new-password"></label>
        <label>Confirmer le nouveau mot de passe<input v-model="password.password_confirmation" type="password" required minlength="8" autocomplete="new-password"></label>
        <p class="form-hint">8 caractères minimum. Les autres sessions seront déconnectées après la modification.</p>
        <button class="primary" :disabled="busy">Modifier mon mot de passe</button>
      </fieldset></form>
    </div>
  </section>
</template>
