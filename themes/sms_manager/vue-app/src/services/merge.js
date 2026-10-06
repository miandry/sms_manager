import { reactive } from 'vue'
import { api } from './api.js'

/** Lots json_sms pas encore fusionnés, partagé par le menu et la page Lots. */
export const mergeState = reactive({
  pending: [],
  pendingCount: 0,
  errors: [],
  canMerge: false,
  lastRun: null,
  loaded: false,
})

function apply(data) {
  mergeState.pending = data.pending || []
  mergeState.pendingCount = Number(data.pending_count || 0)
  mergeState.errors = data.errors || []
  mergeState.canMerge = !!data.can_merge
  mergeState.lastRun = data.last_run || null
  mergeState.loaded = true
}

export async function fetchMergeStatus() {
  try {
    const { data } = await api.get('/sms-merge/api/status')
    apply(data)
  } catch {
    // Module absent ou session expirée : pas de notification.
  }
  return mergeState
}

/** Lance la fusion json_sms → sms côté serveur. */
export async function runMerge(force = false) {
  const { data } = await api.post('/sms-merge/api/run', { force })
  apply(data)
  return data
}

export function isPending(nid) {
  return mergeState.pending.some((p) => String(p.id) === String(nid))
}

export function lotError(nid) {
  return mergeState.errors.find((e) => String(e.id) === String(nid))?.error || ''
}
