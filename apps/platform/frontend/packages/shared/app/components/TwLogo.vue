<script setup lang="ts">
/*
 * The mark of Family Home Base — a roof over a small house: the family's
 * home base, on a sand tile.
 *
 * Fixed colours instead of tokens — a mark does not change with the theme.
 * The same shape lives as icon.svg in the app; see AGENTS.md (“The mark”)
 * for every other place.
 */
</script>

<template>
  <svg viewBox="0 0 160 160" aria-hidden="true" focusable="false">
    <rect width="160" height="160" rx="34" fill="#F4E3CF" />
    <g fill="#B84B2D" transform="translate(80 80) scale(1.1) translate(-80 -51.5)">
      <path d="M26 80V39L73 8v31z" />
      <path d="M87 8l47 31v41L87 39z" />
      <path d="M53 64l27-19 27 19v31H53z" />
    </g>
  </svg>
</template>
