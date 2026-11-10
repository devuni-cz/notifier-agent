<?php

declare(strict_types=1);

namespace Devuni\Notifier\Enums;

enum SealStatusEnum
{
    case Ok;
    case Unsealed;
    case Tampered;
    case WrongPassword;
    case WrongType;
    case WrongSite;
}
