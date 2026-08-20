#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
passthru('php ' . escapeshellarg($root . '/ops/bin/ops.php') . ' doctor', $exitCode);
exit($exitCode);
