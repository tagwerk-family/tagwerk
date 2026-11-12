<script setup lang="ts">
import { type HouseholdNotice, rememberSeen, shownNotices } from '../../../shared/app/utils/notice'

interface Props {
  notices: HouseholdNotice[] | null | undefined
  device: boolean
  today: string
}

const props: Props = defineProps<Props>()

const STORAGE_KEY = 'fahoba-notice-seen'

function readSeen(): string[] {
  try {
    const stored: unknown = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]')

    return Array.isArray(stored) ? stored.filter((entry): entry is string => typeof entry === 'string') : []
  }
  catch {
    return []
  }
}

const seen = ref<string[]>(import.meta.client ? readSeen() : [])
// Closed by this account here, until the server's next answer leaves them out anyway.
const closed = ref<string[]>([])

const shown = computed(() => shownNotices(props.notices, props.device, seen.value, props.today)
  .filter(notice => !notice.key || !closed.value.includes(notice.key)))

function canClose(notice: HouseholdNotice): boolean {
  return props.device || notice.dismissible === true
}

async function close(notice: HouseholdNotice): Promise<void> {
  if (!props.device) {
    if (notice.key) {
      closed.value = [...closed.value, notice.key]
      // Failing costs only the memory: it shows again on the next start.
      await $fetch<unknown>('/api/notices/dismiss', { baseURL: '/', method: 'POST', body: { key: notice.key } }).catch(() => null)
    }

    return
  }

  seen.value = rememberSeen(seen.value, notice, props.today)

  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(seen.value))
  }
  catch {
    // A private window keeps it for this visit only.
  }
}
</script>

<template>
  <div
    v-for="notice in shown"
    :key="notice.key ?? notice.text"
    role="status"
    data-testid="household-notice"
    :data-level="notice.level"
    class="mx-3 mt-3 flex items-center gap-3 rounded-2xl px-5 py-3 text-base font-medium sm:mx-8 sm:mt-4"
    :class="notice.level === 'info' ? 'bg-surface-2 text-ink' : 'bg-warning text-warning-ink'"
  >
    <TwIcon :name="notice.level === 'info' ? 'info' : 'warning'" :size="22" class="shrink-0" />
    <span class="min-w-0 flex-1">{{ notice.text }}</span>
    <a
      v-if="notice.url"
      :href="notice.url"
      class="min-h-11 shrink-0 content-center font-bold underline"
    >{{ notice.action || $t('notice.more') }}</a>
    <button
      v-if="canClose(notice)"
      type="button"
      data-testid="household-notice-close"
      :aria-label="$t('notice.close')"
      class="flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-xl"
      @click="close(notice)"
    >
      <TwIcon name="close" :size="20" />
    </button>
  </div>
</template>
