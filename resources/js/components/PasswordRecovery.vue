<script setup>
import { ref } from 'vue';

const props = defineProps({ api: Function, token: { type: String, default: '' }, email: { type: String, default: '' } });
const emit = defineEmits(['back']);
const form = ref({ email: props.email, token: props.token, password: '', password_confirmation: '' });
const busy = ref(false), error = ref(''), notice = ref(''), completed = ref(false);
async function submit() {
  busy.value = true; error.value = ''; notice.value = '';
  try {
    const result = await props.api('/auth/' + (props.token ? 'reset-password' : 'forgot-password'), { method: 'POST', body: JSON.stringify(form.value) });
    notice.value = result.message;
    if (props.token) { completed.value = true; form.value.password = ''; form.value.password_confirmation = ''; }
  } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
</script>
<template>
  <form class="auth-form" @submit.prevent="submit"><p class="overline">SÉCURITÉ DU COMPTE</p><h2>{{ token ? 'Nouveau mot de passe' : 'Mot de passe oublié ?' }}</h2><p class="muted">{{ token ? 'Définissez un nouveau mot de passe pour retrouver votre espace.' : 'Indiquez votre adresse de connexion pour recevoir un lien temporaire.' }}</p>
    <div v-if="error" class="alert" role="alert">{{ error }}</div><div v-if="notice" class="notice" role="status">{{ notice }}</div>
    <fieldset v-if="!completed" :disabled="busy" class="auth-fields"><label>Adresse email<input v-model="form.email" type="email" required autocomplete="email"></label>
      <template v-if="token"><label>Nouveau mot de passe<input v-model="form.password" type="password" required minlength="8" autocomplete="new-password"></label><label>Confirmer le nouveau mot de passe<input v-model="form.password_confirmation" type="password" required minlength="8" autocomplete="new-password"></label></template>
      <button class="primary wide" :disabled="busy">{{ busy ? 'En cours…' : (token ? 'Réinitialiser mon mot de passe' : 'Envoyer le lien') }}</button>
    </fieldset><p class="switch"><button type="button" class="link" :disabled="busy" @click="emit('back')">Retour à la connexion</button></p>
  </form>
</template>
