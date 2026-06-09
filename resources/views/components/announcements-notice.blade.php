@if (! empty($announcements))
    <div class="notifier-announcements">
        @foreach ($announcements as $announcement)
            @php($content = $announcement['content'] ?? null)
            @if (! empty($content))
                <div class="notifier-announcement notifier-announcement--{{ $announcement['severity'] ?? 'info' }}" role="status">
                    {{ $content }}
                </div>
            @endif
        @endforeach
    </div>
@endif
