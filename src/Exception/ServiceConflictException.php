<?php

declare(strict_types=1);

namespace CordisPhp\Exception;

final class ServiceConflictException extends CordisException
{
    public function __construct(string $id)
    {
        parent::__construct(sprintf('Service "%s" already has a live provider in this realm.', $id));
    }
}
