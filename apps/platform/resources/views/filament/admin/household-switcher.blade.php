{{-- The account's own households; switching reloads the panel. --}}
<form method="POST" action="{{ route('household.switch') }}" class="fahoba-support-switcher">
    @csrf
    <label class="sr-only" for="fahoba-household">{{ __('Household') }}</label>
    <select id="fahoba-household" name="household_id" onchange="this.form.submit()">
        @foreach ($households as $id => $name)
            <option value="{{ $id }}" @selected($id === $current)>{{ $name }}</option>
        @endforeach
    </select>
</form>
