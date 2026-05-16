<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/MaestroInstallerRuntime.php';

function maestroInstallerUsage(): void
{
    fwrite(STDERR, "Usage: php installer/install-run.php --config <path> --report <path> [--mode fresh|upgrade|repair|preflight] [--dry-run] [--no-service-register] [--verbose]\n");
}

function maestroInstallerArgs(array $argv): array
{
    $args = [
        'config' => null,
        'report' => null,
        'mode' => null,
        'dry_run' => false,
        'no_service_register' => false,
        'verbose' => false,
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = (string) $argv[$i];
        switch ($arg) {
            case '--config':
                $args['config'] = $argv[++$i] ?? null;
                break;
            case '--report':
                $args['report'] = $argv[++$i] ?? null;
                break;
            case '--mode':
                $args['mode'] = $argv[++$i] ?? null;
                break;
            case '--dry-run':
                $args['dry_run'] = true;
                break;
            case '--no-service-register':
                $args['no_service_register'] = true;
                break;
            case '--verbose':
                $args['verbose'] = true;
                break;
            case '--help':
            case '-h':
                maestroInstallerUsage();
                exit(0);
            default:
                fwrite(STDERR, "Unknown argument: {$arg}\n");
                maestroInstallerUsage();
                exit(3);
        }
    }

    return $args;
}

$args = maestroInstallerArgs($argv);
$startedAt = date(DATE_ATOM);

if (! is_string($args['config']) || $args['config'] === '' || ! is_file($args['config'])) {
    fwrite(STDERR, "Config file is required and must exist.\n");
    maestroInstallerUsage();
    exit(2);
}

if (! is_string($args['report']) || $args['report'] === '') {
    fwrite(STDERR, "Report path is required.\n");
    maestroInstallerUsage();
    exit(2);
}

$rawConfig = json_decode((string) file_get_contents($args['config']), true);
if (! is_array($rawConfig)) {
    $report = MaestroInstallerRuntime::buildReport(MaestroInstallerRuntime::defaultConfig(), 'failed', [
        ['id' => 'config', 'status' => 'failed', 'message' => 'Config file is not valid JSON.'],
    ], [
        'started_at' => $startedAt,
        'errors' => [['id' => 'config.invalid_json', 'message' => 'Config file is not valid JSON.']],
    ]);
    MaestroInstallerRuntime::externalReportWrite($args['report'], $report);
    exit(2);
}

$config = MaestroInstallerRuntime::normalizeConfig($rawConfig);
if (is_string($args['mode']) && $args['mode'] !== '') {
    $config['mode'] = $args['mode'];
}
if ($args['no_service_register']) {
    $config['services']['registration_mode'] = 'generate';
}

$mode = (string) ($config['mode'] ?? 'fresh');
$steps = [];

