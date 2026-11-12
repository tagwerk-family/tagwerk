<script setup lang="ts">
import type { Preferences } from '../../../shared/app/composables/usePreferences'
import type { Area } from '~/utils/areas'
import { RESTING_AREAS } from '~/utils/screensaver'
import type { IconName } from '../../../shared/app/components/TwIcon.vue'
import type { AgendaPerson } from '../../../shared/app/utils/person'

const { t } = useI18n()

/*
 * What this tablet or this account has preset.
 *
 * Only a few switches, and all with immediate effect: a tablet on the wall
 * is set up once, not maintained. Anything that would need a justification
 * belongs in the admin.
 *
 * An overview with one row per topic and its current value, the details one
 * tap further. Escape leads from a topic back to the overview, a second one
 * closes. What a topic offers follows who signed in, never the width: the
 * resting mode is a tablet's, notifications, language and calendars an
 * account's.
 */
const props = defineProps<{
  preferences: Preferences
  /** The areas switched on — only those qualify as the start screen. */
  areas: Area[]
  /** All areas in this order, hidden ones included. */
  menu: Area[]
  bandAvailable: boolean
  timeZone: string
  device: boolean
  person: AgendaPerson | null
  /** Opens straight on a page, e.g. the household from the menu's name. */
  startPage?: 'household' | null
}>()

const emit = defineEmits<{
  set: [keyof Preferences, string]
  menu: ['area_order' | 'hidden_areas', string[]]
  rest: [string[]]
  /** The albums the photo frame draws from. */
  albums: [number[]]
  /** The darkening to show while the slider moves; null ends the preview. */
  preview: [string | null]
  /** The account signs out. */
  leave: []
  /** The account showed or hid one of its calendars. */
  calendars: []
}>()

const { user, setLanguage, switchHousehold, leaveHousehold, answerInvitation } = useAuth()
const { allowed: pushAllowed, possible: pushPossible, status: pushStatus, busy: pushBusy, enable: enablePush, disable: disablePush } = usePush()
const { calendars, error: calendarError, load: loadCalendars, toggle: toggleCalendar } = useCalendarChoice()

// Fetched on opening: the admin may have shown a calendar since.
onMounted(() => {
  if (!props.device) {
    loadCalendars()
  }
})

function switchPush(): void {
  void (pushAllowed.value ? disablePush() : enablePush())
}

async function flipCalendar(id: number): Promise<void> {
  if (await toggleCalendar(id)) {
    emit('calendars')
  }
}

const languageLabel = computed(() => {
  const choice = user.value?.locale_preference ?? null

  return choice === null ? t('settings.language.household') : user.value?.locales[choice] ?? choice
})

const IDLE = computed(() => Object.fromEntries([1, 2, 5, 10, 15].map(minutes => [minutes, t('device.minutes', { n: minutes })])))
const INTERVAL = computed(() => Object.fromEntries([15, 30, 60].map(seconds => [seconds, t('device.seconds', { n: seconds })])))

/*
 * Only areas that read well from a distance. From the whole menu: an area
 * hidden from the bar may still rest on the wall.
 */
const restable = computed(() => props.menu.filter(entry => RESTING_AREAS.includes(entry.value)))

/** Tapping adds at the end or takes out: the order of taps is the carousel's. */
function toggleRest(value: string): void {
  const chosen = props.preferences.screensaver_areas

  emit('rest', chosen.includes(value) ? chosen.filter(entry => entry !== value) : [...chosen, value])
}

function restPosition(value: string): number {
  return props.preferences.screensaver_areas.indexOf(value) + 1
}

/*
 * Two darkenings, by day and at night (the household's dark theme). Each
 * follows the finger, but is stored only on release: every step of a drag
 * would otherwise be a request.
 */
type DimKey = 'screensaver_dim' | 'screensaver_dim_night'

const DIMS = computed((): { key: DimKey, label: string }[] => [
  { key: 'screensaver_dim', label: t('screensaver.dimDay') },
  { key: 'screensaver_dim_night', label: t('screensaver.dimNight') },
])

const dims = reactive<Record<DimKey, string>>({
  screensaver_dim: props.preferences.screensaver_dim,
  screensaver_dim_night: props.preferences.screensaver_dim_night,
})

watch(() => [props.preferences.screensaver_dim, props.preferences.screensaver_dim_night], ([day, night]) => {
  dims.screensaver_dim = day ?? dims.screensaver_dim
  dims.screensaver_dim_night = night ?? dims.screensaver_dim_night
})

