<template>
  <div>
    <div class="toolbar">
      <div class="search">
        <input v-model="form.search" type="search" placeholder="Rechercher (réf., type…)" @keyup.enter="apply" />
      </div>
      <button type="button" class="btn filter-toggle" :class="{ on: showFilters || activeCount }" @click="showFilters = !showFilters">
        <span>⚲ Filtres</span>
        <span v-if="activeCount" class="count">{{ activeCount }}</span>
        <span class="chev" :class="{ open: showFilters }">▾</span>
      </button>
    </div>

    <form v-show="showFilters" class="card filters" @submit.prevent="apply">
      <label>
        Type
        <select v-model="form.type">
          <option value="">Tous</option>
          <option v-for="(label, key) in TYPES" :key="key" :value="key">{{ label }}</option>
        </select>
      </label>
      <label>
        Opérateur
        <select v-model="form.provider">
          <option value="">Tous</option>
          <option v-for="(label, key) in PROVIDERS" :key="key" :value="key">{{ label }}</option>
        </select>
      </label>
      <label>
        Du
        <input v-model="form.from" type="date" />
      </label>
      <label>
        Au
        <input v-model="form.to" type="date" />
      </label>
      <div class="filter-actions">
        <button type="submit" class="btn primary">Filtrer</button>
        <button type="button" class="btn" @click="reset">Réinit.</button>
      </div>
    </form>

    <div class="list-meta">
      <span>{{ total }} SMS</span>
      <span>Page {{ page }} / {{ pages }}</span>
    </div>

    <p v-if="error" class="error">{{ error }}</p>
    <div class="card list" :class="{ loading }">
      <SmsRow v-for="row in rows" :key="row.nid" :row="row" />
      <p v-if="!loading && !rows.length" class="muted pad">Aucun SMS ne correspond aux filtres.</p>
    </div>

    <div v-if="pages > 1" class="pager">
      <button type="button" class="btn" :disabled="page <= 1" @click="go(page - 1)">‹ Précédent</button>
      <button type="button" class="btn" :disabled="page >= pages" @click="go(page + 1)">Suivant ›</button>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import SmsRow from '../components/SmsRow.vue'
import { SMS_FIELDS, errorMessage, listNodes } from '../services/api.js'
import { PROVIDERS, TYPES, localDayToUtc } from '../services/format.js'

const LIMIT = 20
const route = useRoute()
const router = useRouter()

const q = route.query
const form = reactive({
  search: String(q.search || ''),
  type: String(q.type || ''),
  provider: String(q.provider || ''),
  from: String(q.from || ''),
  to: String(q.to || ''),
})
const page = ref(Number(q.page || 1))
const rows = ref([])
const total = ref(0)
const loading = ref(false)
const error = ref('')
const pages = computed(() => Math.max(1, Math.ceil(total.value / LIMIT)))
const activeCount = computed(() => ['type', 'provider', 'from', 'to'].filter((k) => form[k]).length)
const showFilters = ref(false)

function filters() {
  const f = {
    status: { val: 1 },
    field_type: { val: form.type },
    field_expediteur: { val: form.provider },
  }
  if (form.search) f.title = { val: form.search, op: 'CONTAINS' }
  const from = localDayToUtc(form.from)
  const to = localDayToUtc(form.to, true)
  if (from && to) f.field_date_sms = { val: from <= to ? [from, to] : [to, from], op: 'BETWEEN' }
  else if (from) f.field_date_sms = { val: from, op: '>=' }
  else if (to) f.field_date_sms = { val: to, op: '<=' }
  return f
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await listNodes('sms', { page: page.value, limit: LIMIT, sort: 'nid', order: 'DESC', filters: filters(), fields: SMS_FIELDS.filter((f) => f !== 'field_message') })
    rows.value = res.rows
    total.value = res.total
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
  }
}

function push() {
  const query = { ...Object.fromEntries(Object.entries(form).filter(([, v]) => v)) }
  if (page.value > 1) query.page = String(page.value)
  router.replace({ query })
}

function apply() {
  page.value = 1
  showFilters.value = false
  push()
}

function reset() {
  Object.assign(form, { search: '', type: '', provider: '', from: '', to: '' })
  apply()
}

function go(n) {
  page.value = n
  push()
}

onMounted(load)
</script>
