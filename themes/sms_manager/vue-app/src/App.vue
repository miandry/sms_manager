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
        <router-link to="/lots" active-class="active">Lots JSON</router-link>
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
      </header>
      <section class="content">
        <router-view :key="$route.fullPath" />
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { session } from './services/session.js'
import { logout } from './services/api.js'

const router = useRouter()
const menuOpen = ref(false)
const siteName = window.drupalSettings?.smsManager?.siteName || ''

async function signOut() {
  await logout()
  session.user = null
  router.push('/connexion')
}
</script>
