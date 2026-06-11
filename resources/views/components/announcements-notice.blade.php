@if (! empty($announcements))
    <div class="notifier-announcements">
        @foreach ($announcements as $announcement)
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
                </div>
            @endif
        @endforeach
    </div>
@endif
