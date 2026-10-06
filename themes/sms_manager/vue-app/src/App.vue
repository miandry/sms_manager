<template>
  <router-view v-if="$route.meta.public" />
  <div v-else class="layout">
    <aside class="sidebar" :class="{ open: menuOpen }">
      <div class="brand">
        <span class="brand-logo">✉</span>
        <div>
          <strong>SMS Manager</strong>
          <small>{{ siteName }}</small>
        </div>
      </div>
      <nav @click="menuOpen = false">
        <router-link to="/" exact-active-class="active">Tableau de bord</router-link>
        <router-link to="/sms" active-class="active">SMS</router-link>
        <router-link to="/lots" active-class="active" class="nav-lots">
          Lots JSON
          <span v-if="mergeState.pendingCount" class="nav-badge" :title="`${mergeState.pendingCount} lot(s) non fusionné(s)`">{{ mergeState.pendingCount }}</span>
        </router-link>
      </nav>
      <div class="user">
        <span>{{ session.user?.name }}</span>
        <button type="button" class="link" @click="signOut">Se déconnecter</button>
      </div>
    </aside>
    <div v-if="menuOpen" class="backdrop" @click="menuOpen = false"></div>

    <div class="main">
      <header class="topbar">
        <button type="button" class="burger" aria-label="Menu" @click="menuOpen = !menuOpen">☰</button>
        <h1>{{ $route.meta.title }}</h1>
        <router-link
          v-if="mergeState.pendingCount && $route.path !== '/lots'"
          to="/lots"
          class="pending-pill"
          data-testid="pending-pill"
        >
          {{ mergeState.pendingCount }} lot(s) non fusionné(s)
        </router-link>
      </header>
      <section class="content">
        <router-view :key="$route.fullPath" />
      </section>
    </div>
  </div>
</template>

<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { session } from './services/session.js'
import { logout } from './services/api.js'
import { fetchMergeStatus, mergeState } from './services/merge.js'

const router = useRouter()
const menuOpen = ref(false)
const siteName = window.drupalSettings?.smsManager?.siteName || ''

// New lots arrive from the phone at any time: re-check every minute.
let timer = null
watch(
  () => session.user,
  (user) => {
    clearInterval(timer)
    timer = null
    if (!user) return
    fetchMergeStatus()
    timer = setInterval(fetchMergeStatus, 60000)
  },
  { immediate: true },
)
onBeforeUnmount(() => clearInterval(timer))

async function signOut() {
  await logout()
  session.user = null
  router.push('/connexion')
}
</script>
