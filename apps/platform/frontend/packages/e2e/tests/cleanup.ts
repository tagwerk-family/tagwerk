import { tinker } from './helpers'

/**
 * Removes what the tests create: throwaway accounts for the phone and the
 * "E2E" devices newly created by every pairing, tasks created on the wall and photos uploaded from the phone. Otherwise the development
 * environment's device list grows with every run.
 */
export default function cleanUp(): void {
  tinker(
    // Households of their own (ownHousehold) that a crashed test left; deleting cascades.
    'App\\Models\\Household::where("name", "like", "E2E %")->get()->each(function ($haushalt) { $haushalt->delete(); });'
    // Photos uploaded by throwaway accounts, before the accounts go — the trash included.
    // forceDelete takes file and small copy along (Photo::booted).
    + ' App\\Models\\Photo::withoutGlobalScope("household")->withTrashed()'
    + '->whereIn("user_id", App\\Models\\User::where("email", "like", "e2e-%@fahoba.test")->pluck("id"))->get()'
    + '->each(function ($foto) { $foto->forceDelete(); });'
    // Photos a wall test put in directly carry "E2E" in the caption.
    + ' App\\Models\\Photo::withoutGlobalScope("household")->withTrashed()->where("caption", "like", "E2E %")->get()'
    + '->each(function ($foto) { $foto->forceDelete(); });'
    + ' App\\Models\\User::where("email", "like", "e2e-%@fahoba.test")->get()'
    + '->each(function ($benutzer) { $benutzer->tokens()->delete(); $benutzer->delete(); });'

    // Devices hang off the household scope, which shows nothing in Tinker without a context.
    + ' App\\Models\\Device::withoutGlobalScope("household")->where("name", "E2E")->get()'
    + '->each(function ($geraet) { $geraet->tokens()->delete(); $geraet->delete(); });'

    // Extra stars given on the wall carry "E2E" in the reason.
    + ' App\\Models\\PointEntry::withoutGlobalScope("household")->where("reason", "like", "E2E %")->delete();'

    // Recipes written in the editor; deleting each takes its photos along.
    + ' App\\Models\\Recipe::withoutGlobalScope("household")->where("title", "like", "E2E %")->get()->each(function ($rezept) { $rezept->delete(); });'

    // Tasks created on the wall: it has no editing, so nothing removes them there.
    + ' App\\Models\\Task::withoutGlobalScope("household")->where("title", "like", "E2E %")->get()'
    + '->each(function ($aufgabe) { App\\Models\\TaskCompletion::withoutGlobalScope("household")->where("task_id", $aufgabe->id)->exists() ? $aufgabe->forceFill(["active" => false])->save() : $aufgabe->delete(); });',
  )
}