/*
 * The wall shows the darkening while the finger moves, and a moment after
 * release — long enough to see the result without holding on.
 */
const PREVIEW_AFTER_MS = 1500
let previewTimer: ReturnType<typeof setTimeout> | null = null

function stopPreviewTimer(): void {
  if (previewTimer !== null) {
    clearTimeout(previewTimer)
    previewTimer = null
  }
}

function previewDim(key: DimKey): void {
  stopPreviewTimer()
  emit('preview', dims[key])
}

function storeDim(key: DimKey): void {
  emit('set', key, dims[key])
  stopPreviewTimer()
  previewTimer = setTimeout(() => {
    previewTimer = null
    emit('preview', null)
  }, PREVIEW_AFTER_MS)
}

onBeforeUnmount(() => {
  stopPreviewTimer()
  emit('preview', null)
})

type Page = 'household' | 'appearance' | 'language' | 'start' | 'views' | 'calendars' | 'photos' | 'rest'

const page = ref<Page | null>(props.startPage ?? null)
useEscape(() => { page.value = null }, () => page.value !== null)

const PAGE_TITLES = computed((): Record<Page, string> => ({
  household: t('household.title'),
  appearance: t('theme.appearance'),
  language: t('settings.language.title'),
  start: t('settings.startMenu'),
  views: t('device.views'),
  calendars: t('settings.calendars'),
  photos: t('photos.settings.title'),
  rest: t('screensaver.title'),
}))

const currentHousehold = computed(() => user.value?.households?.find(entry => entry.current)?.name ?? '')

/*
 * Switching and leaving reload the page (`useAuth`); until then the buttons
 * stay busy, so nobody taps a second household in between.
 */
const { busy: householdBusy, run: runHousehold } = useBusy()
const householdError = ref<string | null>(null)

function chooseHousehold(id: number): void {
  householdError.value = null
  void runHousehold(() => switchHousehold(id).catch(() => {
    householdError.value = t('common.failed')
  }))
}

function answer(id: number, accept: boolean): void {
  householdError.value = null
  void runHousehold(() => answerInvitation(id, accept).catch(() => {
    householdError.value = t('common.failed')
  }))
}

function leaveThis(): void {
  householdError.value = null
  void runHousehold(async () => {
    householdError.value = await leaveHousehold()
  })
}

const startLabel = computed(() => props.areas.find(entry => entry.value === props.preferences.start_area)?.label ?? '')

const rows = computed((): { page: Page, icon: IconName, summary: string }[] => {
  const list: { page: Page, icon: IconName, summary: string }[] = []

  // Only for an account in more than one household, or asked to join one.
  if (!props.device && ((user.value?.households?.length ?? 0) > 1 || (user.value?.invitations?.length ?? 0) > 0)) {
    list.push({ page: 'household', icon: 'today', summary: currentHousehold.value })
  }

  list.push({ page: 'appearance', icon: 'palette', summary: t('settings.appearanceSummary') })

  if (!props.device) {
    list.push({ page: 'language', icon: 'globe', summary: languageLabel.value })
  }

  list.push(
    { page: 'start', icon: 'dashboard', summary: t('settings.startSummary', { area: startLabel.value }) },
    {
      page: 'views',
      icon: 'calendar',
      summary: t('device.viewsSummary', {
        calendar: CALENDAR_VIEWS.value[props.preferences.calendar_view as keyof typeof CALENDAR_VIEWS.value] ?? '',
        tasks: TASK_VIEWS.value[props.preferences.task_view as keyof typeof TASK_VIEWS.value] ?? '',
      }),
    },
  )

  if (!props.device && calendars.value.length > 0) {
    list.push({
      page: 'calendars',
      icon: 'calendar',
      summary: t('settings.calendarsSummary', {
        shown: calendars.value.filter(entry => entry.shown).length,
        total: calendars.value.length,
      }),
    })
  }

  if (props.areas.some(entry => entry.value === 'photos')) {
    list.push({
      page: 'photos',
      icon: 'photo',
      summary: t(`photos.styles.${props.preferences.photo_style}`),
    })
  }

  if (props.device) {
    list.push({
      page: 'rest',
      icon: 'moon',
      summary: props.preferences.screensaver_areas.length === 0
        ? t('common.off')
        : t('device.restSummary', {
            after: IDLE.value[Number(props.preferences.screensaver_idle)] ?? '',
            areas: t('device.restAreas', props.preferences.screensaver_areas.length),
          }),
    })
  }

  return list
})

