<script setup lang="ts">
/*
 * The statistics for parents on the phone: who did which task how often.
 * The same numbers as the admin's page (`GET /api/statistics`), drawn
 * without a chart library — stacked columns and bars are plain boxes, and
 * the task table carries every number, so nothing hides behind a hover a
 * phone does not have.
 */
import { weekdayName } from '../../../shared/app/utils/timetable'
import {
  NOBODY,
  busiest,
  heat,
  labelStep,
  rate,
  seriesOrder,
  stacks,
  type StatisticsResponse,
} from '../utils/statistics'

type Period = '28' | '91' | '365' | 'year'

const { t } = useI18n()

const period = ref<Period>('28')
const data = ref<StatisticsResponse | null>(null)
const failed = ref(false)
// A later answer wins: switching quickly must not show the older span.
let generation = 0

async function load(): Promise<void> {
  const mine = ++generation
  failed.value = false

  try {
    const response = await $fetch<StatisticsResponse>('/api/statistics', {
      baseURL: '/',
      params: { period: period.value },
      credentials: 'same-origin',
    })

    if (mine === generation) {
      data.value = response
    }
  }
  catch {
    if (mine === generation) {
      failed.value = true
    }
  }
}

watch(period, load, { immediate: true })

const periods = computed(() => [
  { value: '28' as Period, label: t('statistics.weeks4') },
  { value: '91' as Period, label: t('statistics.months3') },
  { value: '365' as Period, label: t('statistics.months12') },
  { value: 'year' as Period, label: t('statistics.year') },
])

const people = computed(() => data.value?.people ?? [])
const nameOf = (key: string): string => key === NOBODY
  ? t('statistics.nobody')
  : people.value.find(person => String(person.id) === key)?.name ?? ''

const timelineOrder = computed(() => seriesOrder(people.value, Object.keys(data.value?.timeline.series ?? {}), t('statistics.nobody')))
const timeline = computed(() => stacks(data.value?.timeline.series ?? {}, timelineOrder.value))
const timelineStep = computed(() => labelStep(timeline.value.length, 7))

const weekdayOrder = computed(() => seriesOrder(people.value, Object.keys(data.value?.weekdays ?? {}), t('statistics.nobody')))
const weekdays = computed(() => stacks(data.value?.weekdays ?? {}, weekdayOrder.value))
const weekdayLabels = computed(() => [1, 2, 3, 4, 5, 6, 7].map(day => weekdayName(day, 'short')))

const tableColumns = computed(() => {
  const keys = new Set<string>()
  for (const task of data.value?.tasks ?? []) {
    Object.keys(task.counts).forEach(key => keys.add(key))
  }

  return seriesOrder(people.value, keys, t('statistics.nobody'))
})
const tableMax = computed(() => Math.max(1, ...(data.value?.tasks ?? []).flatMap(task => Object.values(task.counts))))

const reliability = computed(() => seriesOrder(people.value, Object.keys(data.value?.reliability ?? {}), '')
  .map((entry) => {
    const counts = data.value?.reliability[entry.key] ?? { due: 0, done: 0 }

    return { ...entry, ...counts, rate: rate(counts.due, counts.done) ?? 0 }
  }))

const stars = computed(() => {
  const rows = seriesOrder(people.value, Object.keys(data.value?.stars ?? {}), '')
    .map(entry => ({ ...entry, ...(data.value?.stars[entry.key] ?? { earned: 0, spent: 0 }) }))
  const max = Math.max(1, ...rows.flatMap(row => [row.earned, row.spent]))

  return rows.map(row => ({ ...row, earnedShare: row.earned / max * 100, spentShare: row.spent / max * 100 }))
})

const totals = computed(() => data.value?.totals ?? { done: 0, due: 0, kept: 0, earned: 0, spent: 0 })
const ownRate = computed(() => rate(totals.value.due, totals.value.kept))
const best = computed(() => busiest(data.value?.tasks ?? []))

const perStep = computed(() => ({
  day: t('statistics.perDay'),
  week: t('statistics.perWeek'),
  month: t('statistics.perMonth'),
}[data.value?.period.bucket ?? 'day']))
</script>

