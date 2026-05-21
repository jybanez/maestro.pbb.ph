<?php

declare(strict_types=1);

const MAESTRO_POPULATE_TOOL = 'populate_initial_data';
const MAESTRO_POPULATE_VERSION = '1.0.0';
const MAESTRO_DEFAULT_SOURCE = __DIR__ . '/../resources/data/maestro/applications.json';

function usage(): void
{
    fwrite(STDERR, "Usage: php tools/populate-initial-data.php --config <path> --report <path> [--mode initial|repair|refresh|demo] [--dry-run] [--verbose]\n");
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

function write_json(string $path, array $data): void
{
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

function read_json(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true);

    if (! is_array($decoded)) {
        throw new RuntimeException("Invalid JSON config: {$path}");
    }

    return $decoded;
}

function source_applications(array $populate): array
{
    $configured = $populate['applications'] ?? null;
    if (is_array($configured) && $configured !== []) {
        return [
            'applications' => merge_runtime_tokens($configured, $populate),
            'source' => [
                'id' => 'config',
                'path' => 'maestro.populate.applications',
                'status' => 'success',
                'default_used' => false,
            ],
        ];
    }

    $sourcePath = trim((string) ($populate['source'] ?? $populate['source_path'] ?? ''));
    if ($sourcePath === '') {
        $sourcePath = MAESTRO_DEFAULT_SOURCE;
    } elseif (! preg_match('/^[a-zA-Z]:[\\\\\\/]|^\\\\\\\\|^\\//', $sourcePath)) {
        $sourcePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sourcePath);
    }

    $source = read_json($sourcePath);
    $applications = is_array($source['applications'] ?? null) ? $source['applications'] : [];

    return [
        'applications' => merge_runtime_tokens($applications, $populate),
        'source' => [
            'id' => $sourcePath === MAESTRO_DEFAULT_SOURCE ? 'packaged_default' : 'configured_source',
            'path' => $sourcePath,
            'status' => 'success',
            'default_used' => $sourcePath === MAESTRO_DEFAULT_SOURCE,
        ],
    ];
}

function merge_runtime_tokens(array $applications, array $populate): array
{
    $overlays = runtime_token_overlays($populate);
    if ($overlays === []) {
        return $applications;
    }

    foreach ($applications as $index => $application) {
        if (! is_array($application)) {
            continue;
        }

        $appCode = (string) ($application['app_code'] ?? '');
        if ($appCode === '' || ! isset($overlays[$appCode])) {
            continue;
        }

        $existing = is_array($application['telemetry_tokens'] ?? null) ? $application['telemetry_tokens'] : [];
        $applications[$index]['telemetry_tokens'] = merge_tokens_by_label($existing, $overlays[$appCode]);
    }

    return $applications;
}

function runtime_token_overlays(array $populate): array
{
    $overlays = [];
    foreach (['telemetry_tokens', 'generated_telemetry_tokens', 'runtime_telemetry_tokens', 'application_tokens'] as $key) {
        if (! is_array($populate[$key] ?? null)) {
            continue;
        }

        foreach (normalize_token_overlay_entries($populate[$key]) as $entry) {
            $appCode = (string) ($entry['app_code'] ?? '');
            if ($appCode === '') {
                continue;
            }
            unset($entry['app_code']);
            $overlays[$appCode][] = $entry;
        }
    }

    return $overlays;
}

function normalize_token_overlay_entries(array $tokens): array
{
    $entries = [];
    foreach ($tokens as $key => $value) {
        if (is_string($key) && is_array($value)) {
            foreach (array_is_list($value) ? $value : [$value] as $token) {
                if (is_array($token)) {
                    $token['app_code'] = $token['app_code'] ?? $key;
                    $entries[] = $token;
                }
            }
            continue;
        }

        if (is_array($value)) {
            $entries[] = $value;
        }
    }

    return $entries;
}

function merge_tokens_by_label(array $baseTokens, array $overlayTokens): array
{
    $merged = [];
    foreach ($baseTokens as $token) {
        if (! is_array($token)) {
            continue;
        }
        $merged[(string) ($token['label'] ?? count($merged))] = $token;
    }

    foreach ($overlayTokens as $token) {
        if (! is_array($token)) {
            continue;
        }
        $label = (string) ($token['label'] ?? '');
        if ($label === '') {
            continue;
        }
        $merged[$label] = array_replace($merged[$label] ?? [], $token);
    }

    return array_values($merged);
}

function populate_config(array $config): array
{
    $populate = $config['maestro']['populate'] ?? $config['populate'] ?? [];

    return is_array($populate) ? $populate : [];
}

