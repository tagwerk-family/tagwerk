<script setup lang="ts">
const greeting = ref<string | null>(null)
const failed = ref(false)

onMounted(async () => {
  try {
    const response = await $fetch<{ household: string }>('/api/fahoba/example-area/greeting', { baseURL: '/' })
    greeting.value = response.household
  } catch {
    failed.value = true
  }
})
</script>

<template>
  <section data-testid="example-area" class="flex h-full items-center justify-center p-8">
    <TwLoading v-if="greeting === null && !failed" />
    <p v-else-if="failed" class="text-lg text-ink-2">
      {{ $t('plugins.exampleArea.failed') }}
    </p>
    <p v-else class="font-display text-3xl font-semibold text-ink">
      {{ $t('plugins.exampleArea.hello', { household: greeting }) }}
    </p>
  </section>
</template>