const CALENDAR_VIEWS = computed(() => ({ day: t('calendar.views.day'), three: t('calendar.views.three'), week: t('calendar.views.week'), month: t('calendar.views.month'), list: t('tasks.views.list') }))
const TASK_VIEWS = computed(() => ({ day: t('calendar.views.day'), list: t('tasks.views.list') }))
const MENU_TOP = computed(() => ({ logo: t('device.menuTopLogo'), weather: t('device.menuTopWeather') }))
const BACKDROPS = computed(() => ({
  household: t('device.backdropHousehold'),
  dawn: t('device.backdropDawn'),
  lagoon: t('device.backdropLagoon'),
  meadow: t('device.backdropMeadow'),
  dusk: t('device.backdropDusk'),
  sand: t('device.backdropSand'),
  slate: t('device.backdropSlate'),
}))
// Installed design packs, as /api/theme lists them; hidden while there are none.
const { state: themeState } = useTheme()
const DESIGNS = computed(() => (themeState.value?.designs ?? []).length === 0
  ? null
  : {
      household: t('device.backdropHousehold'),
      core: 'Family Home Base',
      ...Object.fromEntries((themeState.value?.designs ?? []).map(design => [design.key, design.name])),
    })
const PHONE_MENU = computed(() => ({ drawer: t('device.phoneMenuDrawer'), bottom: t('device.phoneMenuBottom') }))

/* Plain on/off switches for how the wall looks and moves. */
const TOGGLES = computed((): { key: 'colored_icons' | 'colored_emoji' | 'star_filled' | 'star_color' | 'animations', label: string }[] => [
  { key: 'colored_icons', label: t('device.coloredIcons') },
  { key: 'colored_emoji', label: t('device.coloredEmoji') },
  { key: 'star_filled', label: t('device.starFilled') },
  { key: 'star_color', label: t('device.starColor') },
  { key: 'animations', label: t('device.animations') },
])

/*
 * The way from the tablet to the phone's sign-in in the same browser: the
 * device cookie always wins. Behind the parent PIN and two taps; afterwards
 * the start page, and only a new code from the admin pairs again.
 */
const { armed, tap } = useTwoStep()

const deletingAccount = ref(false)
const { write } = useActor()
const unpairFailed = ref(false)

async function unpair(): Promise<void> {
  unpairFailed.value = false

  try {
    await write(() => $fetch<unknown>('/api/devices/me', {
      method: 'DELETE',
      baseURL: '/',
      credentials: 'same-origin',
    }))

    window.location.replace('/')
  }
  catch {
    unpairFailed.value = true
  }
}
</script>

