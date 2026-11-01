<script setup>
defineProps({ name: { type: String, required: true } });
const paths = {
  dashboard: ['M3 3h7v7H3z', 'M14 3h7v7h-7z', 'M3 14h7v7H3z', 'M14 14h7v7h-7z'],
  refresh: ['M20 7a8 8 0 0 0-13-3L3 8', 'M3 3v5h5', 'M4 17a8 8 0 0 0 13 3l4-4', 'M16 16h5v5'],
  alert: ['M12 3 2 21h20Z', 'M12 9v5', 'M12 18h.01'],
  mail: ['M4 5h16v14H4z', 'm4 6 8 6 8-6'],
  inbox: ['m4 4-3 10v6h22v-6L20 4Z', 'M1 14h6l2 3h6l2-3h6'],
  send: ['m22 2-7 20-4-9-9-4Z', 'M22 2 11 13'],
  users: ['M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2', 'M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8', 'M20 21v-2a4 4 0 0 0-3-3.87', 'M16 3.13a4 4 0 0 1 0 7.75'],
  logout: ['M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4', 'm16 17 5-5-5-5', 'M21 12H9'],
  plus: ['M12 5v14', 'M5 12h14'],
  arrow: ['M5 12h14', 'm12 5 7 7-7 7'],
  close: ['m6 6 12 12', 'M6 18 18 6'],
};
</script>
<template><svg class="app-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path v-for="(path, i) in paths[name] || paths.mail" :key="i" :d="path" /></svg></template>
