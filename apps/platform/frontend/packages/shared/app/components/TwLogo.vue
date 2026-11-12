<script setup lang="ts">
/*
 * Fahoba's mark — the family's home base: a tick inside a house, on a
 * terracotta tile.
 *
 * Fixed colours instead of tokens — a mark does not change with the theme.
 * The gradient's id is per instance: several logos sit in one page, and a
 * shared id resolves to the first, which a hidden copy leaves unpainted.
 * The same shape lives as icon.svg in the app; see AGENTS.md (“The mark”)
 * for every other place.
 */
const tile = useId()
</script>

<template>
  <svg viewBox="0 0 512 512" aria-hidden="true" focusable="false">
    <defs>
      <linearGradient :id="tile" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0" stop-color="#ea580c" />
        <stop offset="1" stop-color="#b93a0a" />
      </linearGradient>
    </defs>
    <rect width="512" height="512" rx="112" :fill="`url(#${tile})`" />
    <path
      d="M136 236 256 132 376 236V364a20 20 0 0 1-20 20H156a20 20 0 0 1-20-20Z"
      fill="none" stroke="#ffffff" stroke-width="36" stroke-linejoin="round"
    />
    <path
      d="M200 297 240 337 312 263"
      fill="none" stroke="#fdba74" stroke-width="36" stroke-linecap="round" stroke-linejoin="round"
    />
  </svg>
</template>
