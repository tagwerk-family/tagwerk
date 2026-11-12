{{-- An empty choice leaves the household the operator is supporting. --}}
<form
    method="POST"
    action="{{ route('support.enter') }}"
    data-leave="{{ route('support.leave') }}"
    class="fahoba-support-switcher"
>
    @csrf
    <label class="sr-only" for="fahoba-support-household">{{ __('Household') }}</label>
    <select
        id="fahoba-support-household"
        name="household_id"
        onchange="if (this.value === '') { this.form.action = this.form.dataset.leave } this.form.submit()"
    >
        @if ($ownHousehold === null)
            <option value="" @selected($current === null)>{{ __('No household') }}</option>
        @endif
        @foreach ($households as $id => $name)
            <option value="{{ $id }}" @selected($id === $current)>{{ $name }}</option>
        @endforeach
    </select>
</form>
