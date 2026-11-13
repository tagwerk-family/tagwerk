<script setup lang="ts">
/*
 * One app, one shell for wall and phone. How it is laid out follows the
 * screen's width; what it offers follows who signed in — a paired device or
 * an account — and `useActor()`. Nobody signed in lands on the pairing
 * screen, which is the start page with both ways in.
 */
const { entry, decide } = useEntry()
const { user, checked, check } = useAuth()
const { set: setActor } = useActor()
const { apply: applyLanguage, locale } = useLanguage()

// Through unhead, not the DOM: its `htmlAttrs` from nuxt.config would write
// `de` back after every render.
useHead({ htmlAttrs: { lang: locale } })

// Kiosk rules (no text selection) only where a device runs.
watch(entry, (value) => {
  if (value) {
    document.documentElement.dataset.entry = value
  }

  if (value === 'user' && !checked.value) {
    check()
  }
})

// The start already ran in the server's language (plugins/language.client.ts);
// this follows a switch in the settings or between households.
watch(() => JSON.stringify(user.value?.language ?? user.value?.locale ?? null), () => {
  if (entry.value === 'user') {
    applyLanguage(user.value?.language ?? user.value?.locale)
  }
})

// Sync, so that no view renders with the account but without its rights.
watch(user, (now) => {
  if (entry.value === 'user') {
    setActor(now && { kind: 'user', personId: now.person_id, permissions: now.permissions })
  }
}, { immediate: true, flush: 'sync' })

onMounted(decide)
</script>

<template>
  <AppShell v-if="entry === 'device' || (entry === 'user' && user)" />
  <div v-else-if="entry === 'user' && checked" class="h-full overflow-y-auto">
    <LoginScreen />
  </div>
  <AppLoading v-else class="bg-canvas" />
</template>
