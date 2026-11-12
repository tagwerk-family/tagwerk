{{-- What the household should know; the app shows the same notices. --}}
<div>
    @foreach ($notices as $notice)
        <div class="fahoba-household-notice" data-level="{{ $notice->level->value }}" role="status" wire:key="notice-{{ $loop->index }}">
            <span>{{ $notice->text }}</span>
            @if ($notice->url !== null)
                <a href="{{ $notice->url }}">{{ $notice->action ?? __('More') }}</a>
            @endif
            @if ($notice->dismissible())
                <button type="button" wire:click="dismiss(@js($notice->key))">{{ __('Close') }}</button>
            @endif
        </div>
    @endforeach
</div>
