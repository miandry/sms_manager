<template>
  <div>
    <div class="section-head">
      <span class="muted">{{ rows.length }} lot(s) JSON reçus</span>
      <button
        v-if="mergeState.canMerge"
        type="button"
        class="btn primary"
        data-testid="merge-run"
        :disabled="merging"
        @click="merge"
      >
        {{ merging ? 'Fusion en cours…' : 'Fusionner les SMS' }}
      </button>
    </div>

    <div v-if="result" class="notice" :class="result.tone" role="status" data-testid="merge-result">
      <span>{{ result.text }}</span>
      <button type="button" class="notice-close" aria-label="Fermer" @click="result = null">×</button>
    </div>
    <div v-if="mergeState.pendingCount" class="notice warn" role="alert" data-testid="merge-pending">
      <span>
        <strong>{{ mergeState.pendingCount }} lot(s) non fusionné(s)</strong>
        <template v-if="mergeState.canMerge"> : cliquez sur « Fusionner les SMS » pour créer les SMS.</template>
        <template v-else> : en attente de fusion par un administrateur.</template>
      </span>
    </div>

    <p v-if="loading" class="muted">Chargement…</p>
    <p v-else-if="error" class="error">{{ error }}</p>
    <div v-else class="card list">
      <div v-for="lot in rows" :key="lot.nid" class="lot-row" :class="{ pending: isPending(lot.nid) }">
        <span class="avatar p-autre">{{ details(lot).count ?? '?' }}</span>
        <span class="sms-main">
          <span class="sms-title">{{ lot.title }}</span>
          <span class="sms-sub">{{ (details(lot).senders || []).join(', ') || '—' }}</span>
        </span>
        <span class="sms-side">
          <span class="muted">{{ created(lot) }}</span>
          <span v-if="lotError(lot.nid)" class="tag err" :title="lotError(lot.nid)">Erreur</span>
          <span v-else-if="isPending(lot.nid)" class="tag warn">Non fusionné</span>
          <span v-else-if="mergeState.loaded" class="tag ok">Fusionné</span>
        </span>
      </div>
      <p v-if="!rows.length" class="muted pad">Aucun lot JSON.</p>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { errorMessage, listNodes } from '../services/api.js'
import { scalar } from '../services/format.js'
import { fetchMergeStatus, isPending, lotError, mergeState, runMerge } from '../services/merge.js'

const rows = ref([])
const loading = ref(true)
const error = ref('')
const merging = ref(false)
const result = ref(null)

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

async function merge() {
  merging.value = true
  result.value = null
  try {
    const data = await runMerge()
    const r = data.result || {}
    result.value = { tone: r.errors ? 'warn' : 'ok', text: data.message }
  } catch (err) {
    result.value = { tone: 'err', text: errorMessage(err) }
  } finally {
    merging.value = false
  }
}

onMounted(async () => {
  fetchMergeStatus()
  try {
    rows.value = (await listNodes('json_sms', { limit: 'all', sort: 'nid', fields: ['nid', 'title', 'created', 'field_details'] })).rows
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
  }
})
</script>