function token_hash_from_config(array $token): ?string
{
    $hash = trim((string) ($token['token_hash'] ?? ''));
    if ($hash !== '') {
        return $hash;
    }

    $plain = trim((string) ($token['plain_text_token'] ?? ''));
    if ($plain === '' || in_array(strtolower($plain), ['replace-with-kit-generated-token', 'replace-me', 'changeme', 'secret', 'password'], true)) {
        return null;
    }

    return hash('sha256', $plain);
}

function validate_population(array $populate): array
{
    $errors = [];
    $applications = $populate['applications'] ?? [];

    if (! is_array($applications)) {
        return [['id' => 'maestro.populate.applications', 'message' => 'applications must be an array.']];
    }

    $seen = [];
    foreach ($applications as $index => $application) {
        if (! is_array($application)) {
            $errors[] = ['id' => "applications.{$index}", 'message' => 'Application entry must be an object.'];
            continue;
        }

        $appCode = trim((string) ($application['app_code'] ?? ''));
        $displayName = trim((string) ($application['display_name'] ?? ''));
        if ($appCode === '') {
            $errors[] = ['id' => "applications.{$index}.app_code", 'message' => 'app_code is required.'];
        }
        if ($displayName === '') {
            $errors[] = ['id' => "applications.{$index}.display_name", 'message' => 'display_name is required.'];
        }
        if ($appCode !== '' && isset($seen[$appCode])) {
            $errors[] = ['id' => "applications.{$index}.app_code", 'message' => "Duplicate app_code {$appCode}."];
        }
        $seen[$appCode] = true;

        foreach (($application['telemetry_tokens'] ?? []) as $tokenIndex => $token) {
            if (! is_array($token)) {
                $errors[] = ['id' => "applications.{$index}.telemetry_tokens.{$tokenIndex}", 'message' => 'Token entry must be an object.'];
                continue;
            }
            if (trim((string) ($token['label'] ?? '')) === '') {
                $errors[] = ['id' => "applications.{$index}.telemetry_tokens.{$tokenIndex}.label", 'message' => 'Token label is required.'];
            }
            if (token_hash_from_config($token) === null && ! (bool) ($token['revoke'] ?? false) && ! token_allows_runtime_injection($token)) {
                $errors[] = ['id' => "applications.{$index}.telemetry_tokens.{$tokenIndex}.token", 'message' => 'Token must provide plain_text_token or token_hash; placeholders are not accepted.'];
            }
        }
    }

    return $errors;
}

function token_allows_runtime_injection(array $token): bool
{
    return (bool) ($token['runtime_injected'] ?? $token['kit_generated'] ?? $token['required'] ?? false);
}

function boot_laravel(): void
{
    require __DIR__ . '/../vendor/autoload.php';
    $app = require __DIR__ . '/../bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
}

$args = parse_args($argv);
$startedAt = date(DATE_ATOM);

if (! is_string($args['config']) || $args['config'] === '' || ! is_file($args['config'])) {
    usage();
    exit(2);
}

if (! is_string($args['report']) || $args['report'] === '') {
    usage();
    exit(2);
}

$mode = (string) $args['mode'];
if (! in_array($mode, ['initial', 'repair', 'refresh', 'demo'], true)) {
    usage();
    exit(3);
}

