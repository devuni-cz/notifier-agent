<?php

declare(strict_types=1);

namespace Devuni\Notifier\Enums;

enum BackupTypeEnum: string
{
    case Database = 'backup_database';
    case Storage = 'backup_storage';
}
