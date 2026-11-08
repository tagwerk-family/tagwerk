{{--
    Classes live in public/css/tagwerk-admin.css: Filament's stylesheet only
    carries the classes it uses itself. The cell's tint is one hue, light to
    dark with the count; the number is always written, so the colour never
    carries the meaning alone.
--}}
<x-filament-widgets::widget>
    <x-filament::section :heading="__('Who does which task')" :description="__('How often each task was done, by whom. The darker, the more often.')">
        @if ($rows === [])
            <p class="tw-stat-empty">{{ __('Nothing was done in this period.') }}</p>
        @else
            <div class="tw-stat-scroll">
                <table class="tw-stat-matrix">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Task') }}</th>
                            @foreach ($columns as $column)
                                <th scope="col" class="tw-stat-num">
                                    <span class="tw-stat-person"><span class="tw-stat-dot" style="background: {{ $column['color'] }}"></span>{{ $column['name'] }}</span>
                                </th>
                            @endforeach
                            <th scope="col" class="tw-stat-num">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <th scope="row">
                                    @if ($row['icon'])<span aria-hidden="true">{{ $row['icon'] }}</span> @endif{{ $row['title'] }}
                                </th>
                                @foreach ($columns as $column)
                                    @php($count = $row['counts'][$column['key']] ?? 0)
                                    @php($share = $count === 0 ? 0 : (int) round(12 + $count / $max * 78))
                                    <td class="tw-stat-num tw-stat-cell {{ $share > 55 ? 'tw-stat-strong' : '' }}"
                                        style="--share: {{ $share }}%"
                                        title="{{ __(':person: :count× :task', ['person' => $column['name'], 'count' => $count, 'task' => $row['title']]) }}">
                                        {{ $count === 0 ? '·' : $count }}
                                    </td>
                                @endforeach
                                <td class="tw-stat-num tw-stat-total">{{ $row['total'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
