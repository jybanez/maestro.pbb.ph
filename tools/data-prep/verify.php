<?php

declare(strict_types=1);

const MAESTRO_DATA_PREP_VERIFY_TOOL = 'data_prep_verify';
const MAESTRO_DATA_PREP_VERIFY_VERSION = '1.0.0';
const MAESTRO_DATA_PREP_DEFAULT_SOURCE = __DIR__ . '/../../resources/data/maestro/applications.json';

function usage(): void
{
    fwrite(STDERR, "Usage: php tools/data-prep/verify.php --config <path> --report <path> [--mode initial|repair|refresh|demo] [--dry-run] [--verbose]\n");
}

function parse_args(array $argv): array
{
    $args = [
        'config' => null,
        'report' => null,
        'mode' => 'initial',
        'dry_run' => false,
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
                $args['mode'] = $argv[++$i] ?? 'initial';
                break;
            case '--dry-run':
                $args['dry_run'] = true;
                break;
            case '--verbose':
                $args['verbose'] = true;
                break;
            case '--help':
            case '-h':
                usage();
                exit(0);
            default:
                fwrite(STDERR, "Unknown argument: {$arg}\n");
                usage();
                exit(2);
        }
    }

    return $args;
}

function read_json(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true);

    if (! is_array($decoded)) {
        throw new RuntimeException("Invalid JSON file: {$path}");
    }

    return $decoded;
}

function write_json(string $path, array $data): void
{
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

function verify_config(array $config): array
{
    $verify = $config['maestro']['data_prep']['verify'] ?? $config['maestro']['verify'] ?? $config['verify'] ?? [];

    return is_array($verify) ? $verify : [];
}

function expected_applications(array $config, array $verify): array
{
    $configured = $verify['applications'] ?? $config['maestro']['populate']['applications'] ?? null;
    if (is_array($configured) && $configured !== []) {
        return $configured;
    }

    $sourcePath = trim((string) ($verify['source'] ?? $verify['source_path'] ?? $config['maestro']['populate']['source'] ?? $config['maestro']['populate']['source_path'] ?? ''));
    if ($sourcePath === '') {
        $sourcePath = MAESTRO_DATA_PREP_DEFAULT_SOURCE;
    } elseif (! preg_match('/^[a-zA-Z]:[\\\\\\/]|^\\\\\\\\|^\\//', $sourcePath)) {
        $sourcePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sourcePath);
    }

    $source = read_json($sourcePath);

    return is_array($source['applications'] ?? null) ? $source['applications'] : [];
}

function expected_tokens(array $application): array
{
    $tokens = [];
    foreach (($application['telemetry_tokens'] ?? []) as $token) {
        if (! is_array($token)) {
            continue;
        }

        $label = trim((string) ($token['label'] ?? ''));
        if ($label !== '') {
            $tokens[] = $label;
        }
    }

    return $tokens;
}

function verify_options(array $verify): array
{
    return [
        'freshness_threshold_seconds' => max(1, (int) ($verify['freshness_threshold_seconds'] ?? config('maestro.status.stale_threshold_seconds'))),
        'require_fresh_heartbeat' => (bool) ($verify['require_fresh_heartbeat'] ?? false),
    ];
}

function boot_laravel(): void
{
    require __DIR__ . '/../../vendor/autoload.php';
    $app = require __DIR__ . '/../../bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
}

$args = parse_args($argv);
$startedAt = date(DATE_ATOM);
$mode = (string) $args['mode'];

if (! is_string($args['config']) || $args['config'] === '' || ! is_file($args['config'])) {
    usage();
    exit(2);
}

if (! is_string($args['report']) || $args['report'] === '') {
    usage();
    exit(2);
}

if (! in_array($mode, ['initial', 'repair', 'refresh', 'demo'], true)) {
    usage();
    exit(3);
}

