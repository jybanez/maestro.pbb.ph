<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/MaestroInstallerRuntime.php';

$status = MaestroInstallerRuntime::buildStatus();
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PBB Maestro Installer</title>
    <style>
        body { margin: 0; padding: 2rem; font-family: Segoe UI, Arial, sans-serif; background: #0b1220; color: #edf5fd; }
        main { max-width: 760px; margin: 0 auto; }
        code, pre { font-family: Consolas, monospace; }
        pre { padding: 1rem; overflow: auto; background: #101b2d; border: 1px solid #25364f; border-radius: 8px; }
        a { color: #7dd3fc; }
    </style>
</head>
<body>
<main>
    <h1>PBB Maestro Installer</h1>
    <p>This installer is primarily driven by the unattended Kit Setup CLI contract.</p>
    <pre><?php echo htmlspecialchars(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?></pre>
    <p>Run <code>php installer/install-run.php --config installer/docs/maestro-install.sample.json --report storage/app/installer/install-report.json --mode preflight</code> to validate a config.</p>
</main>
</body>
</html>
