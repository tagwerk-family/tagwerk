import { isLanguage, startLanguage } from '../utils/language'

/*
 * Language and texts before the first text. The app is client-only, so
 * nothing is on screen until it mounts — and it mounts after this plugin.
 * The server answers for a device, an account and nobody alike; without a
 * network the device's last answer holds, so a kiosk after a power cut
 * still starts in its language and not in the fallback.
 */
export default defineNuxtPlugin({
  name: 'fahoba:language',
  dependsOn: ['i18n:plugin'],
  async setup() {
    let answer: unknown = null

    try {
      answer = await $fetch<unknown>('/api/language', { baseURL: '/', credentials: 'same-origin' })
    }
    catch {
      // Offline or the server is down: the kept answer or the browser decides.
    }

    await useLanguage().apply(startLanguage(isLanguage(answer) ? answer : null, storedLanguage(), navigator.languages))
  },
})
