<script setup lang="ts">
import type { MealDto } from '../../../shared/app/composables/useMeals'
import { intlLocale } from '../../../shared/app/utils/intlLocale'
import type { ListItemDto } from '~/composables/useLists'
import type { OccurrenceDto } from '../../../shared/app/composables/useTasks'
import { type Area, type Mode, validMode } from '~/utils/areas'
import { modeKey } from '~/utils/entry'
import { areaEmoji } from '../../../shared/app/utils/areaEmoji'
import { arranged } from '../../../shared/app/utils/menu'
import type { Lesson } from '../../../shared/app/utils/timetable'
import { type AgendaEvent, type CalendarDay, type GridInput, dayGrid, formatIsoDate, monthGrid, threeDayGrid, weekGrid, zonedIso } from '~/utils/calendar'
import { type CalendarView, LIST_DAYS, NARROW_VIEWS, WIDE_VIEWS, shownView } from '~/utils/calendarView'
import { peopleColumns, peopleOfEvent, visibleEvents } from '~/utils/calendarPeople'
import { carousel, dimScreen, idleMs, intervalMs, nextSlide, previewScreen, restingDim, restoreScreen, slideMs, veilOpacity } from '~/utils/screensaver'
import { photoIntervalMs, upcomingToday } from '~/utils/photoFrame'
import type { FrameEvent } from '~/components/PhotoFrame.vue'

const { t } = useI18n()

/*
 * The one shell for wall and phone. Whether something appears follows who
 * signed in (`device`, `can()`); how it is laid out follows the width
 * (`narrow`). The two are never mixed.
 */
const { entry } = useEntry()
const device = computed(() => entry.value === 'device')
const narrow = useNarrow()
const { can } = useActor()
const { user, logout } = useAuth()
const { load: loadNotifications } = useNotifications()
const { offered: pushOffered, check: checkPush } = usePush()

const { agenda, asOf, stale, paired, areas: householdAreas, load } = useAgenda()
const { occurrences, peopleFilter: taskPeople, error: tasksError, load: loadTasks, toggle: toggleTask } = useTasks()
const { lists, loaded: listsLoaded, error: listsError, load: loadLists, create, toggle: toggleItem, remove, cleanUp } = useLists()
const { load: loadPoints } = usePoints()
const { state: themeState, load: loadTheme, end: endTheme } = useTheme()

// Writes the wall is not allowed without the PIN ask for it here and retry.
const { open: pinGateOpen, withPin, settle: settlePinGate } = usePinGate()
const { snapshot: progress, load: loadProgress } = useProgress()

// The home page, not the calendar: whoever walks past the wall should
// see everything important at a glance, without tapping.
const mode = ref<Mode>('dashboard')

/** Whether the wall rests: dark, without controls, showing its carousel. */
const resting = ref(false)
const { shown: installOffered } = useInstallOffer()

/*
 * Photos at rest go edge to edge: no bar, no header, no frame — the time
 * moves into the picture's corner. Only a light veil, since the pictures
 * are what is there to see.
 */
const bleed = computed(() => resting.value && mode.value === 'photos')

const { view: areaView } = useAreaViews()
const pluginView = computed(() => areaView(mode.value))

/*
 * View and school band are stored in the device's preferences. Whoever
 * switches also sets how the tablet comes up after a power cut -- on a wall
 * that is the same thing: you set it up once and leave it that way.
 */
const { preferences, load: loadPreferences, set: setPreference, save: savePreference } = usePreferences()

/*
 * The bar as this device arranged it. The household decides which areas
 * exist, the device only their order and which it hides.
 */
const menu = computed(() => arranged(
  householdAreas.value.map(entry => entry.value),
  preferences.value.area_order,
  preferences.value.hidden_areas,
))

function areasOf(keys: string[]): Area[] {
  return keys
    .map(key => householdAreas.value.find(entry => entry.value === key))
    .filter((entry): entry is Area => entry !== undefined)
}

const areas = computed(() => areasOf(menu.value.visible))

const coloredIcons = computed(() => preferences.value.colored_icons === 'on')

/*
 * The animation switch lives on <html>, so one CSS rule stills every
 * movement — including those in the layer's components.
 */
watch(() => preferences.value.animations, (value) => {
  if (import.meta.client) {
    document.documentElement.dataset.motion = value
  }
}, { immediate: true })

// The resting wall stays plain: dark and veiled, glass would only cost.
watch(() => resting.value ? 'classic' : preferences.value.look, (value) => {
  if (import.meta.client) {
    document.documentElement.dataset.look = value
  }
}, { immediate: true })
// Backdrop and design are resolved by the server, beside the household's own choice.
async function choosePreference(...args: Parameters<typeof setPreference>): Promise<void> {
  await setPreference(...args)

  if (args[0] === 'backdrop' || args[0] === 'design') {
    await loadTheme()
  }
}

const menuAreas = computed(() => areasOf(menu.value.all))

// Every fetch carries it, so a change in the admin reaches the wall without
// re-pairing. An account's language comes with its sign-in (app.vue).
const { apply: applyLanguage } = useLanguage()
watch(() => JSON.stringify(agenda.value?.language ?? agenda.value?.locale ?? null), () => {
  if (device.value) {
    applyLanguage(agenda.value?.language ?? agenda.value?.locale)
  }
}, { immediate: true })

watch(areas, (list) => {
  mode.value = validMode(mode.value, list)
})

/** On a tablet sorting or hiding needs the parent PIN; the gate asks and retries. */
function arrangeMenu(key: 'area_order' | 'hidden_areas', value: string[]): void {
  withPin(() => savePreference(key, value)).catch(() => {
    // Cancelled at the PIN or no connection: the bar stays as it was.
  })
}

const views = computed(() => (narrow.value ? NARROW_VIEWS : WIDE_VIEWS))

const view = computed<CalendarView>({
  // At rest the calendar shows today, one column per person.
  get: () => (resting.value ? 'day' : shownView(preferences.value.calendar_view, narrow.value)),
  set: value => void setPreference('calendar_view', value),
})

const bandOn = computed<boolean>({
  get: () => preferences.value.school_band === 'on',
  set: value => void setPreference('school_band', value ? 'on' : 'off'),
})

const schoolLessons = computed(() => (bandOn.value ? agenda.value?.lessons ?? [] : []))

/*
 * Whose calendars are currently shown. Stored with the device: the tablet in
 * a child's room keeps showing that child's week after a restart.
 */
const peopleFilter = computed<(number | null)[]>({
  get: () => preferences.value.calendar_people,
  set: value => void setPreference('calendar_people', value),
})

function togglePerson(id: number | null): void {
  peopleFilter.value = peopleFilter.value.includes(id)
    ? peopleFilter.value.filter(entry => entry !== id)
    : [...peopleFilter.value, id]
}

const calendars = computed(() => agenda.value?.calendars ?? [])

