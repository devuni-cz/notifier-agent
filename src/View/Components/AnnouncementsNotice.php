<?php

declare(strict_types=1);

namespace Devuni\Notifier\View\Components;

use Devuni\Notifier\Services\AnnouncementsService;
use Illuminate\View\Component;

/**
 * Renders this site's active maintenance/announcement announcements as notice blocks.
 *
 * Drop `<x-notifier-announcements-notice />` anywhere in your own dashboard. When the
 * `announcements` feature is disabled or there is nothing to show, it renders nothing.
 * Markup is intentionally unstyled - target the `.notifier-announcement` class (and the
 * `.notifier-announcement--{severity}` modifier) from your own CSS.
 */
final class AnnouncementsNotice extends Component
{
    /** @var list<array<string, mixed>> */
    public array $announcements;

    public function __construct(AnnouncementsService $announcements)
    {
        $this->announcements = $announcements->activeAnnouncements();
    }

    public function render(): string
    {
        // Returning the view name (rather than a view() instance) keeps this
        // analysable for a package-namespaced view; the component's public
        // properties are passed to the view either way.
        return 'notifier::components.announcements-notice';
    }
}
