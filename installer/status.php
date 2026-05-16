<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/MaestroInstallerRuntime.php';

echo json_encode(MaestroInstallerRuntime::buildStatus(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