<template>
  <div>
    <!-- Overview -->
    <template v-if="page === null">
      <template v-if="device">
        <h2 id="settings-title" class="font-display text-2xl font-semibold">
          {{ $t('device.title') }}
        </h2>

        <p class="mt-1 text-base text-ink-2">
          {{ $t('device.hint') }}
        </p>
      </template>

      <template v-else>
        <div class="flex items-center gap-4 px-2">
          <TwAvatar :name="user?.name ?? ''" :color="props.person?.color" :avatar="props.person?.avatar" :size="56" />
          <div class="min-w-0 flex-1">
            <h2 id="settings-title" class="truncate font-display text-2xl font-semibold">
              {{ user?.name }}
            </h2>
            <p class="truncate text-base text-ink-2">
              {{ user?.email }}
            </p>
          </div>
        </div>

        <!-- Notifications: one switch, no page behind it. -->
        <div class="mt-6 overflow-hidden rounded-3xl bg-surface shadow-card">
          <button
            type="button"
            role="switch"
            data-testid="push"
            :aria-checked="pushAllowed"
            :disabled="!pushPossible || pushBusy"
            :aria-busy="pushBusy"
            class="flex min-h-16 w-full items-center gap-4 px-4 py-3 text-left"
            :class="pushPossible ? '' : 'opacity-60'"
            @click="switchPush"
          >
            <span class="flex size-10 shrink-0 items-center justify-center rounded-2xl bg-accent-soft text-accent" aria-hidden="true">
              <TwIcon name="bell" :size="22" />
            </span>
            <span class="min-w-0 flex-1">
              <span class="block text-lg font-semibold">{{ $t('push.title') }}</span>
              <span class="block text-sm text-ink-2">{{ pushPossible ? $t('push.hint') : $t('push.notSetUp') }}</span>
            </span>
            <TwSpinner v-if="pushBusy" :size="24" class="mx-3 text-accent" />
            <SwitchKnob v-else :on="pushAllowed" />
          </button>

          <p
            v-if="pushStatus"
            data-testid="push-status"
            :role="pushStatus.ok ? 'status' : 'alert'"
            class="flex items-center gap-2 border-t border-line px-4 py-3 text-base font-medium"
            :class="pushStatus.ok ? 'text-success' : 'text-danger'"
          >
            <TwIcon :name="pushStatus.ok ? 'check' : 'warning'" :size="20" />
            {{ pushStatus.text }}
          </p>
        </div>
      </template>

      <ul class="mt-5 divide-y divide-line overflow-hidden rounded-3xl bg-surface shadow-card">
        <li v-for="row in rows" :key="row.page">
          <button
            type="button"
            data-testid="settings-page"
            :data-value="row.page"
            class="flex min-h-16 w-full items-center gap-4 px-4 py-3 text-left transition-colors hover:bg-surface-2"
            @click="page = row.page"
          >
            <span class="flex size-10 shrink-0 items-center justify-center rounded-2xl bg-surface-2 text-ink-2" aria-hidden="true">
              <TwIcon :name="row.icon" :size="22" />
            </span>
            <span class="min-w-0 flex-1">
              <span class="block text-lg font-semibold">{{ PAGE_TITLES[row.page] }}</span>
              <span class="block truncate text-sm text-ink-2">{{ row.summary }}</span>
            </span>
            <TwIcon name="forward" :size="20" class="shrink-0 text-ink-3" />
          </button>
        </li>
      </ul>

      <button
        v-if="!device"
        type="button"
        data-testid="sign-out"
        class="mt-4 flex min-h-14 w-full items-center gap-4 rounded-3xl bg-surface px-4 text-left text-lg font-semibold text-danger shadow-card transition-colors hover:bg-surface-2"
        @click="emit('leave')"
      >
        <span class="flex size-10 shrink-0 items-center justify-center" aria-hidden="true">
          <TwIcon name="logout" :size="22" />
        </span>
        {{ $t('login.signOut') }}
      </button>

      <button
        v-if="!device"
        type="button"
        data-testid="delete-account-open"
        class="mt-2 flex min-h-11 w-full items-center justify-center rounded-2xl text-sm font-semibold text-ink-2 underline"
        @click="deletingAccount = true"
      >
        {{ $t('account.delete') }}
      </button>

      <DeleteAccountDialog
        v-if="deletingAccount"
        @close="deletingAccount = false"
        @deleted="deletingAccount = false; emit('leave')"
      />

      <div v-else class="mt-4 rounded-3xl bg-surface p-4 shadow-card">
        <button
          type="button"
          data-testid="unpair"
          class="flex min-h-12 w-full items-center gap-4 rounded-2xl text-left text-lg font-semibold transition-colors"
          :class="armed === 'unpair' ? 'bg-danger px-3 text-surface' : 'text-danger'"
          @click="tap('unpair', unpair)"
        >
          <span class="flex size-10 shrink-0 items-center justify-center" aria-hidden="true">
            <TwIcon name="logout" :size="22" />
          </span>
          {{ armed === 'unpair' ? $t('device.unpairConfirm') : $t('device.unpair') }}
        </button>

        <p class="mt-1 text-sm text-ink-2">
          {{ $t('device.unpairHint') }}
        </p>

        <p v-if="unpairFailed" role="alert" class="mt-2 text-sm text-danger">
          {{ $t('common.failed') }}
        </p>
      </div>
    </template>

    <!-- One topic -->
    <template v-else>
      <div class="flex items-center gap-2">
        <button
          type="button"
          data-testid="settings-back"
          :aria-label="$t('common.back')"
          class="-ml-2 flex size-11 shrink-0 items-center justify-center rounded-full text-ink-2 hover:bg-surface-2"
          @click="page = null"
        >
          <TwIcon name="back" :size="24" />
        </button>
        <h2 id="settings-title" class="min-w-0 flex-1 truncate font-display text-2xl font-semibold">
          {{ PAGE_TITLES[page] }}
        </h2>
      </div>

      <div class="mt-4 space-y-4">
        <template v-if="page === 'appearance'">
          <section class="rounded-3xl bg-surface p-4 shadow-card" aria-labelledby="look-theme">
            <h3 id="look-theme" class="mb-3 text-lg font-semibold">{{ $t('settings.theme') }}</h3>
            <ThemeSwitch :time-zone="props.timeZone" />
          </section>

          <ul class="divide-y divide-line overflow-hidden rounded-3xl bg-surface shadow-card">
            <li>
              <button
                type="button"
                role="switch"
                data-testid="preference"
                data-cell="look"
                :aria-checked="preferences.look === 'glass'"
                class="flex min-h-14 w-full items-center gap-4 px-4 py-3 text-left text-lg font-semibold"
                @click="emit('set', 'look', preferences.look === 'glass' ? 'classic' : 'glass')"
              >
                <span class="flex-1">{{ $t('device.glass') }}</span>
                <SwitchKnob :on="preferences.look === 'glass'" />
              </button>
            </li>
            <li v-for="toggle in TOGGLES" :key="toggle.key">
              <button
                type="button"
                role="switch"
                data-testid="preference"
                :data-cell="toggle.key"
                :aria-checked="preferences[toggle.key] === 'on'"
                class="flex min-h-14 w-full items-center gap-4 px-4 py-3 text-left text-lg font-semibold"
                @click="emit('set', toggle.key, preferences[toggle.key] === 'on' ? 'off' : 'on')"
              >
                <span class="flex-1">{{ toggle.label }}</span>
                <SwitchKnob :on="preferences[toggle.key] === 'on'" />
              </button>
            </li>
          </ul>

          <section v-if="DESIGNS" class="rounded-3xl bg-surface p-4 shadow-card" aria-labelledby="look-design">
            <h3 id="look-design" class="mb-3 text-lg font-semibold">{{ $t('device.design') }}</h3>
            <div role="group" aria-labelledby="look-design" class="flex flex-wrap gap-1.5">
              <button
                v-for="(label, value) in DESIGNS"
                :key="value"
                type="button"
                data-testid="preference"
                data-cell="design"
                :data-value="value"
                :aria-pressed="preferences.design === value"
                class="min-h-11 rounded-xl px-4 text-base font-semibold transition-colors"
                :class="preferences.design === value
                  ? 'bg-accent-soft text-accent'
                  : 'bg-surface-2 text-ink-2 hover:text-ink'"
                @click="emit('set', 'design', value)"
              >
                {{ label }}
              </button>
            </div>
          </section>

          <section v-if="preferences.look === 'glass'" class="rounded-3xl bg-surface p-4 shadow-card" aria-labelledby="look-backdrop">
            <h3 id="look-backdrop" class="mb-3 text-lg font-semibold">{{ $t('device.backdrop') }}</h3>
            <div role="group" aria-labelledby="look-backdrop" class="flex flex-wrap gap-1.5">
              <button
                v-for="(label, value) in BACKDROPS"
                :key="value"
                type="button"
                data-testid="preference"
                data-cell="backdrop"
                :data-value="value"
                :aria-pressed="preferences.backdrop === value"
                class="min-h-11 rounded-xl px-4 text-base font-semibold transition-colors"
                :class="preferences.backdrop === value
                  ? 'bg-accent-soft text-accent'
                  : 'bg-surface-2 text-ink-2 hover:text-ink'"
                @click="emit('set', 'backdrop', value)"
              >
                {{ label }}
              </button>
            </div>
          </section>

        </template>

        <section v-else-if="page === 'language'" class="rounded-3xl bg-surface p-4 shadow-card" aria-labelledby="language-hint">
          <p id="language-hint" class="mb-3 text-sm text-ink-2">{{ $t('settings.language.hint') }}</p>
          <ChoiceChips
            :options="[null, ...Object.keys(user?.locales ?? {})].map(choice => ({
              value: choice,
              label: choice === null ? $t('settings.language.household') : user?.locales[choice] ?? choice,
            }))"
            :model-value="user?.locale_preference ?? null"
            labelled-by="language-hint"
            test-id="language"
            @update:model-value="setLanguage($event)"
          />
        </section>

        <template v-else-if="page === 'household'">
          <ul class="divide-y divide-line overflow-hidden rounded-3xl bg-surface shadow-card">
            <li v-for="entry in user?.households ?? []" :key="entry.id">
              <button
                type="button"
                data-testid="household"
                :data-value="entry.id"
                :aria-pressed="entry.current"
                :disabled="entry.current || householdBusy"
                class="flex min-h-14 w-full items-center gap-3 px-4 py-3 text-left text-lg font-semibold"
                @click="chooseHousehold(entry.id)"
              >
                <span class="flex-1" :class="entry.current ? 'text-accent' : 'text-ink'">{{ entry.name }}</span>
                <TwIcon v-if="entry.current" name="check" :size="22" class="text-accent" />
              </button>
            </li>
          </ul>

          <section
            v-if="(user?.invitations?.length ?? 0) > 0"
            class="rounded-3xl bg-surface p-4 shadow-card"
            aria-labelledby="household-invitations"
          >
            <h3 id="household-invitations" class="mb-3 text-lg font-semibold">{{ $t('household.invitations') }}</h3>
            <ul class="space-y-3">
              <li
                v-for="entry in user?.invitations ?? []"
                :key="entry.id"
                data-testid="household-invitation"
                :data-value="entry.id"
                class="flex flex-wrap items-center gap-2"
              >
                <span class="min-w-0 flex-1 text-lg font-semibold">{{ entry.name }}</span>
                <button
                  type="button"
                  data-testid="household-accept"
                  :disabled="householdBusy"
                  class="min-h-11 rounded-xl bg-accent px-4 text-base font-semibold text-accent-ink"
                  @click="answer(entry.id, true)"
                >
                  {{ $t('household.accept') }}
                </button>
                <button
                  type="button"
                  data-testid="household-decline"
                  :disabled="householdBusy"
                  class="min-h-11 rounded-xl bg-surface-2 px-4 text-base font-semibold text-ink-2"
                  @click="answer(entry.id, false)"
                >
                  {{ $t('household.decline') }}
                </button>
              </li>
            </ul>
          </section>

          <div v-if="(user?.households?.length ?? 0) > 1" class="rounded-3xl bg-surface p-4 shadow-card">
            <button
              type="button"
              data-testid="household-leave"
              :disabled="householdBusy"
              class="flex min-h-12 w-full items-center gap-4 rounded-2xl text-left text-lg font-semibold transition-colors"
              :class="armed === 'leave' ? 'bg-danger px-3 text-surface' : 'text-danger'"
              @click="tap('leave', leaveThis)"
            >
              <span class="flex size-10 shrink-0 items-center justify-center" aria-hidden="true">
                <TwIcon name="logout" :size="22" />
              </span>
              {{ armed === 'leave' ? $t('household.leaveConfirm', { name: currentHousehold }) : $t('household.leave') }}
            </button>

            <p class="mt-1 text-sm text-ink-2">
              {{ $t('household.leaveHint') }}
            </p>
          </div>

          <p v-if="householdError" role="alert" class="px-2 text-base text-danger">
            {{ householdError }}
          </p>
        </template>

        <template v-else-if="page === 'calendars'">
          <p class="px-2 text-sm text-ink-2">{{ $t('settings.calendarsHint') }}</p>

          <ul class="divide-y divide-line overflow-hidden rounded-3xl bg-surface shadow-card">
            <li v-for="entry in calendars" :key="entry.id">
              <button
                type="button"
                role="switch"
                data-testid="calendar-choice"
                :aria-checked="entry.shown"
                class="flex min-h-14 w-full items-center gap-3 px-4 py-3 text-left text-lg font-semibold"
                @click="flipCalendar(entry.id)"
              >
                <span class="size-4 shrink-0 rounded-full" :style="{ backgroundColor: entry.color }" aria-hidden="true" />
                <span class="flex-1" :class="entry.shown ? 'text-ink' : 'text-ink-3'">{{ entry.summary }}</span>
                <SwitchKnob :on="entry.shown" />
              </button>
            </li>
          </ul>

          <p v-if="calendarError" role="alert" class="px-2 text-base text-danger">
            {{ calendarError }}
          </p>
        </template>

        <template v-else-if="page === 'start'">
          <section class="rounded-3xl bg-surface p-4 shadow-card" aria-labelledby="start-area">
            <h3 id="start-area" class="mb-3 text-lg font-semibold">{{ $t('device.start') }}</h3>
            <div role="group" aria-labelledby="start-area" class="flex flex-wrap gap-1.5">
              <button
                v-for="entry in areas"
                :key="entry.value"
                type="button"
                data-testid="preference"
                data-cell="start_area"
                :data-value="entry.value"
                :aria-pressed="preferences.start_area === entry.value"
                class="min-h-11 rounded-xl px-4 text-base font-semibold transition-colors"
                :class="preferences.start_area === entry.value
                  ? 'bg-accent-soft text-accent'
                  : 'bg-surface-2 text-ink-2 hover:text-ink'"
                @click="emit('set', 'start_area', entry.value)"
              >
                {{ entry.label }}
              </button>
            </div>
          </section>

          <section class="rounded-3xl bg-surface p-4 shadow-card" aria-labelledby="start-menu">
            <h3 id="start-menu" class="text-lg font-semibold">{{ $t('device.menu') }}</h3>
            <p class="mt-0.5 mb-3 text-sm text-ink-2">{{ $t('device.menuHint') }}</p>
            <MenuEditor
              :entries="menu"
              :hidden="preferences.hidden_areas"
              :locked="preferences.start_area"
              @order="emit('menu', 'area_order', $event)"
              @hidden="emit('menu', 'hidden_areas', $event)"
            />
          </section>

          <section class="rounded-3xl bg-surface p-4 shadow-card" aria-labelledby="start-menu-top">
            <h3 id="start-menu-top" class="mb-3 text-lg font-semibold">{{ $t('device.menuTop') }}</h3>
            <div role="group" aria-labelledby="start-menu-top" class="flex flex-wrap gap-1.5">
              <button
                v-for="(label, value) in MENU_TOP"
                :key="value"
                type="button"
                data-testid="preference"
                data-cell="menu_top"
                :data-value="value"
                :aria-pressed="preferences.menu_top === value"
                class="min-h-11 rounded-xl px-4 text-base font-semibold transition-colors"
                :class="preferences.menu_top === value
                  ? 'bg-accent-soft text-accent'
                  : 'bg-surface-2 text-ink-2 hover:text-ink'"
                @click="emit('set', 'menu_top', value)"
              >
                {{ label }}
              </button>
            </div>
          </section>

          <section class="rounded-3xl bg-surface p-4 shadow-card" aria-labelledby="start-phone-menu">
            <h3 id="start-phone-menu" class="mb-3 text-lg font-semibold">{{ $t('device.phoneMenu') }}</h3>
            <div role="group" aria-labelledby="start-phone-menu" class="flex flex-wrap gap-1.5">
              <button
                v-for="(label, value) in PHONE_MENU"
                :key="value"
                type="button"
                data-testid="preference"
                data-cell="phone_menu"
                :data-value="value"
                :aria-pressed="preferences.phone_menu === value"
                class="min-h-11 rounded-xl px-4 text-base font-semibold transition-colors"
                :class="preferences.phone_menu === value
                  ? 'bg-accent-soft text-accent'
                  : 'bg-surface-2 text-ink-2 hover:text-ink'"
                @click="emit('set', 'phone_menu', value)"
              >
                {{ label }}
              </button>
            </div>
          </section>
        </template>

        <template v-else-if="page === 'views'">
          <section class="rounded-3xl bg-surface p-4 shadow-card" aria-labelledby="views-calendar">
            <h3 id="views-calendar" class="mb-3 text-lg font-semibold">{{ $t('device.calendarStarts') }}</h3>
            <div role="group" aria-labelledby="views-calendar" class="flex flex-wrap gap-1.5">
              <button
                v-for="(label, value) in CALENDAR_VIEWS"
                :key="value"
                type="button"
                data-testid="preference"
                data-cell="calendar_view"
                :data-value="value"
                :aria-pressed="preferences.calendar_view === value"
                class="min-h-11 rounded-xl px-4 text-base font-semibold transition-colors"
                :class="preferences.calendar_view === value
                  ? 'bg-accent-soft text-accent'
                  : 'bg-surface-2 text-ink-2 hover:text-ink'"
                @click="emit('set', 'calendar_view', value)"
              >
                {{ label }}
              </button>
            </div>
          </section>

          <section class="rounded-3xl bg-surface p-4 shadow-card" aria-labelledby="views-tasks">
            <h3 id="views-tasks" class="mb-3 text-lg font-semibold">{{ $t('device.tasksStart') }}</h3>
            <div role="group" aria-labelledby="views-tasks" class="flex flex-wrap gap-1.5">
              <button
                v-for="(label, value) in TASK_VIEWS"
                :key="value"
                type="button"
                data-testid="preference"
                data-cell="task_view"
                :data-value="value"
                :aria-pressed="preferences.task_view === value"
                class="min-h-11 rounded-xl px-4 text-base font-semibold transition-colors"
                :class="preferences.task_view === value
                  ? 'bg-accent-soft text-accent'
                  : 'bg-surface-2 text-ink-2 hover:text-ink'"
                @click="emit('set', 'task_view', value)"
              >
                {{ label }}
              </button>
            </div>
          </section>

          <ul v-if="bandAvailable" class="overflow-hidden rounded-3xl bg-surface shadow-card">
            <li>
              <button
                type="button"
                role="switch"
                data-testid="preference"
                data-cell="school_band"
                :aria-checked="preferences.school_band === 'on'"
                class="flex min-h-14 w-full items-center gap-4 px-4 py-3 text-left text-lg font-semibold"
                @click="emit('set', 'school_band', preferences.school_band === 'on' ? 'off' : 'on')"
              >
                <span class="flex-1">{{ $t('calendar.schoolBand') }}</span>
                <SwitchKnob :on="preferences.school_band === 'on'" />
              </button>
            </li>
          </ul>
        </template>

        <PhotoFrameSettings
          v-else-if="page === 'photos'"
          :preferences="preferences"
          :device="device"
          @set="(key, value) => emit('set', key, value)"
          @albums="emit('albums', $event)"
        />

        <template v-else-if="page === 'rest'">
          <section data-testid="screensaver-settings" class="rounded-3xl bg-surface p-4 shadow-card" aria-labelledby="rest-areas">
            <h3 id="rest-areas" class="text-lg font-semibold">{{ $t('screensaver.areas') }}</h3>
            <p class="mt-0.5 mb-3 text-sm text-ink-2">{{ $t('screensaver.hint') }}</p>

            <div role="group" aria-labelledby="rest-areas" class="flex flex-wrap gap-1.5">
              <button
                v-for="entry in restable"
                :key="entry.value"
                type="button"
                data-testid="rest-area"
                :data-value="entry.value"
                :aria-pressed="restPosition(entry.value) > 0"
                class="flex min-h-11 items-center gap-2 rounded-xl px-4 text-base font-semibold transition-colors"
                :class="restPosition(entry.value) > 0
                  ? 'bg-accent-soft text-accent'
                  : 'bg-surface-2 text-ink-2 hover:text-ink'"
                @click="toggleRest(entry.value)"
              >
                <span
                  v-if="restPosition(entry.value) > 0"
                  class="flex size-6 items-center justify-center rounded-full bg-accent text-sm text-accent-ink"
                >{{ restPosition(entry.value) }}</span>
                {{ entry.label }}
              </button>
            </div>
          </section>

          <template v-if="preferences.screensaver_areas.length > 0">
            <section class="rounded-3xl bg-surface p-4 shadow-card" aria-labelledby="rest-after">
              <h3 id="rest-after" class="mb-3 text-lg font-semibold">{{ $t('screensaver.after') }}</h3>
              <div role="group" aria-labelledby="rest-after" class="flex flex-wrap gap-1.5">
                <button
                  v-for="(label, value) in IDLE"
                  :key="value"
                  type="button"
                  data-testid="preference"
                  data-cell="screensaver_idle"
                  :data-value="value"
                  :aria-pressed="preferences.screensaver_idle === String(value)"
                  class="min-h-11 rounded-xl px-3 text-base font-semibold transition-colors"
                  :class="preferences.screensaver_idle === String(value)
                    ? 'bg-accent-soft text-accent'
                    : 'bg-surface-2 text-ink-2 hover:text-ink'"
                  @click="emit('set', 'screensaver_idle', String(value))"
                >
                  {{ label }}
                </button>
              </div>

              <h3 id="rest-every" class="mt-4 mb-3 text-lg font-semibold">{{ $t('screensaver.every') }}</h3>
              <div role="group" aria-labelledby="rest-every" class="flex flex-wrap gap-1.5">
                <button
                  v-for="(label, value) in INTERVAL"
                  :key="value"
                  type="button"
                  data-testid="preference"
                  data-cell="screensaver_interval"
                  :data-value="value"
                  :aria-pressed="preferences.screensaver_interval === String(value)"
                  class="min-h-11 rounded-xl px-3 text-base font-semibold transition-colors"
                  :class="preferences.screensaver_interval === String(value)
                    ? 'bg-accent-soft text-accent'
                    : 'bg-surface-2 text-ink-2 hover:text-ink'"
                  @click="emit('set', 'screensaver_interval', String(value))"
                >
                  {{ label }}
                </button>
              </div>
            </section>

            <section class="rounded-3xl bg-surface p-4 shadow-card">
              <label v-for="entry in DIMS" :key="entry.key" class="flex min-h-11 items-center gap-3">
                <span class="w-36 shrink-0 text-base font-semibold">{{ entry.label }}</span>
                <input
                  v-model="dims[entry.key]"
                  type="range"
                  min="0"
                  max="90"
                  step="5"
                  data-testid="screensaver-dim"
                  :data-cell="entry.key"
                  class="h-11 min-w-0 flex-1 cursor-pointer accent-accent"
                  @input="previewDim(entry.key)"
                  @change="storeDim(entry.key)"
                >
                <span class="w-12 text-right text-base font-semibold text-ink-2 tabular-nums">{{ $t('screensaver.percent', { n: dims[entry.key] }) }}</span>
              </label>
            </section>
          </template>
        </template>
      </div>
    </template>
  </div>
</template>