const shownEvents = computed(
  () => visibleEvents(agenda.value?.events ?? [], calendars.value, peopleFilter.value),
)

/** Whether anything in the loaded range belongs to the family at all. */
const familyEvents = computed(
  () => (agenda.value?.events ?? []).some(event => peopleOfEvent(event, calendars.value).length === 0),
)

// The toggle only appears where there is something to show.
const bandAvailable = computed(() => areas.value.some(entry => entry.feature === 'timetable'))

const anchor = ref(new Date())
const now = ref(new Date())
const themeOpen = ref(false)
useEscape(() => { themeOpen.value = false }, () => themeOpen.value)

/*
 * The household's time zone, not the device's. As long as nothing is loaded
 * yet only the device's remains — nothing is shown at that point anyway.
 */
const zone = computed(
  () => agenda.value?.household.timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone,
)

const todayIso = computed(() => zonedIso(now.value, zone.value))

/** One day after another — the list is a week of single days. */
function listGrid(input: GridInput): CalendarDay[] {
  const start = anchor.value

  return Array.from({ length: LIST_DAYS }, (_, i) => dayGrid(
    new Date(start.getFullYear(), start.getMonth(), start.getDate() + i, 12),
    input,
  )[0]!)
}

function grid(input: GridInput): CalendarDay[] {
  switch (view.value) {
    case 'list':
      return listGrid(input)
    case 'month':
      return monthGrid(anchor.value, input)
    case 'week':
      return weekGrid(anchor.value, input)
    case 'day':
      return dayGrid(anchor.value, input)
    default:
      return threeDayGrid(anchor.value, input)
  }
}

/** Only from anchor, view and zone — otherwise the loaded range would depend on the data it loads. */
const range = computed(() => {
  const raster = grid({ events: [], holidays: [], today: now.value, timeZone: zone.value })

  return { from: raster[0]!.iso, until: raster[raster.length - 1]!.iso }
})

/** Tasks show two weeks — nobody plans further ahead on the wall. */
const taskArea = computed(() => {
  const until = new Date(`${todayIso.value}T00:00:00Z`)

  until.setUTCDate(until.getUTCDate() + 13)

  return { from: todayIso.value, until: until.toISOString().slice(0, 10) }
})

const days = computed(() => grid({
  events: shownEvents.value,
  holidays: agenda.value?.holidays ?? [],
  today: now.value,
  timeZone: zone.value,
}))

/*
 * Empty outside the day view — then the days are the columns. Narrow, too:
 * a column per person leaves a phone a sliver each, so it shows the day.
 */
const columns = computed(() => {
  if (view.value !== 'day' || !agenda.value || narrow.value) {
    return []
  }

  const visible = agenda.value.people.filter(
    person => peopleFilter.value.length === 0 || peopleFilter.value.includes(person.id),
  )

  return peopleColumns(days.value[0]!, visible, calendars.value, t('people.family'))
})

const title = computed(() => {
  if (mode.value !== 'calendar') {
    return areas.value.find(entry => entry.value === mode.value)?.label ?? ''
  }

  if (view.value === 'month') {
    return formatIsoDate(days.value[10]!.iso, { month: 'long', year: 'numeric' })
  }

  // A narrow header has room for the area's name only; the toolbar says the rest.
  if (narrow.value) {
    return areas.value.find(entry => entry.value === mode.value)?.label ?? ''
  }

  if (view.value === 'day') {
    return formatIsoDate(days.value[0]!.iso, { weekday: 'long', day: 'numeric', month: 'long' })
  }

  const first = formatIsoDate(days.value[0]!.iso, { day: 'numeric', month: 'short' })
  const last = formatIsoDate(days.value[days.value.length - 1]!.iso, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })

  return `${first} – ${last}`
})

const dateText = computed(() => formatIsoDate(todayIso.value, { weekday: 'long', day: 'numeric', month: 'long' }))

/* What the ambient photo frame lists beside the picture. */
const frameEvents = computed((): FrameEvent[] => {
  const time = new Intl.DateTimeFormat(intlLocale.value, { hour: '2-digit', minute: '2-digit', timeZone: zone.value })

  return upcomingToday(shownEvents.value, now.value, zone.value).map(event => ({
    id: event.id,
    title: event.title,
    time: event.all_day ? null : time.format(new Date(event.starts_at)),
    color: event.color,
  }))
})

const clockTime = computed(() => new Intl.DateTimeFormat(intlLocale.value, {
  hour: '2-digit',
  minute: '2-digit',
  timeZone: zone.value,
}).format(now.value))

const standText = computed(() => {
  if (!asOf.value) {
    return null
  }

  return new Date(asOf.value).toLocaleTimeString(intlLocale.value, {
    hour: '2-digit',
    minute: '2-digit',
    timeZone: zone.value,
  })
})

/*
 * Null means closed. Creating and changing share the same dialog: the fields
 * are the same, and two versions would inevitably drift apart.
 */
const dialog = ref<{ event: AgendaEvent | null, day: string } | null>(null)

function newEvent(iso: string): void {
  dialog.value = { event: null, day: iso }
}

/*
 * A tablet opens the dialog right away — the PIN guards what it may not do.
 * An account reads first and changes only where the event allows it.
 */
const reading = ref<AgendaEvent | null>(null)

function openEvent(event: AgendaEvent): void {
  if (!device.value) {
    reading.value = event

    return
  }

  dialog.value = { event, day: event.starts_at.slice(0, 10) }
}

function editEvent(event: AgendaEvent): void {
  reading.value = null
  dialog.value = { event, day: zonedIso(new Date(event.starts_at), zone.value) }
}

/** From the dashboard's tasks tile: that person's tasks, nobody else's. */
function showTasks(personId: number): void {
  taskPeople.value = [personId]
  mode.value = 'tasks'
}

function saved(): void {
  dialog.value = null
  load(range.value.from, range.value.until)
}

/*
 * A lesson in the calendar band: the same write path as in the timetable.
 * After the entry the agenda is fetched again — it carries the lessons.
 */
const lessonOpen = ref<Lesson | null>(null)

/*
 * A planned meal's recipe: the cooking view. Someone reads along at the
 * stove, so the wall does not rest while it is open.
 */
const cooking = ref<MealDto | null>(null)

const { cancel, replace, revert } = useLessonException()

async function changeLesson(perform: (lesson: Lesson) => Promise<void>): Promise<void> {
  const lesson = lessonOpen.value

  lessonOpen.value = null

  if (!lesson) {
    return
  }

  await perform(lesson)
  await load(range.value.from, range.value.until)
}

function replaceLesson(subject: string): void {
  changeLesson(lesson => replace(lesson, subject))
}