try {
    $config = read_json($args['config']);
    $verify = verify_config($config);
    $expectedApplications = expected_applications($config, $verify);
    $results = [];
    $errors = [];
    $warnings = [];

    boot_laravel();
    $options = verify_options($verify);
    $now = Illuminate\Support\Carbon::now();

    foreach ($expectedApplications as $applicationConfig) {
        if (! is_array($applicationConfig)) {
            continue;
        }

        $appCode = trim((string) ($applicationConfig['app_code'] ?? ''));
        if ($appCode === '') {
            continue;
        }

        $application = App\Models\MaestroApplication::query()
            ->where('app_code', $appCode)
            ->first();

        $applicationOk = $application !== null && (bool) $application->is_active;
        $expectedEnvironment = trim((string) ($applicationConfig['environment'] ?? ''));
        $environmentOk = $application !== null && ($expectedEnvironment === '' || (string) $application->environment === $expectedEnvironment);
        $tokenResults = [];
        $expectedTokenLabels = expected_tokens($applicationConfig);

        foreach ($expectedTokenLabels as $label) {
            $token = $application === null ? null : App\Models\MaestroTelemetryToken::query()
                ->where('maestro_application_id', $application->id)
                ->where('label', $label)
                ->whereNull('revoked_at')
                ->first();

            $tokenOk = $token !== null && is_string($token->token_hash) && trim($token->token_hash) !== '';
            $tokenResults[] = [
                'label' => $label,
                'status' => $tokenOk ? 'success' : 'failed',
                'active_hash_present' => $tokenOk,
            ];

            if (! $tokenOk) {
                $errors[] = [
                    'id' => "{$appCode}.telemetry_tokens.{$label}",
                    'message' => "Missing active telemetry token hash for {$appCode} token {$label}.",
                ];
            }
        }

        if (! $applicationOk) {
            $errors[] = [
                'id' => "{$appCode}.application",
                'message' => "Missing active Maestro application profile for {$appCode}.",
            ];
        }

        if ($application !== null && ! $environmentOk) {
            $errors[] = [
                'id' => "{$appCode}.environment",
                'message' => "Maestro application {$appCode} environment does not match expected value {$expectedEnvironment}.",
            ];
        }

        $heartbeat = heartbeat_status($application, $appCode, $applicationOk, $tokenResults, $now, $options['freshness_threshold_seconds']);
        if ($heartbeat['status'] !== 'fresh') {
            $message = "Maestro heartbeat for {$appCode} is {$heartbeat['status']}.";
            if ($options['require_fresh_heartbeat']) {
                $errors[] = [
                    'id' => "{$appCode}.heartbeat",
                    'message' => $message,
                ];
            } else {
                $warnings[] = [
                    'id' => "{$appCode}.heartbeat",
                    'message' => $message,
                ];
            }
        }

        $results[] = [
            'id' => "{$appCode}_application_profile",
            'type' => 'maestro_application_profile',
            'key' => $appCode,
            'status' => $applicationOk && $environmentOk && count(array_filter($tokenResults, static fn (array $token): bool => $token['status'] !== 'success')) === 0 ? 'success' : 'failed',
            'application_present' => $application !== null,
            'is_active' => $application !== null ? (bool) $application->is_active : false,
            'environment' => $application !== null ? (string) $application->environment : null,
            'expected_environment' => $expectedEnvironment !== '' ? $expectedEnvironment : null,
            'tokens' => $tokenResults,
            'heartbeat' => $heartbeat,
        ];
    }

    $status = $errors === [] ? 'success' : 'failed';
    $report = [
        'schema_version' => 1,
        'app' => 'pbb-maestro',
        'tool' => MAESTRO_DATA_PREP_VERIFY_TOOL,
        'version' => MAESTRO_DATA_PREP_VERIFY_VERSION,
        'run_id' => (string) ($config['kit']['run_id'] ?? ''),
        'mode' => $mode,
        'dry_run' => (bool) $args['dry_run'],
        'status' => $status,
        'started_at' => $startedAt,
        'finished_at' => date(DATE_ATOM),
        'summary' => $status === 'success'
            ? 'Maestro Data Prep verification passed.'
            : 'Maestro Data Prep verification found missing profile, token, environment, or heartbeat requirements.',
        'sources' => [
            [
                'id' => 'config',
                'path' => (string) $args['config'],
                'status' => 'success',
            ],
        ],
        'results' => $results,
        'outputs' => [],
        'warnings' => $warnings,
        'errors' => $errors,
    ];

    write_json($args['report'], $report);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($status === 'success' ? 0 : 1);
} catch (Throwable $exception) {
    $report = [
        'schema_version' => 1,
        'app' => 'pbb-maestro',
        'tool' => MAESTRO_DATA_PREP_VERIFY_TOOL,
        'version' => MAESTRO_DATA_PREP_VERIFY_VERSION,
        'run_id' => '',
        'mode' => $mode,
        'dry_run' => (bool) $args['dry_run'],
        'status' => 'failed',
        'started_at' => $startedAt,
        'finished_at' => date(DATE_ATOM),
        'summary' => 'Maestro Data Prep verification failed.',
        'sources' => [
            [
                'id' => 'config',
                'path' => (string) $args['config'],
                'status' => 'failed',
            ],
        ],
        'results' => [],
        'outputs' => [],
        'warnings' => [],
        'errors' => [
            [
                'id' => 'exception',
                'message' => $exception->getMessage(),
            ],
        ],
    ];
    write_json($args['report'], $report);
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

function heartbeat_status(?App\Models\MaestroApplication $application, string $appCode, bool $applicationOk, array $tokenResults, Illuminate\Support\Carbon $now, int $thresholdSeconds): array
{
    $tokenOk = count(array_filter($tokenResults, static fn (array $token): bool => $token['status'] !== 'success')) === 0;

    if (! $applicationOk || ! $tokenOk) {
        return [
            'app_code' => $appCode,
            'status' => 'rejected',
            'last_seen_at' => null,
            'age_seconds' => null,
            'freshness_threshold_seconds' => $thresholdSeconds,
            'worker_id' => null,
            'worker_status' => null,
        ];
    }

    $worker = $application?->workers()
        ->whereNotNull('last_heartbeat_at')
        ->orderByDesc('last_heartbeat_at')
        ->first();

    if ($worker === null || $worker->last_heartbeat_at === null) {
        return [
            'app_code' => $appCode,
            'status' => 'missing',
            'last_seen_at' => null,
            'age_seconds' => null,
            'freshness_threshold_seconds' => $thresholdSeconds,
            'worker_id' => null,
            'worker_status' => null,
        ];
    }

    $lastSeenAt = $worker->last_heartbeat_at;
    $ageSeconds = (int) $lastSeenAt->diffInSeconds($now);
    $workerStatus = app(App\Services\Maestro\WorkerStatusResolver::class)->resolveForWorker($worker, $now);
    $heartbeatStatus = $ageSeconds <= $thresholdSeconds && ! in_array($workerStatus, ['stale', 'stopped'], true)
        ? 'fresh'
        : 'stale';

    return [
        'app_code' => $appCode,
        'status' => $heartbeatStatus,
        'last_seen_at' => $lastSeenAt->toISOString(),
        'age_seconds' => $ageSeconds,
        'freshness_threshold_seconds' => $thresholdSeconds,
        'worker_id' => (string) $worker->worker_id,
        'worker_status' => $workerStatus,
    ];
}
