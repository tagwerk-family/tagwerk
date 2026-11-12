import { intlLocale } from '../utils/intlLocale'
import { bundled, isBundled, isLanguage, keptTexts, type StoredLanguage } from '../utils/language'

// A convenience for starting without a network, not state: the server decides.
const STORED = 'fahoba-language'

let latest = 0

export function storedLanguage(): StoredLanguage | null {
  try {
    const value: unknown = JSON.parse(localStorage.getItem(STORED) ?? 'null')

    return isLanguage(value) ? { ...value, texts: (value as { texts?: Record<string, unknown> | null }).texts ?? null } : null
  }
  catch {
    return null
  }
}

function store(language: StoredLanguage): void {
  try {
    localStorage.setItem(STORED, JSON.stringify(language))
  }
  catch {
    // Blocked or full storage: then only the server decides.
  }
}

/**
 * Follows the language the server names.
 *
 * Wall and phone only ever apply what came back from the API — the tablet's
 * or account's own choice, else the household's. A pack's texts are loaded
 * before the switch, so no frame shows two languages; if they cannot be
 * loaded the language stays as it was.
 */
export function useLanguage() {
  const i18n = useNuxtApp().$i18n

  async function apply(value: unknown): Promise<void> {
    // A bare code is what older answers carry (`locale`): bundled ones only.
    const language = isLanguage(value) ? value : typeof value === 'string' && isBundled(value) ? bundled(value) : null

    if (language === null) {
      return
    }

    const step = ++latest
    const texts = language.messages === null ? null : await textsOf(language.messages)

    if (step !== latest || (language.messages !== null && texts === null)) {
      return
    }

    if (texts === null) {
      await i18n.setLocale(bundled(language.code).code)
    }
    else {
      // Missing texts fall back to English, so it must be there first.
      await i18n.loadLocaleMessages('en')
      i18n.setLocaleMessage(language.code, texts)
      // The module types the locale as the built-in codes and its
      // `setLocale()` ignores any other; a pack's code is known at runtime only.
      i18n.locale.value = language.code as typeof i18n.locale.value
    }

    intlLocale.value = language.intl
    store({ ...language, texts })
  }

  async function textsOf(messages: string): Promise<Record<string, unknown> | null> {
    const kept = keptTexts(storedLanguage(), messages)

    if (kept !== null) {
      return kept
    }

    try {
      return await $fetch<Record<string, unknown>>(messages, { baseURL: '/' })
    }
    catch {
      return null
    }
  }

  return { locale: i18n.locale, apply }
}
