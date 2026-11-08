<script setup lang="ts">
/*
 * Line icons from Lucide, rendered as inline SVG.
 *
 * Each icon is its own component and imported by name, so the build ships
 * only the icons listed here — nothing is fetched at runtime, which matters
 * on a wall that runs without a network. A new icon is one import and one
 * line in ICONS; the whole set is at lucide.dev/icons.
 */
import type { Component } from 'vue'
import {
  ArrowLeftRight,
  Bell,
  Bold,
  BookOpen,
  Calendar,
  Camera,
  ChartColumn,
  Cast,
  Check,
  ChefHat,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  ChevronUp,
  Clock,
  Contrast,
  Delete,
  Eye,
  EyeOff,
  Folder,
  Globe,
  GraduationCap,
  Heading,
  Heart,
  History,
  Hourglass,
  House,
  ImagePlus,
  Images,
  Info,
  Italic,
  LayoutGrid,
  Leaf,
  Link,
  List,
  ListFilter,
  ListOrdered,
  LogOut,
  Menu,
  Moon,
  Palette,
  Pause,
  Pencil,
  Play,
  Plus,
  Puzzle,
  RefreshCw,
  Repeat,
  RotateCcw,
  Settings,
  ShieldCheck,
  Shuffle,
  SkipForward,
  Sparkles,
  SquareCheck,
  Star,
  Sun,
  Timer,
  Trash2,
  TriangleAlert,
  Users,
  Utensils,
  X,
} from 'lucide-vue-next'

const ICONS = {
  calendar: Calendar,
  tasks: SquareCheck,
  lists: List,
  points: Star,
  meals: Utensils,
  recipes: BookOpen,
  today: House,
  school: GraduationCap,
  sun: Sun,
  moon: Moon,
  auto: Contrast,
  back: ChevronLeft,
  forward: ChevronRight,
  up: ChevronUp,
  down: ChevronDown,
  close: X,
  delete: Trash2,
  settings: Settings,
  info: Info,
  admin: ShieldCheck,
  refresh: RefreshCw,
  restore: RotateCcw,
  plus: Plus,
  backspace: Delete,
  warning: TriangleAlert,
  check: Check,
  swap: ArrowLeftRight,
  pencil: Pencil,
  eye: Eye,
  eyeOff: EyeOff,
  filter: ListFilter,
  photo: Camera,
  dashboard: LayoutGrid,
  stopwatch: Timer,
  timer: Hourglass,
  folder: Folder,
  burger: Menu,
  bell: Bell,
  globe: Globe,
  logout: LogOut,
  palette: Palette,
  heading: Heading,
  bold: Bold,
  italic: Italic,
  listOrdered: ListOrdered,
  link: Link,
  heart: Heart,
  clock: Clock,
  servings: Users,
  shuffle: Shuffle,
  skip: SkipForward,
  cook: ChefHat,
  vegetarian: Leaf,
  puzzle: Puzzle,
  repeat: Repeat,
  history: History,
  pause: Pause,
  play: Play,
  albums: Images,
  cast: Cast,
  memories: Sparkles,
  upload: ImagePlus,
  statistics: ChartColumn,
} satisfies Record<string, Component>

export type IconName = keyof typeof ICONS

withDefaults(defineProps<{ name: IconName, size?: number }>(), { size: 24 })
</script>

<template>
  <!-- Purely decorative: the surrounding button always carries the label.
       Lucide sets aria-hidden itself when no label is given. -->
  <component
    :is="ICONS[name] ?? Puzzle"
    :size="size"
    :stroke-width="1.8"
    focusable="false"
    class="shrink-0"
  />
</template>
