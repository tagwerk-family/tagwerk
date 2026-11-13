<script setup lang="ts">
// Same picture as spa-loading-template.html, so the hand-over does not jump.
</script>

<template>
  <div role="status" class="flex h-full flex-col items-center justify-center gap-4 px-6 text-center">
    <TwLogo class="size-20" />
    <p class="font-display text-3xl font-bold text-ink">
      Family Home Base
    </p>
    <p class="text-lg text-ink-3">
      {{ $t('common.loading') }}
    </p>
  </div>
</template>
