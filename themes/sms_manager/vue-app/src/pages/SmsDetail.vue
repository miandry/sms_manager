<template>
  <div class="detail">
    <router-link to="/sms" class="btn">‹ Retour</router-link>
    <p v-if="loading" class="muted">Chargement…</p>
    <p v-else-if="error" class="error">{{ error }}</p>
    <template v-else-if="row">
      <div class="card detail-head">
        <div>
          <span class="badge" :class="`t-${type}`">{{ TYPES[type] || type }}</span>
          <h2>{{ row.title }}</h2>
          <span class="muted">{{ dateTime(scalar(row, 'field_date_sms')) }}</span>
        </div>
        <span class="amount big" :class="amount.tone">{{ amount.text }}</span>
      </div>

      <div class="card">
        <dl class="fields">
          <template v-for="f in FIELDS" :key="f.key">
            <dt>{{ f.label }}</dt>
            <dd>{{ f.format ? f.format(scalar(row, f.key)) : scalar(row, f.key) || '—' }}</dd>
          </template>
        </dl>
      </div>

      <div class="card">
        <h3>Message</h3>
        <p class="message">{{ scalar(row, 'field_message') || '—' }}</p>
      </div>
    </template>
    <p v-else class="muted">SMS introuvable.</p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { SMS_FIELDS, errorMessage, getNode } from '../services/api.js'
import { PROVIDERS, TYPES, dateTime, money, scalar, signedMoney } from '../services/format.js'

const FIELDS = [
  { key: 'field_expediteur', label: 'Opérateur', format: (v) => PROVIDERS[v] || v || '—' },
  { key: 'field_reference', label: 'Référence' },
  { key: 'field_contact', label: 'Contact' },
  { key: 'field_telephone', label: 'Téléphone' },
  { key: 'field_montant', label: 'Montant', format: (v) => (Number(v) ? money(v) : '—') },
  { key: 'field_frais', label: 'Frais', format: (v) => (Number(v) ? money(v) : '—') },
  { key: 'field_solde', label: 'Solde après', format: (v) => (Number(v) ? money(v) : '—') },
]

const route = useRoute()
const row = ref(null)
const loading = ref(true)
const error = ref('')

const type = computed(() => scalar(row.value, 'field_type') || 'autre')
const amount = computed(() => signedMoney(type.value, scalar(row.value, 'field_montant')))

onMounted(async () => {
  try {
    row.value = await getNode('sms', route.params.id, SMS_FIELDS)
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
  }
})
</script>
