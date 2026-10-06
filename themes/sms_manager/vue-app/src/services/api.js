import axios from 'axios'

const TOKEN_KEY = 'sms_manager_token'

/**
 * Même origine que Drupal : le cookie `auth_token` posé par
 * /api_solutions/user/login suffit ; le Bearer reste en secours.
 */
export const api = axios.create({
  baseURL: window.drupalSettings?.path?.baseUrl || '/',
  withCredentials: true,
  headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token && !config.url.includes('/user/login')) config.headers.Authorization = `Bearer ${token}`
  return config
})

export const SMS_FIELDS = [
  'nid', 'title', 'created',
  'field_type', 'field_expediteur', 'field_montant', 'field_frais', 'field_solde',
  'field_reference', 'field_contact', 'field_telephone', 'field_date_sms', 'field_message',
]

/**
 * /api_solutions/api/v2/{entity}/{bundle} : offset = taille de page,
 * pager = index de page (0-based) ou "all", filters[champ][val|op], sort[val|op].
 */
export function listParams({ page = 1, limit = 20, sort = 'field_date_sms', order = 'DESC', filters = {}, fields = [] } = {}) {
  const p = new URLSearchParams()
  if (limit === 'all') p.set('pager', 'all')
  else {
    p.set('offset', String(limit))
    if (page > 1) p.set('pager', String(page - 1))
  }
  if (sort) {
    p.set('sort[val]', sort)
    p.set('sort[op]', order)
  }
  for (const [field, f] of Object.entries(filters)) {
    if (f == null || f.val === '' || f.val == null) continue
    if (Array.isArray(f.val)) {
      f.val.forEach((v) => p.append(`filters[${field}][val][]`, String(v)))
      p.set(`filters[${field}][op]`, f.op || 'IN')
    } else {
      p.set(`filters[${field}][val]`, String(f.val))
      if (f.op) p.set(`filters[${field}][op]`, f.op)
    }
  }
  fields.forEach((f) => p.append('fields[]', f))
  return p
}

export async function listNodes(bundle, options = {}) {
  const { data } = await api.get(`/api_solutions/api/v2/node/${bundle}?${listParams(options)}`)
  return { rows: Array.isArray(data.rows) ? data.rows : [], total: Number(data.total || 0) }
}

export async function getNode(bundle, id, fields = []) {
  const { rows } = await listNodes(bundle, { limit: 1, filters: { nid: { val: id } }, fields })
  return rows[0] || null
}

// ── Authentification ─────────────────────────────────────────────────────

export async function login(name, pass) {
  const { data } = await api.post('/api_solutions/user/login', { name: name.trim(), pass })
  if (!data.status || !data.token) throw new Error(data.error || data.message || 'Identifiants invalides')
  localStorage.setItem(TOKEN_KEY, data.token)
  return { id: String(data.id ?? ''), name: data.name ?? name, roles: data.roles ?? [] }
}

export async function checkAuth() {
  try {
    const { data } = await api.get('/api_solutions/user/check-auth')
    return data.authenticated && data.user ? { id: String(data.user.id), name: data.user.name, roles: data.user.roles ?? [] } : null
  } catch {
    return null
  }
}

export async function logout() {
  try {
    await api.post('/api_solutions/user/logout', {})
  } finally {
    localStorage.removeItem(TOKEN_KEY)
  }
}

export function errorMessage(err) {
  return err?.response?.data?.message || err?.response?.data?.error || err?.message || 'Une erreur est survenue.'
}
