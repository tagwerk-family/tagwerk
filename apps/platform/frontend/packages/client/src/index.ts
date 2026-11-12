/**
 * `@fahoba/client` — what every Fahoba frontend needs, without a framework:
 * talking to the server, realtime announcements, and the rules the screen
 * must apply the same way the official app does.
 */
export * from './api.ts'
export * from './http.ts'
export * from './realtime.ts'
export type * from './tasks.ts'
export * from './rules/announcements.ts'
export * from './rules/coalesce.ts'
export * from './rules/dayCompletion.ts'
export * from './rules/design.ts'
export * from './rules/language.ts'
export * from './rules/notice.ts'
export * from './rules/task.ts'
export * from './rules/theme.ts'
