<?php

declare(strict_types=1);

namespace CordisPhp\Tests\Support;

use RuntimeException;

final class YamlFixture
{
    public static function create(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cordis-php-');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary YAML fixture.');
        }

        self::overwrite($path, $contents);

        return $path;
    }

    public static function overwrite(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('Could not write YAML fixture "%s".', $path));
        }
    }

    public static function remove(string $path): void
    {
        if (is_file($path) && ! unlink($path)) {
            throw new RuntimeException(sprintf('Could not remove YAML fixture "%s".', $path));
        }
    }
}