<template>
  <div class="space-y-4 px-4 pb-[calc(env(safe-area-inset-bottom)+1.5rem)]" data-testid="statistics">
    <div>
      <h3 id="statistics-period" class="sr-only">
        {{ $t('statistics.period') }}
      </h3>
      <ChoiceChips
        v-model="period"
        :options="periods"
        labelled-by="statistics-period"
        test-id="statistics-period"
      />
    </div>

    <TwLoading v-if="data === null && !failed" />

    <p v-else-if="data === null" class="rounded-2xl bg-surface p-5 text-ink-2 shadow-card">
      {{ $t('statistics.failed') }}
      <button type="button" class="ml-2 min-h-11 font-semibold text-accent" @click="load">
        {{ $t('common.retry') }}
      </button>
    </p>

    <template v-else>
      <!-- Four numbers before any chart. -->
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-2xl bg-surface p-4 shadow-card" data-testid="statistics-done">
          <p class="text-sm text-ink-3">{{ $t('statistics.done') }}</p>
          <p class="mt-1 text-3xl font-semibold tabular-nums">{{ totals.done }}</p>
          <p class="text-sm text-ink-3">{{ $t('statistics.days', { count: data.period.days }, data.period.days) }}</p>
        </div>
        <div class="rounded-2xl bg-surface p-4 shadow-card">
          <p class="text-sm text-ink-3">{{ $t('statistics.own') }}</p>
          <p class="mt-1 text-3xl font-semibold tabular-nums">{{ ownRate === null ? '–' : `${ownRate} %` }}</p>
          <p class="text-sm text-ink-3">
            {{ ownRate === null ? $t('statistics.nothingDue') : $t('statistics.ofDue', { done: totals.kept, due: totals.due }) }}
          </p>
        </div>
        <div class="rounded-2xl bg-surface p-4 shadow-card">
          <p class="text-sm text-ink-3">{{ $t('statistics.earned') }}</p>
          <p class="mt-1 flex items-center gap-1.5 text-3xl font-semibold tabular-nums">
            {{ totals.earned }} <TwStar />
          </p>
          <p class="text-sm text-ink-3">{{ $t('statistics.spentCount', { count: totals.spent }) }}</p>
        </div>
        <div class="rounded-2xl bg-surface p-4 shadow-card">
          <p class="text-sm text-ink-3">{{ $t('statistics.busiest') }}</p>
          <p class="mt-1 truncate text-3xl font-semibold">{{ best === null ? '–' : nameOf(best.key) }}</p>
          <p class="text-sm text-ink-3">
            {{ best === null ? $t('statistics.nobodyYet') : $t('statistics.tasksCount', { count: best.count }, best.count) }}
          </p>
        </div>
      </div>

      <p v-if="data.tasks.length === 0" class="rounded-2xl bg-surface p-5 text-ink-2 shadow-card" data-testid="statistics-empty">
        {{ $t('statistics.empty') }}
      </p>

      <template v-else>
        <!-- Which task, by whom: the table holds every number. -->
        <section class="rounded-2xl bg-surface p-4 shadow-card" data-testid="statistics-matrix">
          <h3 class="font-display text-xl font-semibold">{{ $t('statistics.matrix') }}</h3>
          <p class="text-sm text-ink-3">{{ $t('statistics.matrixHint') }}</p>

          <div class="relative mt-3 overflow-x-auto">
            <table class="w-full border-separate border-spacing-0.5 text-sm">
              <thead>
                <tr class="text-xs text-ink-3">
                  <th scope="col" class="px-2 py-1.5 text-left font-medium">{{ $t('statistics.task') }}</th>
                  <th v-for="column in tableColumns" :key="column.key" scope="col" class="px-2 py-1.5 font-medium whitespace-nowrap">
                    <span class="inline-flex items-center gap-1.5">
                      <span class="size-2.5 rounded-full" :style="{ background: column.color }" />{{ column.name }}
                    </span>
                  </th>
                  <th scope="col" class="px-2 py-1.5 font-medium">{{ $t('statistics.total') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="task in data.tasks" :key="task.id">
                  <th scope="row" class="max-w-48 truncate px-2 py-1.5 text-left font-medium">
                    <TwEmoji v-if="task.icon" :icon="task.icon" class="mr-1 inline-block size-5 align-[-4px]" />{{ task.title }}
                  </th>
                  <td
                    v-for="column in tableColumns"
                    :key="column.key"
                    class="min-w-14 rounded-md px-2 py-1.5 text-center tabular-nums"
                    :class="heat(task.counts[column.key] ?? 0, tableMax) > 55 ? 'font-semibold text-accent-ink' : 'text-ink-2'"
                    :style="{ background: `color-mix(in oklab, var(--accent) ${heat(task.counts[column.key] ?? 0, tableMax)}%, transparent)` }"
                    :title="$t('statistics.cell', { person: column.name, count: task.counts[column.key] ?? 0, task: task.title })"
                  >
                    {{ task.counts[column.key] ?? '·' }}
                  </td>
                  <td class="px-2 py-1.5 text-center font-semibold tabular-nums">{{ task.total }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
          <!-- Done over time, stacked by person. -->
          <section class="rounded-2xl bg-surface p-4 shadow-card lg:col-span-2" data-testid="statistics-timeline">
            <h3 class="font-display text-xl font-semibold">{{ perStep }}</h3>
            <div class="mt-3 flex h-44 items-end gap-0.5">
              <div
                v-for="(column, index) in timeline"
                :key="index"
                class="flex h-full min-w-0 flex-1 flex-col justify-end"
                role="img"
                :aria-label="$t('statistics.column', { label: data.timeline.labels[index] ?? '', count: column.total })"
                :title="$t('statistics.column', { label: data.timeline.labels[index] ?? '', count: column.total })"
              >
                <div
                  v-for="segment in [...column.segments].reverse()"
                  :key="segment.key"
                  class="w-full border-t-2 border-surface first:rounded-t"
                  :style="{ height: `${segment.share}%`, background: segment.color }"
                />
              </div>
            </div>
            <div class="mt-1 flex gap-0.5 text-[11px] text-ink-3">
              <span v-for="(label, index) in data.timeline.labels" :key="index" class="min-w-0 flex-1 overflow-visible whitespace-nowrap">
                {{ index % timelineStep === 0 ? label : '' }}
              </span>
            </div>
            <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-ink-2">
              <li v-for="entry in timelineOrder" :key="entry.key" class="inline-flex items-center gap-1.5">
                <span class="size-2.5 rounded-full" :style="{ background: entry.color }" />{{ entry.name }}
              </li>
            </ul>
          </section>

          <!-- Of their own tasks, how many got done. -->
          <section class="rounded-2xl bg-surface p-4 shadow-card" data-testid="statistics-reliability">
            <h3 class="font-display text-xl font-semibold">{{ $t('statistics.reliability') }}</h3>
            <p class="text-sm text-ink-3">{{ $t('statistics.reliabilityHint') }}</p>
            <ul class="mt-3 space-y-3">
              <li v-for="row in reliability" :key="row.key">
                <div class="flex items-baseline justify-between gap-2 text-sm">
                  <span class="font-medium">{{ row.name }}</span>
                  <span class="text-ink-3 tabular-nums">{{ row.rate }} % · {{ $t('statistics.ofDue', { done: row.done, due: row.due }) }}</span>
                </div>
                <span class="mt-1 block h-2.5 overflow-hidden rounded-full bg-surface-2">
                  <span class="block h-full rounded-full" :style="{ width: `${row.rate}%`, background: row.color }" />
                </span>
              </li>
            </ul>
          </section>

          <!-- On which days. -->
          <section class="rounded-2xl bg-surface p-4 shadow-card" data-testid="statistics-weekdays">
            <h3 class="font-display text-xl font-semibold">{{ $t('statistics.weekdays') }}</h3>
            <div class="mt-3 flex h-36 items-end gap-2">
              <div
                v-for="(column, index) in weekdays"
                :key="index"
                class="flex h-full flex-1 flex-col justify-end"
                role="img"
                :aria-label="$t('statistics.column', { label: weekdayLabels[index] ?? '', count: column.total })"
                :title="$t('statistics.column', { label: weekdayLabels[index] ?? '', count: column.total })"
              >
                <div
                  v-for="segment in [...column.segments].reverse()"
                  :key="segment.key"
                  class="w-full border-t-2 border-surface first:rounded-t"
                  :style="{ height: `${segment.share}%`, background: segment.color }"
                />
              </div>
            </div>
            <div class="mt-1 flex gap-2 text-center text-xs text-ink-3">
              <span v-for="label in weekdayLabels" :key="label" class="flex-1">{{ label }}</span>
            </div>
          </section>

          <!-- Stars in and out. -->
          <section v-if="stars.length > 0" class="rounded-2xl bg-surface p-4 shadow-card lg:col-span-2" data-testid="statistics-stars">
            <h3 class="font-display text-xl font-semibold">{{ $t('statistics.stars') }}</h3>
            <p class="text-sm text-ink-3">{{ $t('statistics.starsHint') }}</p>
            <ul class="mt-3 space-y-3">
              <li v-for="row in stars" :key="row.key" class="grid grid-cols-[6rem_1fr] items-center gap-x-3 gap-y-1 text-sm">
                <span class="row-span-2 truncate font-medium">{{ row.name }}</span>
                <span class="flex items-center gap-2">
                  <span class="block h-2.5 rounded-full bg-[var(--star)]" :style="{ width: `${row.earnedShare}%` }" />
                  <span class="inline-flex items-center gap-1 tabular-nums">{{ row.earned }} <TwStar /></span>
                </span>
                <span class="flex items-center gap-2 text-ink-3">
                  <span class="block h-2.5 rounded-full bg-ink-3" :style="{ width: `${row.spentShare}%` }" />
                  <span class="tabular-nums whitespace-nowrap">{{ row.spent }} {{ $t('statistics.spent') }}</span>
                </span>
              </li>
            </ul>
          </section>
        </div>
      </template>
    </template>
  </div>
</template>
