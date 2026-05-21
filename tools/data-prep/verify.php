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

    boot_laravel();

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

        $results[] = [
            'id' => "{$appCode}_application_profile",
            'type' => 'maestro_application_profile',
            'key' => $appCode,
            'status' => $applicationOk && count(array_filter($tokenResults, static fn (array $token): bool => $token['status'] !== 'success')) === 0 ? 'success' : 'failed',
            'application_present' => $application !== null,
            'is_active' => $application !== null ? (bool) $application->is_active : false,
            'tokens' => $tokenResults,
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
            : 'Maestro Data Prep verification found missing application profiles or token hashes.',
        'sources' => [
            [
                'id' => 'config',
                'path' => (string) $args['config'],
                'status' => 'success',
            ],
        ],
        'results' => $results,
        'outputs' => [],
        'warnings' => [],
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
