import { createApp } from 'vue'
import { createRouter, createWebHashHistory } from 'vue-router'

import App from './App.vue'
import Login from './pages/Login.vue'
import Dashboard from './pages/Dashboard.vue'
import SmsList from './pages/SmsList.vue'
import SmsDetail from './pages/SmsDetail.vue'
import Lots from './pages/Lots.vue'
import { checkAuth } from './services/api.js'
import { session } from './services/session.js'
import './style.css'

const routes = [
  { path: '/connexion', component: Login, meta: { public: true, title: 'Connexion' } },
  { path: '/', component: Dashboard, meta: { title: 'Tableau de bord' } },
  { path: '/sms', component: SmsList, meta: { title: 'SMS' } },
  { path: '/sms/:id', component: SmsDetail, meta: { title: 'Détail du SMS' } },
  { path: '/lots', component: Lots, meta: { title: 'Lots JSON' } },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

// Hash history : les URL Drupal (/node/1, /user/…) restent intactes.
const router = createRouter({ history: createWebHashHistory(), routes })

router.beforeEach(async (to) => {
  if (!session.checked) {
    session.user = await checkAuth()
    session.checked = true
  }
  if (!to.meta.public && !session.user) return { path: '/connexion', query: { next: to.fullPath } }
  if (to.path === '/connexion' && session.user) return '/'
})

router.afterEach((to) => {
  const site = window.drupalSettings?.smsManager?.siteName || 'SMS Manager'
  document.title = `${to.meta.title || 'SMS'} · ${site}`
})

document.addEventListener('DOMContentLoaded', () => {
  if (document.querySelector('#vue-app')) {
    createApp(App).use(router).mount('#vue-app')
  }
})
