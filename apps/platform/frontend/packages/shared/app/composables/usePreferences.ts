export interface Preferences {
  start_area: string
  calendar_view: string
  task_view: string
  /** Done tasks in the task view: `hidden` or `shown`. */
  task_done: string
  school_band: string
  /** Top of the wall's menu: `logo` or `weather`. */
  menu_top: string
  /** Narrow menu: `drawer` behind the burger, or `bottom` as a row of icons. */
  phone_menu: string
  /** Emoji instead of line icons in the menu. */
  colored_icons: string
  /** Task and reward icons in colour (`on`) or grey (`off`). */
  colored_emoji: string
  /** Every star filled (`on`) or an outline, in gold (`on`) or the text's colour. */
  star_filled: string
  star_color: string
  /** 'off' stills every animation on this device. */
  animations: string
  /** `glass`: frosted surfaces over the household's gradient. */
  look: string
  /** The gradient behind the glass: `household` or a backdrop of its own. */
  backdrop: string
  /** A design pack: `household`, `core` (Fahoba's own) or `vendor/name`. */
  design: string
  /** Menu order and hidden areas: the wall's, then the phone's. Empty means untouched. */
  area_order: string[]
  hidden_areas: string[]
  /** The resting wall: areas in carousel order (empty = off), minutes, seconds. */
  screensaver_areas: string[]
  screensaver_idle: string
  screensaver_interval: string
  screensaver_dim: string
  /** The same while the household shows its dark theme. */
  screensaver_dim_night: string
  /** The last people filters on this device; null is what belongs to nobody. */
  task_people: (number | null)[]
  calendar_people: (number | null)[]
  /** The photo frame: layout (`single`, `duo`, `mosaic`, `ambient`, `memories`), at rest (`same` or a layout). */
  photo_style: string
  photo_rest_style: string
  /** `all`, `favorites` or `albums` (then `photo_albums`). */
  photo_source: string
  photo_albums: number[]
  /** Seconds per picture. */
  photo_interval: string
  /** `fit` keeps the whole picture, `fill` crops it. */
  photo_fit: string
  photo_motion: string
  photo_caption: string
  photo_clock: string
}

/**
 * What is set on this device.
 *
 * On the server and not in the browser's storage: a kiosk whose browser
 * data someone deletes would otherwise come up differently than set up. And
 * the admin could not show how a tablet is configured.
 */
export function usePreferences() {
  const preferences = useState<Preferences>('preferences', () => ({
    start_area: 'dashboard',
    calendar_view: 'three',
    task_view: 'day',
    task_done: 'hidden',
    school_band: 'off',
    menu_top: 'logo',
    phone_menu: 'drawer',
    colored_icons: 'off',
    colored_emoji: 'on',
    star_filled: 'off',
    star_color: 'off',
    animations: 'on',
    look: 'classic',
    backdrop: 'household',
    design: 'household',
    area_order: [],
    hidden_areas: [],
    screensaver_areas: [],
    screensaver_idle: '5',
    screensaver_interval: '30',
    screensaver_dim: '50',
    screensaver_dim_night: '70',
    task_people: [],
    calendar_people: [],
    photo_style: 'single',
    photo_rest_style: 'same',
    photo_source: 'all',
    photo_albums: [],
    photo_interval: '30',
    photo_fit: 'fit',
    photo_motion: 'on',
    photo_caption: 'on',
    photo_clock: 'off',
  }))

  const selection = useState<Record<string, string[]>>('preference-options', () => ({}))

  /** Only after loading may the interface apply the preferences. */
  const loaded = useState<boolean>('preferences-loaded', () => false)

  async function load(): Promise<void> {
    try {
      const data = await $fetch<{ preferences?: Preferences, options?: Record<string, string[]> }>(
        '/api/preferences',
        { baseURL: '/', credentials: 'same-origin' },
      )

      /*
       * Checked, not trusted: the service worker sometimes answers a request
       * at startup from its cache, and a response without content would
       * otherwise set the preferences to nothing — the interface would break
       * on first access.
       */
      if (data?.preferences) {
        preferences.value = data.preferences
      }

      if (data?.options) {
        selection.value = data.options
      }
    }
    catch {
      // Without a response the built-in defaults apply; the wall keeps working.
    }
    finally {
      loaded.value = true
    }
  }

  /**
   * Change a preference.
   *
   * Shown immediately, then saved: a toggle that only flips after the
   * server's response feels broken on a tablet.
   */
  async function set<K extends keyof Preferences>(key: K, value: Preferences[K]): Promise<void> {
    if (JSON.stringify(preferences.value[key]) === JSON.stringify(value)) {
      return
    }

    preferences.value = { ...preferences.value, [key]: value }

    await $fetch('/api/preferences', {
      method: 'PUT',
      body: { [key]: value },
      baseURL: '/',
      credentials: 'same-origin',
    }).catch(() => {
      // The setting then applies until the next restart. An error message for
      // a view choice would be louder on the wall than the error itself.
    })
  }

  /**
   * Like `set`, but shown only once the server took it, and failing loudly:
   * the wall's menu needs the parent PIN, and `withPin` needs the 423 to ask
   * for it.
   */
  async function save<K extends keyof Preferences>(key: K, value: Preferences[K]): Promise<void> {
    await $fetch('/api/preferences', {
      method: 'PUT',
      body: { [key]: value },
      baseURL: '/',
      credentials: 'same-origin',
    })

    preferences.value = { ...preferences.value, [key]: value }
  }

  return { preferences, selection, loaded, load, set, save }
}
