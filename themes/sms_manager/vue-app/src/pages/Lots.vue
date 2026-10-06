<template>
  <div>
    <div class="section-head">
      <span class="muted">{{ rows.length }} lot(s) JSON reçus</span>
      <a v-if="mergeUrl" :href="mergeUrl" class="btn primary">Fusionner les SMS</a>
    </div>
    <p v-if="loading" class="muted">Chargement…</p>
    <p v-else-if="error" class="error">{{ error }}</p>
    <div v-else class="card list">
      <div v-for="lot in rows" :key="lot.nid" class="lot-row">
        <span class="avatar p-autre">{{ details(lot).count ?? '?' }}</span>
        <span class="sms-main">
          <span class="sms-title">{{ lot.title }}</span>
          <span class="sms-sub">{{ (details(lot).senders || []).join(', ') || '—' }}</span>
        </span>
        <span class="muted">{{ created(lot) }}</span>
      </div>
      <p v-if="!rows.length" class="muted pad">Aucun lot JSON.</p>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { errorMessage, listNodes } from '../services/api.js'
import { scalar } from '../services/format.js'

const mergeUrl = window.drupalSettings?.smsManager?.mergeUrl || ''
const rows = ref([])
const loading = ref(true)
const error = ref('')

function details(lot) {
  try {
    return JSON.parse(scalar(lot, 'field_details') || '{}')
  } catch {
    return {}
  }
}

function created(lot) {
  const ts = Number(scalar(lot, 'created'))
  return ts ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(ts * 1000)) : ''
}

onMounted(async () => {
  try {
    rows.value = (await listNodes('json_sms', { limit: 'all', sort: 'nid', fields: ['nid', 'title', 'created', 'field_details'] })).rows
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
  }
})
</script>
