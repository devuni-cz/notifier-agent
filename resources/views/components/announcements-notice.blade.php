@if (! empty($announcements))
    {{-- Items arrive priority-ordered, so the top-N are the most important. Cap how --}}
    {{-- many render here (0 = unlimited) and summarise the rest in a muted line. --}}
    @php($max = (int) config('notifier.announcements.max_visible', 5))
    @php($visible = $max > 0 ? array_slice($announcements, 0, $max) : $announcements)
    @php($hidden = count($announcements) - count($visible))

    <div class="notifier-announcements">
        @foreach ($visible as $announcement)
            @php($content = $announcement['content'] ?? null)
            @php($type = \Devuni\Notifier\Enums\AnnouncementTypeEnum::tryFrom((string) ($announcement['type'] ?? '')))
            @if (! empty($content))
                <div
                    class="notifier-announcement notifier-announcement--{{ $announcement['severity'] ?? 'info' }}{{ $type ? ' notifier-announcement--type-'.$type->value : '' }}"
                    role="status"
                    @if (! empty($announcement['id'])) data-announcement-id="{{ $announcement['id'] }}" @endif
                >
                    @if ($type?->getLabel())
                        <span class="notifier-announcement__type">{{ $type->getLabel() }}</span>
                    @endif{{ $content }}
                    @if (! empty($announcement['validity_label']))
                        <span class="notifier-announcement__validity">{{ $announcement['validity_label'] }}</span>
                    @endif
                </div>
            @endif
        @endforeach
        @if ($hidden > 0)
            <div class="notifier-announcement__more">+ {{ $hidden }} dalších oznámení</div>
        @endif
    </div>
@endif
