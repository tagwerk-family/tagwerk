<script setup lang="ts">
import { bookingDays, bookingText, affordable, riseKeys, share, shortfall } from '../../../shared/app/utils/points'
import { formatIsoDate, zonedIso } from '../../../shared/app/utils/calendar'
import { intlLocale } from '../../../shared/app/utils/intlLocale'

import type { PointPerson } from '~/composables/usePoints'

const { t } = useI18n()

const {
  people,
  bookings,
  rewards,
  pending,
  more,
  error,
  loaded,
  load,
  loadMore,
  filter,
  filterBookings,
  requests,
  approve,
  reject,
  book,
  gift,
  locked,
} = usePoints()

type Range = 'week' | 'month' | 'total'

const RANGES = computed((): { value: Range, label: string }[] => [
  { value: 'week', label: t('calendar.views.week') },
  { value: 'month', label: t('calendar.views.month') },
  { value: 'total', label: t('points.total') },
])

const range = ref<Range>('week')

function pointsIn(person: PointPerson): number {
  if (range.value === 'week') {
    return person.week_points
  }

  return range.value === 'month' ? person.month_points : person.balance
}

/*
 * Ties keep the alphabetical order: a leaderboard that swaps places daily
 * at equal scores causes more quarrels than motivation.
 */
const leaderboard = computed(() =>
  [...people.value].sort((a, b) => pointsIn(b) - pointsIn(a)),
)

const who = ref<number | null>(null)

/*
 * The action still pending because the PIN was missing. After unlocking
 * exactly that one is repeated — otherwise you would have to tap the same
 * field again after typing the PIN.
 */
const retry = ref<(() => Promise<boolean>) | null>(null)

const selected = computed(() => people.value.find(person => person.id === who.value) ?? null)

const color = (value: string | null): string => value ?? '#8a8276'

/* The history is there for looking up, not for looking at: closed at first. */
const bookingsOpen = ref(false)

/* Only a refusal for want of the PIN asks for it; any other failure shows its message. */
async function attempt(action: () => Promise<boolean>): Promise<void> {
  if (!await action() && locked.value) {
    retry.value = action
  }
}

async function unlocked(): Promise<void> {
  const action = retry.value

  retry.value = null

  if (action) {
    await action()
  }
}

/*
 * One decision at a time; `deciding` names the button that is on its way
 * ("reward-3", "12-approve"), so only that one turns.
 */
const deciding = ref<string | null>(null)
const { busy: decidingBusy, run: runDecision } = useBusy()

function decide(key: string, action: () => Promise<boolean>): void {
  deciding.value = key
  void runDecision(() => attempt(action))
}

/*
 * An account that may award stars redeems at once — the server books
 * without a request to approve. One tap would then debit for good, so the
 * card asks for a second one; the wall's tap only asks the parents.
 */
const { kind, can } = useActor()
const redeemsAtOnce = computed(() => kind.value === 'user' && can('points.award'))

/*
 * What each person has asked for and nobody decided yet, beside their
 * balance: the list of open requests sits below the cards, out of sight on
 * a tablet, and the tap on a reward otherwise showed nothing.
 */
const requested = computed(() => {
  const sums: Record<number, number> = {}

  for (const entry of pending.value) {
    sums[entry.person_id] = (sums[entry.person_id] ?? 0) + entry.cost
  }

  return sums
})
const { armed, tap } = useTwoStep()

function submitRequest(rewardId: number): void {
  if (who.value === null) {
    return
  }

  const person = who.value
  const send = (): void => decide(`reward-${rewardId}`, () => requests(rewardId, person))

  if (redeemsAtOnce.value) {
    tap(`reward-${rewardId}`, send)
  }
  else {
    send()
  }
}

/*
 * Extra stars: PIN first, then the popup — whoever types a reason should not
 * be stopped after it. The snapshot is fetched fresh because an unlock runs
 * out after a few minutes.
 */
const bonusFor = ref<PointPerson | null>(null)
const bonusBusy = ref(false)

