import { reactive } from 'vue'

/** Utilisateur connecté (null = anonyme), vérifié une fois au démarrage. */
export const session = reactive({ user: null, checked: false })