function page(direction: -1 | 1): void {
  const d = anchor.value

  if (view.value === 'month') {
    anchor.value = new Date(d.getFullYear(), d.getMonth() + direction, 15)

    return
  }

  const step = { week: 7, three: 3, day: 1, list: LIST_DAYS }[view.value] ?? 3

  anchor.value = new Date(d.getFullYear(), d.getMonth(), d.getDate() + direction * step)
}

/* Spelled out per view: German inflects "vorige" by gender. */
const PAGING = {
  month: ['calendar.page.monthBack', 'calendar.page.monthForward'],
  day: ['calendar.page.dayBack', 'calendar.page.dayForward'],
  week: ['calendar.page.weekBack', 'calendar.page.weekForward'],
  three: ['calendar.page.threeBack', 'calendar.page.threeForward'],
  list: ['calendar.page.weekBack', 'calendar.page.weekForward'],
} as const

function pageLabel(direction: -1 | 1): string {
  return t(PAGING[view.value][direction === -1 ? 0 : 1])
}

function today(): void {
  anchor.value = new Date()
}

// On a touch screen a swipe sideways turns the page; scrolling stays vertical.
let touchStart: { x: number, y: number } | null = null

function swipeStart(event: TouchEvent): void {
  const touch = event.touches[0]

  touchStart = touch ? { x: touch.clientX, y: touch.clientY } : null
}

function swipeEnd(event: TouchEvent): void {
  const touch = event.changedTouches[0]

  if (!touchStart || !touch || !narrow.value) {
    touchStart = null

    return
  }

  const dx = touch.clientX - touchStart.x
  const dy = touch.clientY - touchStart.y

  touchStart = null

  if (Math.abs(dx) > 70 && Math.abs(dx) > Math.abs(dy) * 1.5) {
    page(dx < 0 ? 1 : -1)
  }
}

/**
 * The calendar is always loaded, also in the other views: the notice banner
 * for expired connections and the list of people depend on it, and both
 * apply everywhere.
 *
 * Realtime names the areas that changed, and only those load: a tick used
 * to reload the whole agenda as well, twice (completion and stars).
 * Unnamed or unknown areas load everything.
 */
function refresh(areas: string[] = ['all']): Promise<unknown> {
  const known = ['agenda', 'photos', 'tasks', 'lists', 'points', 'meals', 'notifications']
  const changed = (area: string) => areas.includes(area) || areas.some(entry => !known.includes(entry))

  return Promise.all([
    // A photo sent to the wall arrives with the agenda (`shown_photo`).
    changed('agenda') || changed('photos') ? load(range.value.from, range.value.until) : null,
    changed('tasks') ? loadProgress(range.value.from, range.value.until) : null,
    // An account's messages; every phone of the household hears the area, each loads its own.
    !device.value && changed('notifications') ? loadNotifications() : null,
    loadArea(changed),
  ])
}

/*
 * What the shown area needs beyond the calendar. Switching areas loads only
 * this: the calendar stays current through realtime and the five-minute
 * cycle, and on a phone every extra request is a round trip.
 *
 * Not waiting for the agenda either — before it, the stars are asked for
 * regardless, so that they arrive alongside instead of after it.
 */
function loadArea(changed: (area: string) => boolean = () => true): Promise<unknown> {
  return Promise.all([
    // Extra stars and the day bonus are star entries, shown in the columns.
    mode.value === 'tasks' && (changed('tasks') || changed('points')) ? loadTasks(taskArea.value.from, taskArea.value.until) : null,
    mode.value === 'lists' && changed('lists') ? loadLists() : null,
    // The dashboard's star and reward tiles share this state.
    (mode.value === 'points' || mode.value === 'dashboard') && changed('points') && (agenda.value?.household.features.includes('points') ?? true) ? loadPoints() : null,
  ])
}

function startPaired(): void {
  refresh()
  loadTheme()

  if (!device.value) {
    checkPush()
  }

  // The menu's weather has no tile around it to refresh it.
  if (preferences.value.menu_top === 'weather') {
    loadWeather()
  }
}

/*
 * The preferences once at start, not on every sync: whoever later switches
 * to the tasks does not want to be back on the dashboard five minutes later.
 */
async function applyPreferences(): Promise<void> {
  await loadPreferences()

  mode.value = validMode((device.value ? null : storedMode()) ?? preferences.value.start_area as Mode, areas.value)
}

/*
 * An account remembers its area for the session: a phone that brings the
 * app back from the background lands where it was. A tablet always starts
 * in its start area — after a power cut the important things show at once.
 * Per household: the other family may have switched that area off.
 */
const MODE_KEY = computed(() => modeKey(user.value?.households?.find(entry => entry.current)?.id))

function storedMode(): Mode | null {
  try {
    return sessionStorage.getItem(MODE_KEY.value) as Mode | null
  }
  catch {
    // Blocked storage (private window): then the start area applies.
    return null
  }
}

watch(mode, (value) => {
  if (device.value) {
    return
  }

  try {
    sessionStorage.setItem(MODE_KEY.value, value)
  }
  catch {
    // Without storage the choice only lasts until the next reload.
  }
})

function freshlyPaired(): void {
  applyPreferences()
  startPaired()
}

/*
 * On the boundaries as text, not on the object: `range` computes with the
 * clock and is therefore recreated every minute — a watcher on the object
 * would reload the calendar every minute although nothing changed in the
 * range.
 *
 * On the range, not on view and anchor: as soon as the first response brings
 * the household's time zone, the range can change once more.
 */
watch(
  () => `${range.value.from}|${range.value.until}`,
  () => load(range.value.from, range.value.until),
  { immediate: true },
)
watch(mode, () => loadArea())

const { connect, disconnect } = useRealtime(areas => refresh(areas))

/*
 * The resting wall. After a while without a touch it turns dark and shows
 * the chosen areas in turn; the first touch only wakes it and returns to
 * where it was. Nothing is stored about it — a restart comes up awake.
 */
const restAreas = computed(() => carousel(preferences.value.screensaver_areas, householdAreas.value.map(entry => entry.value)))
let restTimer: ReturnType<typeof setTimeout> | null = null
let slideTimer: ReturnType<typeof setTimeout> | null = null
let awakeMode: Mode | null = null
let pageTimer: ReturnType<typeof setInterval> | null = null
let brightnessBefore: number | null = null

/*
 * The darkening chosen on the slider, shown before it applies: the veil
 * under the device dialog and, with Fully, the real brightness.
 */
const dimPreview = ref<string | null>(null)
let previewBefore: number | null = null

function previewDim(percent: string | null): void {
  dimPreview.value = percent

  if (percent === null) {
    restoreScreen(previewBefore)
    previewBefore = null

    return
  }

  previewBefore = previewScreen(percent, previewBefore)
}

/*
 * The timetable rests one child at a time: its slide lasts one interval per
 * child, and the shown child moves on with each interval. Awake again, the
 * child chosen before comes back.
 */
const { children: timetableChildren, selectedChild, load: loadTimetable } = useTimetable()
let awakeChild: number | null = null

