<?php

declare(strict_types=1);

namespace Devuni\Notifier\Enums;

/**
 * Announcement types the control plane sends alongside severity.
 *
 * Mirrors the server-side enum (lowercase wire values). NOTICE is the neutral
 * default and renders without a type chip — exactly like payloads from older
 * servers that do not send a type at all.
 */
enum AnnouncementTypeEnum: string
{
    case MAINTENANCE = 'maintenance';
    case OUTAGE = 'outage';
    case RELEASE = 'release';
    case NOTICE = 'notice';

    /**
     * Client-facing chip label; null = no chip.
     */
    public function getLabel(): ?string
    {
        return match ($this) {
            self::MAINTENANCE => 'Údržba',
            self::OUTAGE => 'Výpadek',
            self::RELEASE => 'Novinka',
            self::NOTICE => null,
        };
    }
}
