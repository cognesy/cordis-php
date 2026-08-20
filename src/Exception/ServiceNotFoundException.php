<?php

declare(strict_types=1);

namespace CordisPhp\Exception;

final class ServiceNotFoundException extends CordisException
{
    public function __construct(string $id)
    {
        parent::__construct(sprintf('Service "%s" is not available in this context.', $id));
    }
}
