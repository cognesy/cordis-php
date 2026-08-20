<?php

declare(strict_types=1);

namespace CordisPhp\Runtime;

enum FiberState: string
{
    case Pending = 'pending';
    case Loading = 'loading';
    case Active = 'active';
    case Failed = 'failed';
    case Unloading = 'unloading';
    case Disposed = 'disposed';
}
