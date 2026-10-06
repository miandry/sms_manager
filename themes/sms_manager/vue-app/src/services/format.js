export const TYPES = {
  recu: 'Reçu',
  envoye: 'Envoyé',
  retrait: 'Retrait',
  depot: 'Dépôt',
  paiement: 'Paiement',
  autre: 'Autre',
}

export const PROVIDERS = {
  mvola: 'MVola',
  orange_money: 'Orange Money',
  airtel_money: 'Airtel Money',
  autre: 'Autre',
}

/** Entrées d'argent (+) ; les autres types mouvementés sont des sorties (−). */
export const CREDIT_TYPES = ['recu', 'depot']
export const DEBIT_TYPES = ['envoye', 'retrait', 'paiement']

/** Valeur simple d'un champ api_solutions (scalaire, tableau ou {value}). */
export function scalar(row, key) {
  let v = row?.[key]
  if (Array.isArray(v)) v = v[0]
  if (v && typeof v === 'object') v = v.value ?? ''
  return v == null ? '' : String(v)
}

export function money(n) {
  const value = Number(n) || 0
  return `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(value)} Ar`
}

export function signedMoney(type, amount) {
  if (CREDIT_TYPES.includes(type)) return { text: `+${money(amount)}`, tone: 'credit' }
  if (DEBIT_TYPES.includes(type)) return { text: `−${money(amount)}`, tone: 'debit' }
  return { text: Number(amount) ? money(amount) : '—', tone: 'neutral' }
}

/** Date Drupal stockée en UTC (AAAA-MM-JJTHH:MM:SS) → affichage local. */
export function dateTime(utc) {
  if (!utc) return ''
  const d = new Date(`${utc.replace(' ', 'T')}Z`)
  if (Number.isNaN(d.getTime())) return utc
  return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(d)
}

/** Jour local (AAAA-MM-JJ) → borne UTC pour filtrer field_date_sms. */
export function localDayToUtc(day, endOfDay = false) {
  if (!day) return ''
  const d = new Date(`${day}T${endOfDay ? '23:59:59' : '00:00:00'}`)
  return d.toISOString().slice(0, 19)
}

export function initials(text) {
  return (text || '?')
    .replace(/[^\p{L}\s]/gu, ' ')
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase() ?? '')
    .join('') || '?'
}
