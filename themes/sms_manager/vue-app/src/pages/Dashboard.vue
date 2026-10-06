<template>
  <div>
    <p v-if="loading" class="muted">Chargement…</p>
    <p v-else-if="error" class="error">{{ error }}</p>
    <template v-else>
      <div class="stats">
        <div class="card stat">
          <small>SMS</small>
          <strong>{{ total }}</strong>
        </div>
        <div class="card stat">
          <small>Reçus / dépôts</small>
          <strong class="credit">+{{ money(credit) }}</strong>
        </div>
        <div class="card stat">
          <small>Envoyés / retraits / paiements</small>
          <strong class="debit">−{{ money(debit) }}</strong>
        </div>
        <div class="card stat">
          <small>Frais</small>
          <strong>{{ money(fees) }}</strong>
        </div>
      </div>

      <h2>Dernier solde connu</h2>
      <div class="stats">
        <div v-for="b in balances" :key="b.provider" class="card stat">
          <small>{{ PROVIDERS[b.provider] || b.provider }}</small>
          <strong>{{ money(b.solde) }}</strong>
          <span class="muted">{{ dateTime(b.date) }}</span>
        </div>
        <p v-if="!balances.length" class="muted">Aucun solde trouvé dans les SMS.</p>
      </div>

      <div class="section-head">
        <h2>Derniers SMS</h2>
        <router-link to="/sms" class="btn">Tout voir</router-link>
      </div>
      <div class="card list">
        <SmsRow v-for="row in recent" :key="row.nid" :row="row" />
        <p v-if="!recent.length" class="muted pad">Aucun SMS. Lancez la fusion des lots JSON.</p>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import SmsRow from '../components/SmsRow.vue'
import { SMS_FIELDS, errorMessage, listNodes } from '../services/api.js'
import { CREDIT_TYPES, DEBIT_TYPES, PROVIDERS, dateTime, money, scalar } from '../services/format.js'

const rows = ref([])
const total = ref(0)
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  try {
    const res = await listNodes('sms', { limit: 'all', fields: SMS_FIELDS.filter((f) => f !== 'field_message') })
    rows.value = res.rows
    total.value = res.total
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
  }
})

const sum = (types, key = 'field_montant') =>
  rows.value.filter((r) => types.includes(scalar(r, 'field_type'))).reduce((s, r) => s + (Number(scalar(r, key)) || 0), 0)

const credit = computed(() => sum(CREDIT_TYPES))
const debit = computed(() => sum(DEBIT_TYPES))
const fees = computed(() => rows.value.reduce((s, r) => s + (Number(scalar(r, 'field_frais')) || 0), 0))
const recent = computed(() => rows.value.slice(0, 8))

/** Rows are sorted newest first: the first SMS with a balance wins per provider. */
const balances = computed(() => {
  const seen = new Map()
  for (const r of rows.value) {
    const provider = scalar(r, 'field_expediteur') || 'autre'
    const solde = Number(scalar(r, 'field_solde'))
    if (solde > 0 && !seen.has(provider)) seen.set(provider, { provider, solde, date: scalar(r, 'field_date_sms') })
  }
  return [...seen.values()]
})
</script>