function stopPages(): void {
  if (pageTimer !== null) {
    clearInterval(pageTimer)
    pageTimer = null
  }
}

function armRest(): void {
  if (restTimer !== null) {
    clearTimeout(restTimer)
    restTimer = null
  }

  // Only a tablet rests; an account has its own screen lock.
  if (device.value && !resting.value && restAreas.value.length > 0) {
    restTimer = setTimeout(rest, idleMs(preferences.value.screensaver_idle))
  }
}

const { open: clockTool, ringing } = useClockTools()
const { openDay: weatherDay, load: loadWeather, close: closeWeather } = useWeather()

// A timer that runs out wakes a resting wall: the alarm has to be seen.
watch(ringing, (now) => {
  if (now && resting.value) {
    wake()
  }
})

function rest(): void {
  // Someone left a dialog open: that is unfinished work, not idleness.
  if (pinGateOpen.value || themeOpen.value || dialog.value !== null || managingPhotos.value || viewingStatistics.value || lessonOpen.value !== null || clockTool.value !== null || cooking.value !== null) {
    armRest()

    return
  }

  /*
   * A glance, not unfinished work: the weather window closes rather than
   * standing in front of the resting wall. It lies outside the area view,
   * so the view's rebuild on rest does not take it along.
   */
  closeWeather()

  awakeMode = mode.value
  awakeChild = selectedChild.value
  anchor.value = new Date()
  resting.value = true
  brightnessBefore = dimScreen(restDim.value)
  void showSlide(0)
}

async function showSlide(index: number): Promise<void> {
  const list = restAreas.value

  stopPages()

  if (!resting.value || list.length === 0) {
    return
  }

  const position = index % list.length
  const area = list[position]!
  let pages = 1

  if (area === 'timetable') {
    if (timetableChildren.value.length === 0) {
      await loadTimetable()
    }

    const ids = timetableChildren.value.map(child => child.id)

    pages = ids.length
    selectedChild.value = ids[0] ?? null

    if (ids.length > 1) {
      let page = 0

      pageTimer = setInterval(() => {
        page = nextSlide(page, ids.length)
        selectedChild.value = ids[page] ?? null
      }, intervalMs(preferences.value.screensaver_interval))
    }
  }

  // Woken while the timetable was loading: nothing more to show.
  if (!resting.value) {
    return
  }

  mode.value = area
  slideTimer = setTimeout(
    () => void showSlide(nextSlide(position, list.length)),
    slideMs(area, preferences.value.screensaver_interval, photoIntervalMs(preferences.value.photo_interval), pages),
  )
}

function wake(): void {
  if (slideTimer !== null) {
    clearTimeout(slideTimer)
    slideTimer = null
  }

  stopPages()
  resting.value = false
  restoreScreen(brightnessBefore)
  brightnessBefore = null

  if (awakeMode !== null) {
    mode.value = awakeMode
    awakeMode = null
  }

  if (awakeChild !== null) {
    selectedChild.value = awakeChild
    awakeChild = null
  }

  armRest()
}

/*
 * Dark at rest whatever the theme says; afterwards back to the theme's
 * choice. Set directly, not through useTheme: the choice itself must not
 * change, and a theme alarm during rest is overruled again.
 */
function restTheme(): void {
  const theme = resting.value ? 'dark' : themeState.value?.theme

  if (theme) {
    document.documentElement.dataset.theme = theme
  }
}

watch(resting, restTheme)

/*
 * Day or night darkening, by the household's theme. Should the theme turn
 * while the wall rests, Fully's brightness follows — counted from the
 * brightness before resting, like the slider's preview.
 */
const restDim = computed(() => restingDim(
  preferences.value.screensaver_dim,
  preferences.value.screensaver_dim_night,
  themeState.value?.theme,
))

watch(restDim, (percent) => {
  if (resting.value && brightnessBefore !== null) {
    previewScreen(percent, brightnessBefore)
  }
})
watch(() => themeState.value?.theme, () => resting.value && restTheme())
watch(() => [preferences.value.screensaver_areas.join(','), preferences.value.screensaver_idle], armRest)

onMounted(() => {
  /*
   * Regular polling remains the fallback. The WebSocket shortens the wait to
   * seconds, but it must not be the only source: a kiosk that lost its
   * connection does not always notice. The theme hangs off it too, because a
   * sleeping tablet misses its alarm.
   */
  const fetchData = setInterval(startPaired, 5 * 60 * 1000)

  // The clock jumps on the full minute, not somewhere within the minute.
  let clock: ReturnType<typeof setTimeout>

  const tick = (): void => {
    now.value = new Date()
    clock = setTimeout(tick, 60_000 - (Date.now() % 60_000) + 50)
  }

  tick()

  // After the tablet wakes up the state is almost always stale.
  const onVisible = (): void => {
    if (document.visibilityState === 'visible') {
      startPaired()
    }
  }

  document.addEventListener('visibilitychange', onVisible)

  // Any touch counts as use and starts the wait for rest again.
  const onActivity = (): void => {
    if (!resting.value) {
      armRest()
    }
  }

  for (const kind of ['pointerdown', 'keydown', 'wheel'] as const) {
    window.addEventListener(kind, onActivity, { capture: true, passive: true })
  }

  armRest()
  applyPreferences()
  loadTheme()
  connect()

  // An account is asked about push once, and its menu may show the weather.
  if (!device.value) {
    checkPush()
    loadWeather()
  }

  onUnmounted(() => {
    clearInterval(fetchData)
    clearTimeout(clock)
    document.removeEventListener('visibilitychange', onVisible)

    for (const kind of ['pointerdown', 'keydown', 'wheel'] as const) {
      window.removeEventListener(kind, onActivity, { capture: true })
    }

    if (restTimer !== null) {
      clearTimeout(restTimer)
    }

    if (slideTimer !== null) {
      clearTimeout(slideTimer)
    }

    stopPages()
    endTheme()
    disconnect()
  })
})

/* The menu as a drawer from the left where the bar does not fit. */
const drawer = ref(false)

/*
 * Narrow, the areas may also sit along the bottom, one tap away. The burger
 * stays: settings and the admin live in the drawer.
 */
const bottomBar = computed(() => narrow.value && !resting.value && preferences.value.phone_menu === 'bottom')
useEscape(() => { drawer.value = false }, () => drawer.value)

watch(narrow, (now) => {
  if (!now) {
    drawer.value = false
  }
})

function go(value: Mode): void {
  mode.value = value
  drawer.value = false
}

const settingsStart = ref<'household' | null>(null)

function openSettings(): void {
  drawer.value = false
  settingsStart.value = null
  themeOpen.value = true
}

/* An account in several households sees which one it looks at, and taps to switch. */
const householdName = computed(() => (user.value?.households?.length ?? 0) > 1
  ? user.value?.households?.find(entry => entry.current)?.name ?? null
  : null)

