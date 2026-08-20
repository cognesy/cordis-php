<?php

declare(strict_types=1);

namespace CordisPhp\Event;

enum DispatchMode: string
{
    case Emit = 'emit';
    case Parallel = 'parallel';
    case Serial = 'serial';
    case Bail = 'bail';
    case Waterfall = 'waterfall';
}
