<template>
  <router-link :to="`/sms/${row.nid}`" class="sms-row">
    <span class="avatar" :class="`p-${provider}`">{{ initials(providerLabel) }}</span>
    <span class="sms-main">
      <span class="sms-title">{{ row.title }}</span>
      <span class="sms-sub">{{ dateTime(scalar(row, 'field_date_sms')) }}<template v-if="contact"> · {{ contact }}</template></span>
    </span>
    <span class="sms-side">
      <span class="amount" :class="amount.tone">{{ amount.text }}</span>
      <span v-if="phone" class="sms-phone">{{ phone }}</span>
    </span>
  </router-link>
</template>

<script setup>
import { computed } from 'vue'
import { PROVIDERS, dateTime, initials, scalar, signedMoney } from '../services/format.js'

const props = defineProps({ row: { type: Object, required: true } })

const type = computed(() => scalar(props.row, 'field_type') || 'autre')
const provider = computed(() => scalar(props.row, 'field_expediteur') || 'autre')
const providerLabel = computed(() => PROVIDERS[provider.value] || provider.value)
const contact = computed(() => scalar(props.row, 'field_contact'))
const phone = computed(() => {
  const digits = String(scalar(props.row, 'field_telephone') || '').replace(/\s+/g, '')
  return /^0\d{9}$/.test(digits)
    ? digits.replace(/^(\d{3})(\d{2})(\d{3})(\d{2})$/, '$1 $2 $3 $4')
    : digits
})
const amount = computed(() => signedMoney(type.value, scalar(props.row, 'field_montant')))
</script>
