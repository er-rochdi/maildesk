<script setup>
import { ref, watch } from 'vue';
const props = defineProps({ api: Function, messageId: Number });
const team = ref(null), note = ref(''), error = ref(''), busy = ref(false);
let version = 0;
watch(() => props.messageId, async id => {
  const current = ++version; team.value = null; error.value = ''; note.value = '';
  try { const data = await props.api(`/workspace/messages/${id}/team`); if (current === version) team.value = data; }
  catch (e) { if (current === version) error.value = e.message; }
}, { immediate: true });
async function save() {
  const id = props.messageId, current = version; busy.value = true; error.value = '';
  try { const data = await props.api(`/workspace/messages/${id}/team`, { method: 'PUT', body: JSON.stringify({ status: team.value.status, assigned_user_id: team.value.assigned_user_id || null }) }); if (version === current) team.value = data; }
  catch (e) { if (version === current) error.value = e.message; } finally { if (version === current) busy.value = false; }
}
async function addNote() {
  const id = props.messageId, current = version; busy.value = true; error.value = '';
  try { const data = await props.api(`/workspace/messages/${id}/notes`, { method: 'POST', body: JSON.stringify({ body: note.value }) }); if (version === current) { team.value.notes.push(data); note.value = ''; } }
  catch (e) { if (version === current) error.value = e.message; } finally { if (version === current) busy.value = false; }
}
</script>
<template><section class="team-work"><h3>Suivi d’équipe</h3><p class="muted">Attribution, traitement et notes internes de cette boîte.</p>
  <div v-if="error" class="alert" role="alert">{{ error }}</div>
  <template v-if="team"><form @submit.prevent="save"><div class="form-grid"><label>Statut<select v-model="team.status"><option value="open">À traiter</option><option value="in_progress">En cours</option><option value="done">Traité</option></select></label><label>Attribué à<select v-model="team.assigned_user_id"><option :value="null">Personne</option><option v-for="member in team.members" :key="member.id" :value="member.id">{{ member.name }}</option></select></label></div><button class="secondary" :disabled="busy">Enregistrer le suivi</button></form>
  <h3>Notes internes</h3><p v-if="!team.notes.length" class="muted">Aucune note pour le moment.</p><div v-for="item in team.notes" :key="item.id" class="team-note"><strong>{{ item.author?.name || 'Utilisateur supprimé' }}</strong><small>{{ new Date(item.created_at).toLocaleString('fr-FR') }}</small><p>{{ item.body }}</p></div><form @submit.prevent="addNote"><label>Ajouter une note<textarea v-model="note" required maxlength="10000" rows="3"></textarea></label><button class="secondary" :disabled="busy">Ajouter la note</button></form></template>
</section></template>