async function openBonus(person: PointPerson): Promise<void> {
  const snapshot = await $fetch<{ required: boolean, unlocked: boolean }>('/api/wall/pin', {
    credentials: 'same-origin',
  }).catch(() => ({ required: false, unlocked: true }))

  if (snapshot.required && !snapshot.unlocked) {
    retry.value = async () => {
      bonusFor.value = person

      return true
    }

    return
  }

  bonusFor.value = person
}

// The other children, as they stand now: a gift goes up to what the giver has.
const givers = computed(() => people.value.filter(person => person.id !== bonusFor.value?.id))

function giveBonus(bonus: { points: number, reason: string }): Promise<void> {
  return submitBonus(person => book(person.id, bonus.points, bonus.reason))
}

function giveGift(present: { from: number, points: number, note: string }): Promise<void> {
  return submitBonus(person => gift(present.from, person.id, present.points, present.note))
}

async function submitBonus(send: (person: PointPerson) => Promise<boolean>): Promise<void> {
  const person = bonusFor.value

  if (person === null) {
    return
  }

  bonusBusy.value = true

  const booked = () => send(person)
  const done = await booked()

  bonusBusy.value = false

  if (done) {
    bonusFor.value = null
  }
  else if (locked.value) {
    // The unlock ran out while typing: ask again, then book what was typed.
    retry.value = async () => {
      const again = await booked()

      if (again) {
        bonusFor.value = null
      }

      return again
    }
  }
}

onMounted(load)

// Pops on a rise only: a deduction is said quietly.
const riseKey = riseKeys()

/*
 * Bookings under a line per day, with the time — in the household's zone,
 * like everything on the wall, not in the tablet's.
 */
const { agenda } = useAgenda()
const zone = computed(() => agenda.value?.household.timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone)
const days = computed(() => bookingDays(bookings.value, zone.value))

function dayTitle(iso: string): string {
  const today = zonedIso(new Date(), zone.value)
  const yesterday = zonedIso(new Date(Date.now() - 86_400_000), zone.value)

  if (iso === today) {
    return t('common.today')
  }

  if (iso === yesterday) {
    return t('common.yesterday')
  }

  return iso === '' ? t('points.noDate') : formatIsoDate(iso, { weekday: 'long', day: 'numeric', month: 'long' })
}

function clockTime(moment: string | null): string {
  return moment === null
    ? ''
    : new Intl.DateTimeFormat(intlLocale.value, { hour: '2-digit', minute: '2-digit', timeZone: zone.value }).format(new Date(moment))
}

const SIGNS = computed((): { value: 'plus' | 'minus' | null, label: string }[] => [
  { value: null, label: t('points.filter.allKinds') },
  { value: 'plus', label: t('points.filter.plus') },
  { value: 'minus', label: t('points.filter.minus') },
])

const filtered = computed(() => filter.value.person !== null || filter.value.sign !== null)
</script>

