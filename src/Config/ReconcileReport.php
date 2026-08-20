<?php

declare(strict_types=1);

namespace CordisPhp\Config;

final readonly class ReconcileReport
{
    /**
     * @param list<string> $mounted
     * @param list<string> $updated
     * @param list<string> $disposed
     * @param list<string> $unchanged
     * @param list<EntryFailure> $failed
     */
    public function __construct(
        public array $mounted = [],
        public array $updated = [],
        public array $disposed = [],
        public array $unchanged = [],
        public array $failed = [],
    ) {
    }

    public function isQuiet(): bool
    {
        return $this->mounted === []
            && $this->updated === []
            && $this->disposed === []
            && $this->failed === [];
    }
}