function openHouseholds(): void {
  openSettings()
  settingsStart.value = 'household'
}

const me = computed(() => agenda.value?.people.find(person => person.id === user.value?.person_id) ?? null)

/*
 * A picture a phone sent to the walls. It wakes a resting wall — someone
 * stands beside it waiting — and goes on a tap or when its time is up. The
 * key it came with stays dismissed, so the next sync does not bring it back.
 */
const dismissedCast = ref<string | null>(null)
const cast = computed(() => {
  const shown = agenda.value?.shown_photo ?? null

  return device.value && shown && shown.key !== dismissedCast.value && new Date(shown.until) > now.value ? shown : null
})

watch(() => cast.value?.key, (key) => {
  if (key && resting.value) {
    wake()
  }
})

/* The library behind the picture frame, for whoever may add photos. */
const managingPhotos = ref(false)
useEscape(() => { managingPhotos.value = false }, () => managingPhotos.value)

/* The statistics for whoever runs the household; an account only, never the wall. */
const viewingStatistics = ref(false)
useEscape(() => { viewingStatistics.value = false }, () => viewingStatistics.value)

function openStatistics(): void {
  drawer.value = false
  viewingStatistics.value = true
}

async function leave(): Promise<void> {
  // Before signing out: afterwards the cookie that authorized the channel
  // is gone, and the connection would keep running into nothing.
  disconnect()
  endTheme()
  themeOpen.value = false

  await logout()
}

const loading = ref(false)

async function reload(): Promise<void> {
  loading.value = true

  try {
    await refresh()
  }
  finally {
    loading.value = false
  }
}

function completeTask(entry: OccurrenceDto, personId: number | null): void {
  toggleTask(entry, personId)
}

function createItem(listId: number, titleText: string): void {
  create(listId, titleText, null)
}

function completeItem(item: ListItemDto): void {
  toggleItem(item)
}
</script>