<template>
  <div class="h-full overflow-y-auto pb-6">
    <p
      v-if="error"
      role="alert"
      class="mb-5 flex items-center gap-3 rounded-2xl bg-warning px-5 py-3 text-base font-medium text-warning-ink"
    >
      <TwIcon name="warning" :size="20" />
      {{ error }}
    </p>

    <TwLoading v-if="!loaded && !error" />

    <template v-else>
    <section class="mb-10" aria-labelledby="balance">
      <h2 id="balance" class="mb-4 font-display text-2xl font-semibold">
        {{ $t('points.title') }}
      </h2>

      <div class="flex flex-wrap gap-4">
        <div v-for="person in people" :key="person.id" class="relative w-full sm:w-auto">
          <button
            type="button"
            data-testid="points-person"
            :aria-pressed="who === person.id"
            class="tw-press flex h-full w-full flex-col rounded-3xl bg-surface p-5 sm:w-72 text-left shadow-card transition"
            :style="who === person.id
              ? { backgroundColor: color(person.color) + '1f', boxShadow: `inset 0 0 0 3px ${color(person.color)}` }
              : undefined"
            @click="who = who === person.id ? null : person.id"
          >
            <!-- Picture at the top, beside it who and how much; the corner
                 stays free for the star-plus button. -->
            <span class="flex items-start gap-3 pr-10">
              <TwAvatar :name="person.name" :color="person.color" :avatar="person.avatar" :size="52" />

              <span class="min-w-0">
                <span class="block truncate text-lg font-medium text-ink-2">{{ person.name }}</span>
                <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                  <span
                    :key="riseKey(person.id, person.balance)"
                    class="tw-pop flex items-center gap-1.5 font-display text-4xl leading-none font-semibold tabular-nums"
                    :aria-label="$t('task.stars', person.balance)"
                  >
                    <TwStar class="text-2xl" />
                    {{ person.balance }}
                  </span>

                  <span
                    v-if="requested[person.id]"
                    :key="`requested-${requested[person.id]}`"
                    data-testid="requested"
                    class="tw-pop flex items-center gap-1 rounded-full bg-surface-2 px-2.5 py-1 text-lg leading-none font-semibold text-ink-2 tabular-nums"
                    :aria-label="$t('points.requested', requested[person.id]!)"
                  >
                    <TwIcon name="timer" :size="18" />
                    −{{ requested[person.id] }}
                  </span>
                </span>
              </span>
            </span>

            <span class="mt-3 flex flex-wrap gap-1.5 text-sm font-semibold">
              <span class="rounded-full bg-surface-2 px-2.5 py-0.5 text-ink-2">
                {{ $t('points.week', { n: person.week_points }) }}
              </span>
              <span v-if="person.streak > 0" class="rounded-full bg-surface-2 px-2.5 py-0.5 text-ink-2">
                🔥 {{ $t('points.streakShort', person.streak) }}
              </span>
            </span>

            <!-- Badges with a picture: as plain chips beside the week and the
                 streak they read like more numbers, not like something won. -->
            <span v-if="person.badges.length > 0" class="mt-2 flex flex-wrap gap-1.5 text-sm font-semibold">
              <span
                v-for="badge in person.badges"
                :key="badge.key"
                data-testid="badge"
                class="flex items-center gap-1.5 rounded-full bg-points py-0.5 pr-3 pl-0.5 text-points-ink"
              >
                <span class="flex size-7 items-center justify-center rounded-full bg-surface text-lg shadow-card">
                  <TwEmoji :icon="badge.icon" />
                </span>
                {{ badge.name }}
              </span>
            </span>

            <span v-if="person.saving" class="mt-2 block text-sm text-ink-3">
              {{ $t('points.saving', { reward: person.saving.reward, missing: person.saving.missing }) }}
            </span>

            <!-- The bar only with a goal set: an empty frame without a goal
                 would look like a deficit. At the very bottom, full width. -->
            <span v-if="person.weekly_goal > 0" data-testid="weekly-goal" class="mt-auto block w-full pt-4">
              <span class="flex items-baseline justify-between text-sm font-semibold" :class="person.goal_reached ? 'text-success' : 'text-ink-3'">
                <span>{{ person.goal_reached ? $t('points.goalReached') : $t('points.weeklyGoal') }}</span>
                <span class="tabular-nums">{{ $t('points.of', { have: person.week_points, need: person.weekly_goal }) }}</span>
              </span>
              <span class="mt-1 block h-2.5 overflow-hidden rounded-full bg-surface-2">
                <span
                  class="block h-full rounded-full transition-[width] duration-700 ease-out"
                  :class="person.goal_reached ? 'bg-success' : 'bg-accent'"
                  :style="{ width: `${share(person.week_points, person.weekly_goal)}%` }"
                />
              </span>
            </span>
          </button>

          <!-- Beside the tile, not inside: a button in a button is not allowed.
               The visible circle is small, the tap area stays 44 px. -->
          <button
            type="button"
            data-testid="bonus"
            :aria-label="$t('points.bonusFor', { name: person.name })"
            class="tw-press absolute top-2 right-2 flex size-11 items-center justify-center"
            @click="openBonus(person)"
          >
            <span aria-hidden="true" class="flex size-9 items-center justify-center rounded-full bg-points text-sm font-bold text-points-ink shadow-card">+<TwStar /></span>
          </button>
        </div>

        <p v-if="people.length === 0" class="text-xl text-ink-3">
          {{ $t('points.noChildren') }}
        </p>
      </div>
    </section>

    <section class="mb-10" aria-labelledby="rewards">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 id="rewards" class="font-display text-2xl font-semibold">
          {{ $t('points.rewards') }}
        </h2>

        <!-- The cards stay readable even while nobody is selected: children
             should see what they are saving for. -->
        <p
          v-if="who === null && rewards.length > 0"
          class="flex items-center gap-2 rounded-full bg-accent-soft px-4 py-2 text-base font-semibold text-accent"
        >
          <TwIcon name="points" :size="18" />
          {{ $t('points.redeemHint') }}
        </p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <button
          v-for="reward in rewards"
          :key="reward.id"
          type="button"
          data-testid="reward"
          class="tw-press flex flex-col items-start rounded-3xl bg-surface p-6 text-left shadow-card transition enabled:hover:-translate-y-0.5"
          :disabled="who === null || !affordable(selected?.balance ?? 0, reward.cost) || decidingBusy"
          @click="submitRequest(reward.id)"
        >
          <span class="flex w-full items-center justify-between gap-3">
            <span
              class="flex items-center gap-1 rounded-full bg-points px-3 py-1 text-base font-bold text-points-ink tabular-nums"
              :aria-label="$t('task.stars', reward.cost)"
            >
              <TwStar />
              {{ reward.cost }}
            </span>

            <TwPending :busy="decidingBusy && deciding === `reward-${reward.id}`" class="text-accent" />

            <!-- The icon carries the card: whoever cannot read yet looks for it. -->
            <span v-if="reward.icon" aria-hidden="true" class="text-4xl"><TwEmoji :icon="reward.icon" /></span>
          </span>

          <span class="mt-3 font-display text-2xl font-semibold">{{ reward.name }}</span>

          <span
            v-if="armed === `reward-${reward.id}`"
            data-testid="reward-confirm"
            class="tw-pop mt-2 rounded-full bg-accent px-3 py-1 text-base font-semibold text-accent-ink"
          >
            {{ $t('points.redeemConfirm', { name: selected?.name ?? '' }) }}
          </span>

          <span v-if="reward.description" class="mt-1 text-base text-ink-2">
            {{ reward.description }}
          </span>

          <!-- With a child chosen: how far their stars already reach, in
               their colour. Full means the card can be tapped. -->
          <span v-if="selected" data-testid="reward-progress" class="mt-auto block w-full pt-4">
            <span class="relative block h-7 overflow-hidden rounded-full" :style="{ backgroundColor: color(selected.color) + '2e' }">
              <span
                class="block h-full rounded-full transition-[width] duration-700 ease-out"
                :style="{ width: `${share(selected.balance, reward.cost)}%`, backgroundColor: color(selected.color) }"
              />
              <span class="absolute inset-0 flex items-center justify-center gap-1 text-sm font-bold tabular-nums text-ink">
                <TwStar />
                {{ $t('points.of', { have: Math.min(selected.balance, reward.cost), need: reward.cost }) }}
              </span>
            </span>
            <span
              v-if="!affordable(selected.balance, reward.cost)"
              class="mt-1.5 block text-sm font-semibold text-ink-3"
            >
              {{ $t('points.missing', { n: shortfall(selected.balance, reward.cost) }) }}
            </span>
          </span>
        </button>

        <p v-if="rewards.length === 0" class="text-xl text-ink-3">
          {{ $t('points.noRewards') }}
        </p>
      </div>
    </section>

    <section v-if="pending.length > 0" class="mb-10" aria-labelledby="count-open-requests">
      <h2 id="count-open-requests" class="mb-4 font-display text-2xl font-semibold">
        {{ $t('points.requests') }}
      </h2>

      <ul class="grid gap-3 xl:grid-cols-2">
        <li
          v-for="entry in pending"
          :key="entry.id"
          data-testid="request"
          :data-id="entry.id"
          class="flex flex-wrap items-center gap-3 rounded-3xl bg-surface p-4 pl-6 shadow-card"
        >
          <TwAvatar
            v-if="entry.person"
            :name="entry.person"
            :avatar="entry.avatar"
            :size="40"
          />

          <span class="min-w-0 flex-1">
            <span class="block text-xl font-semibold">
              {{ $t('points.wants', { name: entry.person, reward: entry.reward }) }}
            </span>
            <span class="block text-base text-ink-3 tabular-nums"><TwStar /> {{ entry.cost }}</span>
          </span>

          <button
            type="button"
            data-testid="reject"
            class="flex min-h-12 items-center gap-2 rounded-2xl px-4 text-lg font-semibold text-ink-2 transition-colors hover:bg-surface-2"
            :disabled="decidingBusy"
            @click="decide(`${entry.id}-reject`, () => reject(entry.id))"
          >
            <TwPending :busy="decidingBusy && deciding === `${entry.id}-reject`" />
            {{ $t('points.decline') }}
          </button>

          <button
            type="button"
            data-testid="approve"
            class="flex min-h-12 items-center gap-2 rounded-2xl bg-accent px-5 text-lg font-semibold text-accent-ink"
            :disabled="decidingBusy"
            @click="decide(`${entry.id}-approve`, () => approve(entry.id))"
          >
            <TwPending :busy="decidingBusy && deciding === `${entry.id}-approve`" />
            {{ $t('points.approve') }}
          </button>
        </li>
      </ul>
    </section>

    <section v-if="people.length > 1" class="mb-10" aria-labelledby="leaderboard">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 id="leaderboard" class="font-display text-2xl font-semibold">
          {{ $t('points.ranking') }}
        </h2>

        <div role="group" :aria-label="$t('points.range')" class="flex gap-1 rounded-full bg-surface p-1 shadow-card">
          <button
            v-for="entry in RANGES"
            :key="entry.value"
            type="button"
            data-testid="range"
            :aria-pressed="range === entry.value"
            class="min-h-11 rounded-full px-5 text-base font-semibold transition-colors"
            :class="range === entry.value ? 'bg-accent text-accent-ink' : 'text-ink-2 hover:text-ink'"
            @click="range = entry.value"
          >
            {{ entry.label }}
          </button>
        </div>
      </div>

      <ol class="divide-y divide-line overflow-hidden rounded-3xl bg-surface shadow-card">
        <li
          v-for="(person, rank) in leaderboard"
          :key="person.id"
          data-testid="rank"
          class="flex items-center gap-4 px-6 py-3"
        >
          <span class="w-8 shrink-0 font-display text-2xl font-semibold text-ink-3 tabular-nums">{{ rank + 1 }}</span>

          <TwAvatar :name="person.name" :color="person.color" :avatar="person.avatar" :size="40" />

          <span class="min-w-0 flex-1 truncate text-xl font-semibold">{{ person.name }}</span>

          <span class="shrink-0 font-display text-2xl font-semibold tabular-nums">{{ pointsIn(person) }}</span>
        </li>
      </ol>
    </section>

    <section v-if="bookings.length > 0 || filtered" aria-labelledby="last-bookings">
      <h2 id="last-bookings" class="font-display text-2xl font-semibold">
        <button
          type="button"
          data-testid="bookings-toggle"
          :aria-expanded="bookingsOpen"
          aria-controls="booking-list"
          class="tw-press flex min-h-12 items-center gap-2 rounded-2xl pr-3"
          @click="bookingsOpen = !bookingsOpen"
        >
          {{ $t('points.recent') }}
          <TwIcon name="forward" :size="20" class="text-ink-3 transition-transform" :class="bookingsOpen ? 'rotate-90' : ''" />
        </button>
      </h2>

      <div v-if="bookingsOpen" class="tw-fade-down mt-3 flex flex-wrap items-center gap-2" data-testid="booking-filter">
        <div role="group" :aria-label="$t('points.filter.who')" class="flex flex-wrap gap-1 rounded-full bg-surface p-1 shadow-card">
          <button
            v-for="person in [{ id: null, name: $t('points.filter.everyone') }, ...people]"
            :key="person.id ?? 'all'"
            type="button"
            data-testid="booking-person"
            :aria-pressed="filter.person === person.id"
            class="min-h-11 rounded-full px-4 text-base font-semibold transition-colors"
            :class="filter.person === person.id ? 'bg-accent text-accent-ink' : 'text-ink-2 hover:text-ink'"
            @click="filterBookings({ ...filter, person: person.id })"
          >
            {{ person.name }}
          </button>
        </div>

        <div role="group" :aria-label="$t('points.filter.kind')" class="flex gap-1 rounded-full bg-surface p-1 shadow-card">
          <button
            v-for="sign in SIGNS"
            :key="sign.value ?? 'all'"
            type="button"
            data-testid="booking-sign"
            :aria-pressed="filter.sign === sign.value"
            class="min-h-11 rounded-full px-4 text-base font-semibold transition-colors"
            :class="filter.sign === sign.value ? 'bg-accent text-accent-ink' : 'text-ink-2 hover:text-ink'"
            @click="filterBookings({ ...filter, sign: sign.value })"
          >
            {{ sign.label }}
          </button>
        </div>
      </div>

      <p
        v-if="bookingsOpen && bookings.length === 0"
        class="mt-3 rounded-3xl bg-surface px-6 py-5 text-base text-ink-3 shadow-card"
      >
        {{ $t('points.filter.none') }}
      </p>

      <ul
        v-else-if="bookingsOpen"
        id="booking-list"
        class="mt-3 overflow-hidden rounded-3xl bg-surface shadow-card"
      >
        <template v-for="day in days" :key="day.iso">
          <li
            data-testid="booking-day"
            class="border-b border-line bg-surface-2 px-6 py-2 text-sm font-bold tracking-wide text-ink-2"
          >
            <h3>{{ dayTitle(day.iso) }}</h3>
          </li>

          <li
            v-for="entry in day.entries"
            :key="entry.id"
            data-testid="booking"
            class="flex items-center gap-4 border-b border-line px-6 py-3 text-base last:border-0"
          >
            <span
              class="w-16 shrink-0 rounded-full py-0.5 text-center font-bold tabular-nums"
              :class="entry.points > 0 ? 'bg-success-soft text-success' : 'bg-surface-2 text-ink-2'"
            >
              {{ bookingText(entry.points) }}
            </span>

            <TwAvatar
              v-if="entry.person"
              :name="entry.person"
              :avatar="entry.avatar"
              :size="28"
            />

            <span class="w-28 shrink-0 font-semibold">{{ entry.person }}</span>

            <span class="min-w-0 flex-1 truncate text-ink-2">{{ entry.reason }}</span>

            <time
              v-if="entry.created_at"
              data-testid="booking-time"
              :datetime="entry.created_at"
              class="shrink-0 text-ink-3 tabular-nums"
            >{{ clockTime(entry.created_at) }}</time>
          </li>
        </template>
      </ul>

      <button
        v-if="bookingsOpen && more"
        type="button"
        data-testid="more-bookings"
        class="mt-3 flex min-h-12 w-full items-center justify-center rounded-2xl border-2 border-line text-lg font-semibold transition-colors hover:bg-surface-2"
        @click="loadMore"
      >
        {{ $t('points.older') }}
      </button>
    </section>
    </template>

    <BonusDialog
      v-if="bonusFor"
      :name="bonusFor.name"
      :balance="bonusFor.balance"
      :givers="givers"
      :busy="bonusBusy"
      :error="error"
      @close="bonusFor = null"
      @save="giveBonus"
      @gift="giveGift"
    />

    <PinDialog
      v-if="retry"
      @close="retry = null"
      @unlocked="unlocked"
    />
  </div>
</template>
