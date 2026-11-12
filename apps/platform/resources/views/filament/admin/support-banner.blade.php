{{-- Always visible while supporting: nobody should change a stranger's calendar by accident. --}}
<div class="fahoba-support-banner" role="status">
    <span>{{ __('Support access:') }} <strong>{{ $household->name }}</strong></span>
    <form method="POST" action="{{ route('support.leave') }}">
        @csrf
        <button type="submit">{{ __('Back to my household') }}</button>
    </form>
</div>
