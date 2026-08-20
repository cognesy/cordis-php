<?php

declare(strict_types=1);

namespace CordisPhp\Runtime;

enum ServiceChangeKind: string
{
    case Provided = 'provided';
    case Removed = 'removed';
}