try {
    $errors = MaestroInstallerRuntime::validateConfig($config, ! in_array($mode, ['preflight', 'repair'], true));
    if ($errors !== []) {
        $report = MaestroInstallerRuntime::buildReport($config, 'failed', [
            ['id' => 'config', 'status' => 'failed', 'message' => 'Installer configuration is incomplete.'],
        ], [
            'started_at' => $startedAt,
            'errors' => $errors,
        ]);
        MaestroInstallerRuntime::externalReportWrite($args['report'], $report);
        exit(2);
    }

    $checks = MaestroInstallerRuntime::buildPreflightChecks($config);
    $preflightFailed = MaestroInstallerRuntime::hasBlockingFailures($checks);
    $steps[] = ['id' => 'preflight', 'status' => $preflightFailed ? 'failed' : 'success', 'message' => $preflightFailed ? 'Blocking preflight checks failed.' : 'Preflight checks passed.'];

    if ($mode === 'preflight' || $args['dry_run']) {
        $report = MaestroInstallerRuntime::buildReport($config, $preflightFailed ? 'failed' : 'success', $steps, [
            'started_at' => $startedAt,
            'summary' => $preflightFailed ? 'Preflight failed.' : 'Preflight passed.',
            'errors' => $preflightFailed ? $checks : [],
            'merge' => ['preflight' => ['status' => $preflightFailed ? 'failed' : 'passed', 'checks' => $checks]],
        ]);
        MaestroInstallerRuntime::externalReportWrite($args['report'], $report);
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($preflightFailed ? 1 : 0);
    }

    if ($preflightFailed) {
        $report = MaestroInstallerRuntime::buildReport($config, 'failed', $steps, [
            'started_at' => $startedAt,
            'summary' => 'Install stopped before mutation because preflight failed.',
            'errors' => $checks,
        ]);
        MaestroInstallerRuntime::externalReportWrite($args['report'], $report);
        exit(1);
    }

    MaestroInstallerRuntime::writeState([
        'schema_version' => 1,
        'state' => $mode === 'repair' ? 'repairing' : ($mode === 'upgrade' ? 'upgrading' : 'installing'),
        'started_at' => $startedAt,
        'config' => [
            'mode' => $mode,
            'app_url' => $config['app']['app_url'],
            'install_path' => $config['app']['install_path'],
        ],
    ]);

    $env = MaestroInstallerRuntime::writeEnvironment($config);
    $steps[] = ['id' => 'environment', 'status' => ($env['skipped'] ?? false) ? 'skipped' : 'success', 'message' => ($env['skipped'] ?? false) ? '.env write skipped.' : '.env written.'];

    $migrations = MaestroInstallerRuntime::runMigrations($config);
    if (($migrations['exit_code'] ?? 1) !== 0) {
        throw new RuntimeException('Migrations failed: ' . trim((string) (($migrations['stderr'] ?? '') ?: ($migrations['stdout'] ?? ''))));
    }
    $steps[] = ['id' => 'migrate', 'status' => ($migrations['skipped'] ?? false) ? 'skipped' : 'success', 'message' => ($migrations['skipped'] ?? false) ? 'Migrations skipped.' : 'Database migrations completed.'];

    $seeders = MaestroInstallerRuntime::runSeeders($config);
    if (($seeders['exit_code'] ?? 1) !== 0) {
        throw new RuntimeException('Seeders failed: ' . trim((string) (($seeders['stderr'] ?? '') ?: ($seeders['stdout'] ?? ''))));
    }
    $steps[] = ['id' => 'seed', 'status' => ($seeders['skipped'] ?? false) ? 'skipped' : 'success', 'message' => ($seeders['skipped'] ?? false) ? 'Seeders skipped.' : 'Seeders completed.'];

    $admin = MaestroInstallerRuntime::bootstrapAdmin($config);
    $steps[] = ['id' => 'admin', 'status' => ($admin['skipped'] ?? false) ? 'skipped' : 'success', 'message' => ($admin['skipped'] ?? false) ? 'Admin bootstrap skipped.' : 'Admin account is present.'];

    $optimize = MaestroInstallerRuntime::optimizeRuntime($config);
    $steps[] = ['id' => 'optimize', 'status' => ($optimize['skipped'] ?? false) ? 'skipped' : 'success', 'message' => ($optimize['skipped'] ?? false) ? 'Runtime cache skipped.' : 'Runtime cache commands completed.'];

    $service = MaestroInstallerRuntime::writeServiceArtifact($config);
    $steps[] = ['id' => 'services', 'status' => 'warning', 'message' => 'Scheduler service artifact generated; host registration is intentionally left to Kit Setup or the operator.'];

    $validation = MaestroInstallerRuntime::runValidation($config);
    $validationFailed = array_values(array_filter($validation, static fn (array $item): bool => $item['status'] === 'failed'));
    $status = $validationFailed === [] ? 'success' : 'warning';
    $steps[] = ['id' => 'validate', 'status' => $validationFailed === [] ? 'success' : 'warning', 'message' => $validationFailed === [] ? 'Local validation passed.' : 'Local validation completed with warnings.'];

    $manifest = MaestroInstallerRuntime::buildManifest($config, $service, $status === 'success' ? 'healthy' : 'degraded');
    MaestroInstallerRuntime::writeManifest($manifest);

    $report = MaestroInstallerRuntime::buildReport($config, $status, $steps, [
        'started_at' => $startedAt,
        'summary' => $status === 'success' ? 'PBB Maestro installed successfully.' : 'PBB Maestro installed with warnings.',
        'warnings' => $validationFailed,
        'merge' => [
            'environment' => [
                'path' => $env['path'] ?? null,
                'backup_path' => $env['backup_path'] ?? null,
                'generated_app_key' => $env['generated_app_key'] ?? false,
            ],
            'database' => [
                'migrations_ran' => ! ($migrations['skipped'] ?? false),
                'seeders_ran' => ! ($seeders['skipped'] ?? false),
            ],
            'admin' => [
                'email' => $admin['email'] ?? null,
                'created' => $admin['created'] ?? false,
                'overwritten' => $admin['overwritten'] ?? false,
            ],
            'services' => [
                [
                    'id' => 'pbb-maestro-scheduler',
                    'status' => 'artifact-generated',
                    'message' => 'Scheduler artifact generated.',
                    'artifact' => $service['artifact'] ?? null,
                ],
            ],
            'validation' => $validation,
        ],
    ]);

    MaestroInstallerRuntime::writeReport($report);
    MaestroInstallerRuntime::externalReportWrite($args['report'], $report);
    MaestroInstallerRuntime::writeState([
        'schema_version' => 1,
        'state' => $status === 'success' ? 'installed' : 'failed',
        'finished_at' => date(DATE_ATOM),
        'manifest' => MaestroInstallerRuntime::manifestPath(),
        'report' => MaestroInstallerRuntime::reportPath(),
    ]);

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($status === 'success' ? 0 : 5);
} catch (Throwable $exception) {
    $steps[] = ['id' => 'install', 'status' => 'failed', 'message' => $exception->getMessage()];
    $report = MaestroInstallerRuntime::buildReport($config, 'failed', $steps, [
        'started_at' => $startedAt,
        'summary' => 'PBB Maestro installer failed.',
        'errors' => [['id' => 'install.exception', 'message' => $exception->getMessage()]],
    ]);
    MaestroInstallerRuntime::writeReport($report);
    MaestroInstallerRuntime::externalReportWrite($args['report'], $report);
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
