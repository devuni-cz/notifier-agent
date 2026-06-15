{{-- Auto-injected into Filament panels via a render hook (see NotifierServiceProvider). --}}
{{-- Self-contained styles + .notifier-* classes so nothing depends on the host's Tailwind build. --}}
@if (! empty($announcements))
    <style>
        .notifier-announcements { display: flex; flex-direction: column; gap: .5rem; padding: 1rem 1.5rem 0; }
        .notifier-announcement { border-radius: .5rem; border: 1px solid transparent; padding: .625rem 1rem; font-size: .875rem; line-height: 1.25rem; font-weight: 500; }
        .notifier-announcement__type { font-weight: 700; font-size: .6875rem; text-transform: uppercase; letter-spacing: .05em; margin-right: .5rem; opacity: .85; }
        .notifier-announcement__validity { display: block; margin-top: .25rem; font-size: .75rem; font-weight: 400; opacity: .7; }
        .notifier-announcement__more { display: block; padding: .25rem 1.5rem 0; font-size: .75rem; opacity: .6; }
        .notifier-announcement--critical { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
        .notifier-announcement--high     { background: #fff7ed; color: #9a3412; border-color: #fed7aa; }
        .notifier-announcement--medium   { background: #fffbeb; color: #92400e; border-color: #fde68a; }
        .notifier-announcement--low      { background: #f0f9ff; color: #075985; border-color: #bae6fd; }
        .notifier-announcement--info     { background: #f9fafb; color: #374151; border-color: #e5e7eb; }
        .dark .notifier-announcement--critical { background: rgba(248,113,113,.10); color: #fca5a5; border-color: rgba(248,113,113,.30); }
        .dark .notifier-announcement--high     { background: rgba(251,146,60,.10);  color: #fdba74; border-color: rgba(251,146,60,.30); }
        .dark .notifier-announcement--medium   { background: rgba(251,191,36,.10);  color: #fcd34d; border-color: rgba(251,191,36,.30); }
        .dark .notifier-announcement--low      { background: rgba(56,189,248,.10);  color: #7dd3fc; border-color: rgba(56,189,248,.30); }
        .dark .notifier-announcement--info     { background: rgba(255,255,255,.05); color: #d1d5db; border-color: rgba(255,255,255,.10); }
    </style>

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
                    role="status"
                    class="notifier-announcement notifier-announcement--{{ $announcement['severity'] ?? 'info' }}{{ $type ? ' notifier-announcement--type-'.$type->value : '' }}"
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