try {
    $config = read_json($args['config']);
    $populate = populate_config($config);
    $enabled = (bool) ($populate['enabled'] ?? true);
    $source = $enabled ? source_applications($populate) : ['applications' => [], 'source' => null];
    $applications = $source['applications'];
    $populate['applications'] = $applications;
    $errors = $enabled ? validate_population($populate) : [];
    $dryRun = (bool) $args['dry_run'] || (bool) ($populate['dry_run'] ?? false);
    $results = [];
    $sources = [
        [
            'id' => 'config',
            'path' => (string) $args['config'],
            'status' => $errors === [] ? 'success' : 'failed',
        ],
    ];
    if (is_array($source['source'] ?? null)) {
        $sources[] = $source['source'];
    }

    if (! $enabled) {
        $report = [
            'schema_version' => 1,
            'app' => 'pbb-maestro',
            'tool' => MAESTRO_POPULATE_TOOL,
            'version' => MAESTRO_POPULATE_VERSION,
            'run_id' => (string) ($config['kit']['run_id'] ?? ''),
            'mode' => $mode,
            'dry_run' => $dryRun,
            'status' => 'skipped',
            'started_at' => $startedAt,
            'finished_at' => date(DATE_ATOM),
            'summary' => 'Maestro population skipped because maestro.populate.enabled is false.',
            'sources' => $sources,
            'results' => [],
            'warnings' => [],
            'errors' => [],
        ];
        write_json($args['report'], $report);
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    if ($errors !== []) {
        $report = [
            'schema_version' => 1,
            'app' => 'pbb-maestro',
            'tool' => MAESTRO_POPULATE_TOOL,
            'version' => MAESTRO_POPULATE_VERSION,
            'run_id' => (string) ($config['kit']['run_id'] ?? ''),
            'mode' => $mode,
            'dry_run' => $dryRun,
            'status' => 'failed',
            'started_at' => $startedAt,
            'finished_at' => date(DATE_ATOM),
            'summary' => 'Maestro population config validation failed.',
            'sources' => $sources,
            'results' => [],
            'warnings' => [],
            'errors' => $errors,
        ];
        write_json($args['report'], $report);
        exit(2);
    }

    if (! $dryRun) {
        boot_laravel();
    }

    $overwrite = (bool) ($populate['options']['overwrite_existing'] ?? false);

    foreach ($applications as $applicationConfig) {
        $appCode = (string) $applicationConfig['app_code'];
        $existing = $dryRun ? null : App\Models\MaestroApplication::query()->where('app_code', $appCode)->first();
        $action = $existing === null ? 'insert' : ($overwrite ? 'update' : 'skip');

        $result = [
            'type' => 'maestro_application',
            'key' => $appCode,
            'action' => $action,
            'status' => 'success',
            'tokens' => [],
        ];

        if (! $dryRun && ($existing === null || $overwrite)) {
            $application = App\Models\MaestroApplication::query()->updateOrCreate(
                ['app_code' => $appCode],
                [
                    'display_name' => (string) $applicationConfig['display_name'],
                    'environment' => (string) ($applicationConfig['environment'] ?? 'production'),
                    'base_url' => $applicationConfig['base_url'] ?? null,
                    'is_active' => (bool) ($applicationConfig['is_active'] ?? true),
                    'meta_json' => is_array($applicationConfig['meta'] ?? null) ? $applicationConfig['meta'] : null,
                ]
            );
        } elseif (! $dryRun) {
            $application = $existing;
        }

        foreach (($applicationConfig['telemetry_tokens'] ?? []) as $tokenConfig) {
            $label = (string) $tokenConfig['label'];
            $tokenHash = token_hash_from_config($tokenConfig);
            $tokenAction = 'skip';

            if (! $dryRun && isset($application) && $tokenHash !== null) {
                $token = App\Models\MaestroTelemetryToken::query()
                    ->where('maestro_application_id', $application->id)
                    ->where('label', $label)
                    ->first();

                if ((bool) ($tokenConfig['revoke'] ?? false)) {
                    if ($token !== null && $token->revoked_at === null) {
                        $token->forceFill(['revoked_at' => now()])->save();
                        $tokenAction = 'revoke';
                    }
                } elseif ($token === null) {
                    App\Models\MaestroTelemetryToken::query()->create([
                        'maestro_application_id' => $application->id,
                        'label' => $label,
                        'token_hash' => $tokenHash,
                    ]);
                    $tokenAction = 'insert';
                } elseif ($overwrite) {
                    $token->forceFill([
                        'token_hash' => $tokenHash,
                        'revoked_at' => null,
                    ])->save();
                    $tokenAction = 'update';
                }
            } else {
                $tokenAction = (bool) ($tokenConfig['revoke'] ?? false) ? 'revoke' : ($overwrite ? 'upsert' : 'insert-if-missing');
            }

            $result['tokens'][] = [
                'label' => $label,
                'action' => $tokenAction,
                'status' => 'success',
                'token_supplied' => $tokenHash !== null,
            ];
        }

        $results[] = $result;
    }

    $report = [
        'schema_version' => 1,
        'app' => 'pbb-maestro',
        'tool' => MAESTRO_POPULATE_TOOL,
        'version' => MAESTRO_POPULATE_VERSION,
        'run_id' => (string) ($config['kit']['run_id'] ?? ''),
        'mode' => $mode,
        'dry_run' => $dryRun,
        'status' => 'success',
        'started_at' => $startedAt,
        'finished_at' => date(DATE_ATOM),
        'summary' => $dryRun ? 'Maestro population dry run completed.' : 'Maestro initial data populated.',
        'sources' => $sources,
        'results' => $results,
        'warnings' => [],
        'errors' => [],
    ];

    write_json($args['report'], $report);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    $report = [
        'schema_version' => 1,
        'app' => 'pbb-maestro',
        'tool' => MAESTRO_POPULATE_TOOL,
        'version' => MAESTRO_POPULATE_VERSION,
        'run_id' => '',
        'mode' => $mode,
        'dry_run' => (bool) $args['dry_run'],
        'status' => 'failed',
        'started_at' => $startedAt,
        'finished_at' => date(DATE_ATOM),
        'summary' => 'Maestro population failed.',
        'sources' => [
            [
                'id' => 'config',
                'path' => (string) $args['config'],
                'status' => 'failed',
            ],
        ],
        'results' => [],
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
