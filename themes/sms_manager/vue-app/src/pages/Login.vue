<template>
  <div class="login-page">
    <form class="card login-card" @submit.prevent="submit">
      <div class="brand center">
        <span class="brand-logo">✉</span>
        <strong>SMS Manager</strong>
      </div>
      <label>
        Nom d'utilisateur
        <input id="username" v-model="name" autocomplete="username" required />
      </label>
      <label>
        Mot de passe
        <input id="password" v-model="pass" type="password" autocomplete="current-password" required />
      </label>
      <p v-if="error" class="error">{{ error }}</p>
      <button type="submit" class="btn primary" :disabled="loading">{{ loading ? 'Connexion…' : 'Se connecter' }}</button>
    </form>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { session } from '../services/session.js'
import { errorMessage, login } from '../services/api.js'

const route = useRoute()
const router = useRouter()
const name = ref('')
const pass = ref('')
const loading = ref(false)
const error = ref('')

async function submit() {
  loading.value = true
  error.value = ''
  try {
    session.user = await login(name.value, pass.value)
    router.replace(String(route.query.next || '/'))
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
  }
}
</script>
