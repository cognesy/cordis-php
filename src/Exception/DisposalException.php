<?php

declare(strict_types=1);

namespace CordisPhp\Exception;

use Throwable;

final class DisposalException extends CordisException
{
    /**
     * @param list<Throwable> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(sprintf('%d error(s) occurred while disposing a scope.', count($errors)));
    }
}
