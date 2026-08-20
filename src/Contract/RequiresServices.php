<?php

declare(strict_types=1);

namespace CordisPhp\Contract;

interface RequiresServices
{
    /** @return list<string> */
    public static function requiredServices(): array;
}