<template>
  <div class="h-full bg-canvas text-ink">
    <!-- Over everything, catching no taps: the cheer after a tick. -->
    <TwCheer />

    <PinDialog
      v-if="pinGateOpen"
      @unlocked="settlePinGate(true)"
      @close="settlePinGate(false)"
    />

    <ReadOnlyNotice />

    <PairingScreen
      v-if="paired === false && device"
      @paired="freshlyPaired"
    />

    <AppLoading v-else-if="paired !== true" />

    <div v-else class="flex h-full">
      <!-- Catches the waking touch, so it does not also press what lies below,
           and veils the page: a browser cannot dim the screen itself. -->
      <button
        v-if="resting"
        type="button"
        data-testid="screensaver"
        :aria-label="$t('screensaver.end')"
        class="fixed inset-0 z-50 cursor-default bg-black"
        :style="{ opacity: veilOpacity(restDim) }"
        @click="wake"
      />

      <nav
        v-if="!resting && !narrow"
        :aria-label="$t('common.areas')"
        class="flex w-28 shrink-0 flex-col gap-1 border-r border-line bg-surface px-2 pt-4 pb-2"
      >
        <div class="mb-4 flex shrink-0 justify-center">
          <WeatherNow v-if="preferences.menu_top === 'weather'" />
          <TwLogo v-else class="size-12" />
        </div>

        <button
          v-if="!device && householdName"
          type="button"
          data-testid="menu-household"
          class="tw-press -mt-2 mb-2 flex min-h-11 shrink-0 items-center justify-center gap-0.5 rounded-xl px-1 text-xs font-semibold text-ink-2 transition-colors hover:bg-surface-2 hover:text-ink"
          @click="openHouseholds"
        >
          <span class="truncate">{{ householdName }}</span>
          <TwIcon name="down" :size="14" class="shrink-0" />
        </button>

        <!-- Only the areas scroll; logo and device button stay in reach. -->
        <div class="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto">
          <button
            v-for="entry in areas"
            :key="entry.value"
            type="button"
            data-testid="mode"
            :aria-current="mode === entry.value ? 'page' : undefined"
            class="tw-press flex min-h-20 flex-col items-center justify-center gap-1.5 rounded-2xl px-1 text-sm font-semibold transition-colors"
            :class="mode === entry.value
              ? 'bg-accent-soft text-accent'
              : 'text-ink-2 hover:bg-surface-2 hover:text-ink'"
            @click="mode = entry.value"
          >
            <span
              v-if="coloredIcons && areaEmoji(entry.icon)"
              aria-hidden="true"
              data-testid="mode-emoji"
              class="text-[28px] leading-none"
              :class="mode === entry.value ? 'tw-pop' : ''"
            ><TwEmoji :icon="areaEmoji(entry.icon) ?? ''" colored /></span>
            <TwIcon v-else :name="entry.icon" :size="28" />
            {{ entry.label }}
          </button>
        </div>

        <button
          v-if="!device && can('household.manage')"
          type="button"
          data-testid="statistics-link"
          :aria-label="$t('menu.statistics')"
          class="mx-auto mt-2 flex size-12 shrink-0 items-center justify-center rounded-full text-ink-3 transition-colors hover:bg-surface-2 hover:text-ink"
          @click="openStatistics"
        >
          <TwIcon name="statistics" :size="22" />
        </button>

        <!-- The admin under the same sign-in; it leads back with "Zur App". -->
        <a
          v-if="!device && can('household.manage')"
          href="/admin"
          data-testid="admin-link"
          :aria-label="$t('menu.admin')"
          class="mx-auto mt-2 flex size-12 shrink-0 items-center justify-center rounded-full text-ink-3 transition-colors hover:bg-surface-2 hover:text-ink"
        >
          <TwIcon name="admin" :size="22" />
        </a>

        <button
          type="button"
          data-testid="theme"
          aria-haspopup="dialog"
          :aria-expanded="themeOpen"
          :aria-label="$t('device.title')"
          class="mx-auto mt-2 flex size-12 shrink-0 items-center justify-center rounded-full transition-colors"
          :class="themeOpen ? 'bg-surface-2 text-ink' : 'text-ink-3 hover:bg-surface-2 hover:text-ink'"
          @click="settingsStart = null; themeOpen = !themeOpen"
        >
          <!-- Small on purpose: set up once, then rarely touched. -->
          <TwIcon name="settings" :size="22" />
        </button>
      </nav>

      <div class="flex min-w-0 flex-1 flex-col">
        <HouseholdNoticeBar
          v-if="!resting && !bleed"
          :notices="device ? agenda?.household.notices : user?.notices"
          :device="device"
          :today="todayIso"
        />

        <!-- Where the bar does not fit: menu, area and refresh in one row. -->
        <header
          v-if="narrow && !bleed"
          class="flex shrink-0 items-center justify-between gap-2 px-3 pt-[calc(env(safe-area-inset-top)+0.75rem)] pb-2"
        >
          <button
            type="button"
            data-testid="menu-open"
            :aria-label="$t('menu.open')"
            aria-haspopup="dialog"
            :aria-expanded="drawer"
            class="flex size-11 shrink-0 items-center justify-center rounded-full text-ink-2 transition-colors hover:bg-surface-2"
            @click="drawer = true"
          >
            <TwIcon name="burger" :size="24" />
          </button>

          <h1 class="min-w-0 flex-1 truncate font-display text-3xl font-semibold">
            {{ title }}
          </h1>

          <span
            v-if="stale && standText"
            role="status"
            class="shrink-0 rounded-full bg-warning px-3 py-1 text-sm font-semibold text-warning-ink"
          >
            {{ $t('common.asOf', { time: standText }) }}
          </span>

          <NotificationBell v-if="!device" />

          <button
            type="button"
            :aria-label="$t('common.refresh')"
            class="-mr-2 flex size-11 shrink-0 items-center justify-center rounded-full text-ink-2 transition-colors hover:bg-surface-2 disabled:opacity-40"
            :disabled="loading"
            @click="reload"
          >
            <TwIcon name="refresh" :size="22" :class="loading ? 'animate-spin' : ''" />
          </button>
        </header>

        <header
          v-else-if="!bleed"
          class="flex shrink-0 flex-wrap items-end justify-between gap-x-6 gap-y-3 px-8"
          :class="mode === 'photos' ? 'pt-3 pb-3' : 'pt-5 pb-4'"
        >
          <div class="min-w-0">
            <p class="text-base font-medium text-ink-3">
              {{ dateText }}
            </p>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
              <!-- On the dashboard only for screen readers: the tiles say what this is, and the line costs grid space. -->
              <h1
                class="font-display text-4xl leading-tight font-semibold capitalize"
                :class="mode === 'dashboard' ? 'sr-only' : ''"
              >
                {{ title }}
              </h1>

              <span
                v-if="stale && standText"
                role="status"
                class="rounded-full bg-warning px-3 py-1 text-sm font-semibold text-warning-ink"
              >
                {{ $t('common.asOf', { time: standText }) }}
              </span>

              <!-- On the first load without a network there is no state that could
                   be shown. Without this notice the wall would look empty
                   and yet seemingly fine. -->
              <button
                v-else-if="stale && !agenda"
                type="button"
                role="status"
                class="flex min-h-10 items-center gap-2 rounded-full bg-warning px-4 text-sm font-semibold text-warning-ink"
                @click="startPaired"
              >
                <TwIcon name="refresh" :size="16" />
                {{ $t('common.offlineRetry') }}
              </button>
            </div>
          </div>

          <div class="flex items-center gap-3">
            <NotificationBell v-if="!device" />

            <CalendarToolbar
              v-if="mode === 'calendar' && !resting"
              :view="view"
              :views="views"
              :back-label="pageLabel(-1)"
              :forward-label="pageLabel(1)"
              :band-available="bandAvailable"
              :band-on="bandOn"
              :can-create="can('calendar.edit_own')"
              @page="page"
              @today="today"
              @view="view = $event"
              @create="newEvent(todayIso)"
              @band="bandOn = !bandOn"
            />

            <time
              class="ml-3 font-display text-2xl leading-none font-medium tabular-nums"
              :datetime="now.toISOString()"
            >
              {{ clockTime }}
            </time>
          </div>
        </header>

        <main
          :key="resting ? `rest-${mode}` : 'awake'"
          class="min-h-0 flex-1"
          :class="[
            resting ? 'rest-fade' : '',
            bleed ? '' : bottomBar ? 'overflow-y-auto px-3 pb-3' : narrow ? 'overflow-y-auto px-3 pb-[calc(env(safe-area-inset-bottom)+0.75rem)]' : mode === 'photos' ? 'px-4 pb-4' : 'px-8 pb-6',
          ]"
        >
          <div v-if="mode === 'calendar'" data-testid="calendar" class="flex h-full flex-col gap-3">
            <CalendarToolbar
              v-if="narrow"
              compact
              :view="view"
              :views="views"
              :back-label="pageLabel(-1)"
              :forward-label="pageLabel(1)"
              :band-available="bandAvailable"
              :band-on="bandOn"
              :can-create="can('calendar.edit_own')"
              :filtered="peopleFilter.length > 0"
              @page="page"
              @today="today"
              @view="view = $event"
              @create="newEvent(todayIso)"
              @band="bandOn = !bandOn"
            >
              <div v-if="(agenda?.people ?? []).length > 0">
                <div class="mb-2 flex items-center justify-between gap-3">
                  <span class="text-base font-semibold text-ink-2">{{ $t('tasks.filters.people') }}</span>
                  <button
                    v-if="peopleFilter.length > 0"
                    type="button"
                    data-testid="people-all"
                    class="min-h-11 rounded-xl px-3 text-base font-semibold text-accent"
                    @click="peopleFilter = []"
                  >
                    {{ $t('tasks.filters.showAll') }}
                  </button>
                </div>

                <PeopleBar
                  :people="agenda?.people ?? []"
                  :selection="peopleFilter"
                  :progress="progress"
                  :family="familyEvents"
                  @toggle="togglePerson"
                />
              </div>
            </CalendarToolbar>

            <PeopleBar
              v-if="!narrow"
              :people="agenda?.people ?? []"
              :selection="peopleFilter"
              :progress="progress"
              :family="familyEvents"
              @toggle="togglePerson"
            />

            <div
              class="min-h-0 flex-1 overflow-hidden rounded-3xl border border-line bg-surface shadow-card"
              @touchstart.passive="swipeStart"
              @touchend.passive="swipeEnd"
            >
              <CalendarList
                v-if="view === 'list'"
                :days="days"
                :people="agenda?.people ?? []"
                :time-zone="zone"
                :now="now"
                @pick-event="openEvent"
              />
              <MonthView
                v-else-if="view === 'month'"
                :days="days"
                :time-zone="zone"
                :lessons="schoolLessons"
                @pick-day="newEvent"
                @pick-event="openEvent"
              />
              <TimeGrid
                v-else
                :key="view"
                :days="days"
                :columns="columns"
                :time-zone="zone"
                :now="now"
                :lessons="schoolLessons"
                :people="agenda?.people ?? []"
                :hour-height="narrow ? 56 : undefined"
                :follow="narrow"
                @pick-day="newEvent"
                @pick-event="openEvent"
                @pick-lesson="lessonOpen = $event"
              />
            </div>
          </div>

          <TasksView
            v-else-if="mode === 'tasks'"
            :occurrences="occurrences"
            :people="agenda?.people ?? []"
            :today="todayIso"
            :error="tasksError"
            :points="agenda?.household.features.includes('points') ?? false"
            @toggle="completeTask"
          />

          <TwLoading v-else-if="mode === 'lists' && !listsLoaded && !listsError" />

          <ListsBoard
            v-else-if="mode === 'lists'"
            :lists="lists"
            :error="listsError"
            @add="createItem"
            @toggle="completeItem"
            @remove="remove"
            @clear="cleanUp"
          />

          <PointsView v-else-if="mode === 'points'" />

          <RecipesView v-else-if="mode === 'recipes'" :today="todayIso" />

          <TimetableView v-else-if="mode === 'timetable'" />

          <DashboardView
            v-else-if="mode === 'dashboard'"
            :time-zone="zone"
            :today="todayIso"
            @pick-event="openEvent"
            @show-tasks="showTasks"
          />

          <div v-else-if="mode === 'photos'" class="relative h-full">
            <PhotosView :bleed="bleed" :clock="clockTime" :date="dateText" :upcoming="frameEvents" />

            <button
              v-if="!resting && can('photos.upload')"
              type="button"
              data-testid="photos-manage"
              class="absolute top-3 right-3 z-10 flex min-h-11 items-center gap-2 rounded-full bg-surface px-4 text-base font-semibold shadow-floating"
              @click="managingPhotos = true"
            >
              <TwIcon name="photo" :size="20" />
              {{ $t('photos.manage') }}
            </button>
          </div>

          <!-- A plugin's area: its layer registered the view (useAreaViews). -->
          <component
            :is="pluginView"
            v-else-if="pluginView"
            :today="todayIso"
            :time-zone="zone"
          />

          <MealsView v-else :today="todayIso" @recipe="cooking = $event" />
        </main>

        <!-- `relative`: with many areas the row scrolls sideways inside itself. -->
        <nav
          v-if="bottomBar"
          data-testid="bottom-menu"
          :aria-label="$t('common.areas')"
          class="relative flex shrink-0 overflow-x-auto border-t border-line bg-surface px-1 pt-1 pb-[calc(env(safe-area-inset-bottom)+0.25rem)]"
        >
          <button
            v-for="entry in areas"
            :key="entry.value"
            type="button"
            data-testid="bottom-tab"
            :data-value="entry.value"
            :aria-current="mode === entry.value ? 'page' : undefined"
            class="tw-press flex min-h-14 min-w-16 flex-1 flex-col items-center justify-center gap-0.5 rounded-xl px-1 text-xs font-semibold transition-colors"
            :class="mode === entry.value ? 'text-accent' : 'text-ink-3 hover:text-ink'"
            @click="go(entry.value)"
          >
            <span
              v-if="coloredIcons && areaEmoji(entry.icon)"
              aria-hidden="true"
              class="text-[22px] leading-none"
              :class="mode === entry.value ? 'tw-pop' : ''"
            ><TwEmoji :icon="areaEmoji(entry.icon) ?? ''" colored /></span>
            <TwIcon v-else :name="entry.icon" :size="22" />
            <span class="max-w-full truncate">{{ entry.label }}</span>
          </button>
        </nav>

        <PlannedRecipe v-if="cooking" :meal="cooking" @close="cooking = null" />
      </div>

      <!-- The menu where the bar does not fit: drawn like the bar, sliding in from the left. -->
      <Transition
        enter-from-class="opacity-0"
        leave-to-class="opacity-0"
        enter-active-class="transition-opacity duration-200"
        leave-active-class="transition-opacity duration-200"
      >
        <div
          v-if="drawer"
          data-testid="menu-scrim"
          class="fixed inset-0 z-20 bg-scrim"
          @click="drawer = false"
        />
      </Transition>

      <Transition
        enter-from-class="-translate-x-full"
        leave-to-class="-translate-x-full"
        enter-active-class="transition-transform duration-200 ease-out"
        leave-active-class="transition-transform duration-200 ease-in"
      >
        <nav
          v-if="drawer"
          data-testid="menu"
          :aria-label="$t('common.areas')"
          class="fixed inset-y-0 left-0 z-30 flex w-72 max-w-[85vw] flex-col gap-1 border-r border-line bg-surface px-3 pt-[calc(env(safe-area-inset-top)+1rem)] pb-[calc(env(safe-area-inset-bottom)+0.5rem)] shadow-floating"
        >
          <div class="mb-4 flex shrink-0 items-start justify-between gap-2">
            <div class="w-28">
              <WeatherNow v-if="preferences.menu_top === 'weather'" />
              <TwLogo v-else class="ml-2 size-12" />
            </div>

            <button
              v-if="!device && householdName"
              type="button"
              data-testid="menu-household"
              class="tw-press flex min-h-11 min-w-0 flex-1 items-center gap-1 self-center rounded-full px-3 text-sm font-semibold text-ink-2 transition-colors hover:bg-surface-2 hover:text-ink"
              @click="openHouseholds"
            >
              <span class="truncate">{{ householdName }}</span>
              <TwIcon name="down" :size="16" class="shrink-0" />
            </button>

            <button
              type="button"
              :aria-label="$t('menu.close')"
              class="flex size-11 items-center justify-center rounded-full text-ink-2 transition-colors hover:bg-surface-2"
              @click="drawer = false"
            >
              <TwIcon name="close" :size="22" />
            </button>
          </div>

          <!-- Only the areas scroll; weather and settings stay in reach. -->
          <div class="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto">
            <button
              v-for="entry in areas"
              :key="entry.value"
              type="button"
              data-testid="tab"
              :data-value="entry.value"
              :aria-current="mode === entry.value ? 'page' : undefined"
              class="tw-press flex min-h-14 items-center gap-4 rounded-2xl px-4 text-lg font-semibold transition-colors"
              :class="mode === entry.value
                ? 'bg-accent-soft text-accent'
                : 'text-ink-2 hover:bg-surface-2 hover:text-ink'"
              @click="go(entry.value)"
            >
              <span
                v-if="coloredIcons && areaEmoji(entry.icon)"
                aria-hidden="true"
                data-testid="tab-emoji"
                class="w-7 text-center text-[26px] leading-none"
                :class="mode === entry.value ? 'tw-pop' : ''"
              ><TwEmoji :icon="areaEmoji(entry.icon) ?? ''" colored /></span>
              <TwIcon v-else :name="entry.icon" :size="26" />
              {{ entry.label }}
            </button>
          </div>

          <button
            v-if="!device && can('household.manage')"
            type="button"
            data-testid="statistics-link"
            class="mt-2 flex min-h-12 shrink-0 items-center gap-3 rounded-2xl px-3 text-left text-base font-semibold text-ink-2 transition-colors hover:bg-surface-2 hover:text-ink"
            @click="openStatistics"
          >
            <TwIcon name="statistics" :size="22" class="text-ink-3" />
            {{ $t('menu.statistics') }}
          </button>

          <a
            v-if="!device && can('household.manage')"
            href="/admin"
            data-testid="admin-link"
            class="mt-2 flex min-h-12 shrink-0 items-center gap-3 rounded-2xl px-3 text-base font-semibold text-ink-2 transition-colors hover:bg-surface-2 hover:text-ink"
          >
            <TwIcon name="admin" :size="22" class="text-ink-3" />
            {{ $t('menu.admin') }}
          </a>

          <button
            type="button"
            data-testid="settings"
            :aria-label="$t('settings.title')"
            aria-haspopup="dialog"
            class="mt-2 flex min-h-14 shrink-0 items-center gap-3 rounded-2xl px-3 text-left transition-colors hover:bg-surface-2"
            @click="openSettings"
          >
            <TwIcon name="settings" :size="22" class="text-ink-3" />
            <span class="min-w-0 flex-1">
              <span class="block truncate text-base font-semibold">{{ device ? $t('device.title') : user?.name }}</span>
              <span class="block text-sm text-ink-3">{{ $t('settings.title') }}</span>
            </span>
          </button>
        </nav>
      </Transition>

      <!-- Over the frame, not instead of it: the library is a task, the frame what the area is. -->
      <div
        v-if="managingPhotos"
        role="dialog"
        aria-modal="true"
        :aria-label="$t('photos.manage')"
        data-testid="photo-manager"
        class="fixed inset-0 z-30 flex flex-col bg-canvas"
      >
        <div class="flex shrink-0 items-center gap-2 px-3 pt-[calc(env(safe-area-inset-top)+0.75rem)] pb-2">
          <button
            type="button"
            data-testid="photo-manager-close"
            :aria-label="$t('common.back')"
            class="flex size-11 items-center justify-center rounded-full text-ink-2 hover:bg-surface-2"
            @click="managingPhotos = false"
          >
            <TwIcon name="back" :size="24" />
          </button>
          <h2 class="font-display text-3xl font-semibold">
            {{ $t('photos.manage') }}
          </h2>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto">
          <PhotoManager class="mx-auto max-w-5xl" />
        </div>
      </div>

      <div
        v-if="viewingStatistics"
        role="dialog"
        aria-modal="true"
        :aria-label="$t('statistics.title')"
        data-testid="statistics-view"
        class="fixed inset-0 z-30 flex flex-col bg-canvas"
      >
        <div class="flex shrink-0 items-center gap-2 px-3 pt-[calc(env(safe-area-inset-top)+0.75rem)] pb-2">
          <button
            type="button"
            data-testid="statistics-close"
            :aria-label="$t('common.back')"
            class="flex size-11 items-center justify-center rounded-full text-ink-2 hover:bg-surface-2"
            @click="viewingStatistics = false"
          >
            <TwIcon name="back" :size="24" />
          </button>
          <h2 class="font-display text-3xl font-semibold">
            {{ $t('statistics.title') }}
          </h2>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto">
          <StatisticsView class="mx-auto max-w-5xl" />
        </div>
      </div>

      <div
        v-if="themeOpen"
        class="fixed inset-0 z-40"
        :class="narrow ? 'flex items-end bg-scrim' : ''"
        @click.self="themeOpen = false"
      >
        <div
          v-if="dimPreview !== null"
          data-testid="dim-preview"
          class="pointer-events-none fixed inset-0 bg-black"
          :style="{ opacity: veilOpacity(dimPreview) }"
        />

        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="settings-title"
          data-testid="settings-sheet"
          :class="narrow
            ? 'max-h-[90vh] w-full overflow-y-auto rounded-t-[2rem] bg-canvas px-4 pt-5 pb-[calc(1.5rem+env(safe-area-inset-bottom))] shadow-floating'
            : 'absolute bottom-4 left-32 max-h-[calc(100vh-2rem)] w-[32rem] max-w-[calc(100vw-9rem)] overflow-y-auto rounded-3xl border border-line bg-canvas p-6 shadow-floating'"
        >
          <SettingsSheet
            :preferences="preferences"
            :time-zone="zone"
            :areas="areas"
            :menu="menuAreas"
            :band-available="bandAvailable"
            :device="device"
            :person="me"
            :start-page="settingsStart"
            @set="choosePreference"
            @menu="arrangeMenu"
            @rest="setPreference('screensaver_areas', $event)"
            @albums="setPreference('photo_albums', $event)"
            @preview="previewDim"
            @leave="leave"
            @calendars="refresh"
          />
        </div>
      </div>

      <ClockToolsDialog v-if="clockTool !== null" />
      <WeatherDialog v-if="weatherDay !== null" />

      <EventDialog
        v-if="dialog && agenda"
        :event="dialog.event"
        :day="dialog.day"
        :calendars="agenda.calendars"
        :people="agenda.people"
        :time-zone="zone"
        @close="dialog = null"
        @saved="saved"
      />

      <EventSheet
        v-if="reading"
        :event="reading"
        :people="agenda?.people ?? []"
        :time-zone="zone"
        @close="reading = null"
        @edit="editEvent(reading)"
      />

      <!-- One question at a time: installing waits for the push offer. Not at
           rest: the resting wall shows nothing to tap. -->
      <PushOffer v-if="!device && pushOffered && !themeOpen" />
      <InstallOffer v-else-if="installOffered && !resting && !themeOpen" />

      <!-- Sent from a phone: over everything, one tap and it is gone. -->
      <Transition name="cast">
        <button
          v-if="cast"
          :key="cast.key"
          type="button"
          data-testid="photo-cast"
          :aria-label="$t('photos.cast.close')"
          class="fixed inset-0 z-50 bg-black text-white"
          @click="dismissedCast = cast.key"
        >
          <PhotoPicture :url="cast.photo.url" :alt="cast.photo.caption ?? $t('photos.photo')" />
          <span
            v-if="cast.photo.caption"
            class="absolute inset-x-0 bottom-0 bg-linear-to-t from-black/60 to-transparent px-10 pt-16 pb-8 text-left text-2xl font-medium"
          >
            {{ cast.photo.caption }}
          </span>
        </button>
      </Transition>

      <LessonExceptionDialog
        v-if="lessonOpen"
        :lesson="lessonOpen"
        @close="lessonOpen = null"
        @cancel="changeLesson(cancel)"
        @replace="replaceLesson"
        @revert="changeLesson(revert)"
      />
    </div>
  </div>
</template>

<style>
/* Each area of the resting carousel fades in instead of jumping. */
.rest-fade {
  animation: rest-fade 0.8s ease-out;
}

@keyframes rest-fade {
  from { opacity: 0; }
  to { opacity: 1; }
}

/* A picture from a phone grows in like a shared photo. */
.cast-enter-active,
.cast-leave-active {
  transition: opacity 0.5s ease, transform 0.5s ease;
}

.cast-enter-from,
.cast-leave-to {
  opacity: 0;
  transform: scale(0.96);
}

:root[data-motion="off"] .rest-fade {
  animation: none;
}
</style>
