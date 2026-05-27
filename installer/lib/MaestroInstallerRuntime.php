<?php

declare(strict_types=1);

final class MaestroInstallerRuntime
{
    private const APP_ID = 'pbb-maestro';
    private const APP_NAME = 'PBB Maestro';
    private const GENERATED_DIR = 'generated';

    public static function rootPath(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function storageDir(): string
    {
        return self::rootPath() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'installer';
    }

    public static function generatedDir(): string
    {
        $dir = self::storageDir() . DIRECTORY_SEPARATOR . self::GENERATED_DIR;
        self::ensureDir($dir);

        return $dir;
    }

    public static function reportPath(): string
    {
        return self::storageDir() . DIRECTORY_SEPARATOR . 'install-report.json';
    }

    public static function manifestPath(): string
    {
        return self::storageDir() . DIRECTORY_SEPARATOR . 'install-manifest.json';
    }

    public static function statePath(): string
    {
        return self::storageDir() . DIRECTORY_SEPARATOR . 'state.json';
    }

    public static function logPath(): string
    {
        return self::storageDir() . DIRECTORY_SEPARATOR . 'install.log';
    }

    public static function releaseMetadata(): array
    {
        $path = self::rootPath() . DIRECTORY_SEPARATOR . 'release.json';
        if (! is_file($path)) {
            return [
                'schema_version' => 1,
                'app' => self::APP_ID,
                'name' => self::APP_NAME,
                'version' => '0.0.0-dev',
                'display_version' => 'dev',
            ];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function defaultConfig(): array
    {
        $targetOs = strtolower(PHP_OS_FAMILY) === 'windows' ? 'windows' : 'linux';

        return [
            'schema_version' => 1,
            'mode' => 'fresh',
            'kit' => [
                'run_id' => '',
                'node_id' => '',
                'operator' => '',
                'timezone' => date_default_timezone_get(),
            ],
            'app' => [
                'install_path' => self::rootPath(),
                'public_path' => self::rootPath() . DIRECTORY_SEPARATOR . 'public',
                'app_url' => 'https://maestro.pbb.ph',
                'app_env' => 'production',
                'app_debug' => false,
                'force_https' => true,
            ],
            'database' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'pbb_maestro',
                'username' => 'root',
                'password' => '',
            ],
            'admin' => [
                'strategy' => 'create_if_missing',
                'name' => 'PBB Administrator',
                'email' => 'admin@pbb.local',
                'password' => '',
                'must_change_password' => false,
                'overwrite_existing' => false,
            ],
            'maestro' => [
                'starting_threshold_seconds' => 15,
                'stale_threshold_seconds' => 45,
                'clock_skew_threshold_seconds' => 60,
                'telemetry_token_header' => 'X-Telemetry-Token',
                'telemetry_trace' => false,
                'telemetry_token_last_used_at_update_interval_seconds' => 60,
                'telemetry_slow_request_threshold_ms' => 1000,
            ],
            'services' => [
                'target_os' => $targetOs,
                'manager' => $targetOs === 'windows' ? 'scheduled-task' : 'systemd',
                'startup_mode' => 'automatic',
                'registration_mode' => 'generate',
            ],
            'options' => [
                'run_migrations' => true,
                'seed_initial_data' => true,
                'write_env' => true,
                'cache_config' => true,
                'validate_after_install' => true,
                'overwrite_env' => false,
                'telemetry_smoke' => false,
            ],
        ];
    }

    public static function normalizeConfig(array $input): array
    {
        if (isset($input['services']) && is_array($input['services'])) {
            $services = $input['services'];
            $input['services'] = array_replace_recursive(self::defaultConfig()['services'], [
                'target_os' => $services['target_os'] ?? $services['targetOs'] ?? null,
                'manager' => $services['manager'] ?? $services['service_manager'] ?? null,
                'startup_mode' => $services['startup_mode'] ?? null,
                'registration_mode' => $services['registration_mode'] ?? null,
            ]);
        }

        $config = array_replace_recursive(self::defaultConfig(), $input);

        $registrationMode = strtolower((string) ($config['services']['registration_mode'] ?? 'generate'));
        if ($registrationMode === 'template') {
            $registrationMode = 'generate';
        }
        $config['services']['registration_mode'] = $registrationMode;

        return $config;
    }

    public static function validateConfig(array $config, bool $requireAdminPassword): array
    {
        $errors = [];

        if (! in_array((string) ($config['mode'] ?? ''), ['fresh', 'upgrade', 'repair', 'preflight'], true)) {
            $errors[] = ['id' => 'mode', 'message' => 'Mode must be fresh, upgrade, repair, or preflight.'];
        }

        if (trim((string) ($config['app']['install_path'] ?? '')) === '') {
            $errors[] = ['id' => 'app.install_path', 'message' => 'Install path is required.'];
        }

        foreach (self::boundaryValidationErrors($config) as $error) {
            $errors[] = $error;
        }

        if (! filter_var((string) ($config['app']['app_url'] ?? ''), FILTER_VALIDATE_URL)) {
            $errors[] = ['id' => 'app.app_url', 'message' => 'APP_URL must be a valid URL.'];
        }

        foreach (['host', 'database', 'username'] as $field) {
            if (trim((string) ($config['database'][$field] ?? '')) === '') {
                $errors[] = ['id' => "database.{$field}", 'message' => "Database {$field} is required."];
            }
        }

        if (! filter_var((string) ($config['admin']['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            $errors[] = ['id' => 'admin.email', 'message' => 'Admin email must be valid.'];
        }

        if (trim((string) ($config['admin']['name'] ?? '')) === '') {
            $errors[] = ['id' => 'admin.name', 'message' => 'Admin name is required.'];
        }

        $adminStrategy = (string) ($config['admin']['strategy'] ?? 'create_if_missing');
        if ($adminStrategy !== 'create_if_missing') {
            $errors[] = ['id' => 'admin.strategy', 'message' => 'Admin strategy must be create_if_missing.'];
        }

        if ($requireAdminPassword) {
            $adminPassword = (string) ($config['admin']['password'] ?? '');
            if (! self::isNonPlaceholderSecret($adminPassword)) {
                $errors[] = ['id' => 'admin.password', 'message' => 'Admin password is required and must not be a placeholder.'];
            } elseif (! self::isStrongPassword($adminPassword)) {
                $errors[] = ['id' => 'admin.password', 'message' => 'Admin password must be at least 12 characters and include uppercase, lowercase, number, and symbol characters.'];
            }
        }

        if (! in_array((string) ($config['services']['target_os'] ?? ''), ['windows', 'linux'], true)) {
            $errors[] = ['id' => 'services.target_os', 'message' => 'Service target OS must be windows or linux.'];
        }

        if (! in_array((string) ($config['services']['registration_mode'] ?? ''), ['generate', 'register', 'manual'], true)) {
            $errors[] = ['id' => 'services.registration_mode', 'message' => 'Service registration mode must be generate, register, or manual.'];
        }

        return $errors;
    }

    public static function buildPreflightChecks(array $config): array
    {
        $root = self::rootPath();
        $installPath = (string) ($config['app']['install_path'] ?? '');
        $publicPath = (string) ($config['app']['public_path'] ?? '');
        $db = $config['database'] ?? [];
        $checks = [];
        $installPathMatchesRoot = self::pathsEquivalent($installPath, $root);
        $publicPathWithinRoot = $publicPath !== '' && self::pathIsWithin($publicPath, $root);

        $checks[] = self::check('php.version', 'PHP version', version_compare(PHP_VERSION, '8.2.0', '>='), 'PHP ' . PHP_VERSION, true);

        foreach (['json', 'openssl', 'mbstring', 'fileinfo', 'zip', 'pdo', 'pdo_mysql', 'tokenizer', 'xml', 'ctype'] as $extension) {
            $loaded = extension_loaded($extension);
            $checks[] = self::check("php.extension.{$extension}", "PHP extension {$extension}", $loaded, $loaded ? 'Loaded' : 'Missing', true);
        }

        $checks[] = self::check('filesystem.install_path.safe', 'Install path safety', self::isSafePath($installPath), $installPath ?: 'Missing install path', true);
        $checks[] = self::check('filesystem.install_path.deployed_root', 'Install path matches deployed app root', $installPathMatchesRoot, $installPath ?: 'Missing install path', true);
        $checks[] = self::check('filesystem.install_path.writable', 'Install path writable', is_dir($installPath) && is_writable($installPath), $installPath, true);
        $checks[] = self::check('filesystem.public_path', 'Public path exists', is_dir($publicPath), $publicPath, true);
        $checks[] = self::check('filesystem.public_path.boundary', 'Public path stays under install path', $publicPathWithinRoot, $publicPath ?: 'Missing public path', true);

        if ($installPathMatchesRoot) {
            foreach (self::repairRuntimeDirectories() as $id => $result) {
                $checks[] = self::check(
                    "filesystem.{$id}.writable",
                    $result['label'],
                    $result['ok'],
                    $result['message'],
                    true
                );
            }
        } else {
            foreach (self::runtimeDirectoryDefinitions() as $id => $directory) {
                $checks[] = self::check(
                    "filesystem.{$id}.writable",
                    $directory['label'],
                    false,
                    'Skipped because app.install_path does not match the deployed app root.',
                    true
                );
            }
        }
        $checks[] = self::check('laravel.artisan', 'Laravel artisan present', is_file($root . '/artisan'), $root . '/artisan', true);
        $checks[] = self::check('laravel.vendor', 'Composer vendor present', is_file($root . '/vendor/autoload.php'), $root . '/vendor/autoload.php', true);
        $checks[] = self::check('assets.app_js', 'Maestro app JS present', is_file($root . '/public/js/maestro.app.js'), $root . '/public/js/maestro.app.js', true);
        $checks[] = self::check('assets.shell_css', 'Maestro shell CSS present', is_file($root . '/public/css/maestro.shell.css'), $root . '/public/css/maestro.shell.css', true);
        $checks[] = self::check('assets.helpers.bundle_js', 'Vendored Helper bundle JS present', is_file($root . '/public/vendor/helpers.pbb.ph/dist/helpers.ui.bundle.min.js'), $root . '/public/vendor/helpers.pbb.ph/dist/helpers.ui.bundle.min.js', true);
        $checks[] = self::check('assets.helpers.bundle_css', 'Vendored Helper bundle CSS present', is_file($root . '/public/vendor/helpers.pbb.ph/dist/helpers.ui.bundle.min.css'), $root . '/public/vendor/helpers.pbb.ph/dist/helpers.ui.bundle.min.css', true);
        $checks[] = self::check('app.url', 'APP_URL valid', (bool) filter_var((string) ($config['app']['app_url'] ?? ''), FILTER_VALIDATE_URL), (string) ($config['app']['app_url'] ?? ''), true);
        $checks[] = self::check('database.connection', 'Database connection', self::canConnectToDatabase($db), self::databaseLabel($db), true);

        return $checks;
    }

    public static function hasBlockingFailures(array $checks): bool
    {
        foreach ($checks as $check) {
            if (($check['blocking'] ?? false) && ($check['status'] ?? '') === 'failed') {
                return true;
            }
        }

        return false;
    }

    public static function writeEnvironment(array $config): array
    {
        if (! (bool) ($config['options']['write_env'] ?? true)) {
            return ['skipped' => true, 'path' => self::rootPath() . DIRECTORY_SEPARATOR . '.env'];
        }

        $root = self::rootPath();
        $envPath = $root . DIRECTORY_SEPARATOR . '.env';
        if (in_array((string) ($config['mode'] ?? 'fresh'), ['upgrade', 'repair'], true) && is_file($envPath) && ! (bool) ($config['options']['overwrite_env'] ?? false)) {
            return ['skipped' => true, 'path' => $envPath, 'reason' => 'preserve_existing_env'];
        }

        $examplePath = $root . DIRECTORY_SEPARATOR . '.env.example';
        $content = is_file($envPath)
            ? (string) file_get_contents($envPath)
            : (is_file($examplePath) ? (string) file_get_contents($examplePath) : '');

        if ($content === '') {
            $content = "APP_NAME=\"PBB Maestro\"\nAPP_ENV=production\nAPP_KEY=\n";
        }

        $backupPath = null;
        if (is_file($envPath)) {
            $backupPath = self::generatedDir() . DIRECTORY_SEPARATOR . '.env.' . date('YmdHis') . '.bak';
            copy($envPath, $backupPath);
        }

        $values = self::envValues($config);
        $existing = self::parseEnv($content);
        $generatedAppKey = false;
        if (trim((string) ($existing['APP_KEY'] ?? '')) !== '' && ! (bool) ($config['options']['overwrite_env'] ?? false)) {
            unset($values['APP_KEY']);
        } else {
            $values['APP_KEY'] = 'base64:' . base64_encode(random_bytes(32));
            $generatedAppKey = true;
        }

        $content = self::replaceEnvValues($content, $values);
        file_put_contents($envPath, $content);
        self::appendLog('Environment file written: ' . $envPath);

        return [
            'skipped' => false,
            'path' => $envPath,
            'backup_path' => $backupPath,
            'generated_app_key' => $generatedAppKey,
        ];
    }

    public static function runMigrations(array $config): array
    {
        if (! (bool) ($config['options']['run_migrations'] ?? true)) {
            return ['skipped' => true, 'exit_code' => 0, 'command' => 'migrate --force'];
        }

        if ((string) ($config['mode'] ?? 'fresh') === 'fresh') {
            return self::applyBaselineSchema($config);
        }

        $result = self::runArtisan(['migrate', '--force']);
        $result['strategy'] = 'laravel_migrations';

        return $result;
    }

    public static function runSeeders(array $config): array
    {
        if (! (bool) ($config['options']['seed_initial_data'] ?? true)) {
            return ['skipped' => true, 'exit_code' => 0, 'command' => 'db:seed --force'];
        }

        if ((string) (($config['_database_setup']['strategy'] ?? '') ?: '') === 'baseline_schema') {
            return ['skipped' => true, 'exit_code' => 0, 'command' => 'db:seed --force', 'reason' => 'baseline_schema'];
        }

        if (! is_file(self::rootPath() . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeders' . DIRECTORY_SEPARATOR . 'DatabaseSeeder.php')) {
            return ['skipped' => true, 'exit_code' => 0, 'command' => 'db:seed --force', 'reason' => 'database_seeder_absent'];
        }

        return self::runArtisan(['db:seed', '--force']);
    }

    public static function optimizeRuntime(array $config): array
    {
        if (! (bool) ($config['options']['cache_config'] ?? true)) {
            return ['skipped' => true, 'results' => []];
        }

        return [
            'skipped' => false,
            'results' => [
                self::runArtisan(['config:clear']),
                self::runArtisan(['route:clear']),
                self::runArtisan(['view:clear']),
                self::runArtisan(['config:cache']),
                self::runArtisan(['route:cache']),
                self::runArtisan(['view:cache']),
            ],
        ];
    }

    public static function bootstrapAdmin(array $config): array
    {
        if (in_array((string) ($config['mode'] ?? 'fresh'), ['upgrade', 'repair'], true)) {
            return ['skipped' => true, 'email' => (string) ($config['admin']['email'] ?? ''), 'created' => false, 'overwritten' => false, 'reason' => 'preserve_existing_admin'];
        }

        $email = (string) ($config['admin']['email'] ?? '');
        $name = (string) ($config['admin']['name'] ?? '');
        $password = (string) ($config['admin']['password'] ?? '');
        $overwriteExisting = (bool) ($config['admin']['overwrite_existing'] ?? false);

        if ($email === '' || $name === '' || $password === '') {
            return ['skipped' => true, 'email' => $email, 'created' => false, 'overwritten' => false];
        }

        $script = <<<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$email = $argv[1];
$name = $argv[2];
$overwriteExisting = ($argv[3] ?? '0') === '1';
$password = (string) getenv('MAESTRO_INSTALL_ADMIN_PASSWORD');
$existing = App\Models\User::query()->where('email', $email)->first();
if ($existing !== null && ! $overwriteExisting) {
    echo json_encode(['created' => false, 'overwritten' => false, 'id' => $existing->id, 'email' => $existing->email], JSON_UNESCAPED_SLASHES);
    exit(0);
}

if ($existing === null) {
    $user = App\Models\User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
    echo json_encode(['created' => true, 'overwritten' => false, 'id' => $user->id, 'email' => $user->email], JSON_UNESCAPED_SLASHES);
    exit(0);
}

$existing->forceFill(['name' => $name, 'password' => $password])->save();
echo json_encode(['created' => false, 'overwritten' => true, 'id' => $existing->id, 'email' => $existing->email], JSON_UNESCAPED_SLASHES);
PHP;

        $result = self::runPhpSnippet($script, [$email, $name, $overwriteExisting ? '1' : '0'], [
            'MAESTRO_INSTALL_ADMIN_PASSWORD' => $password,
        ], [$password]);
        $decoded = json_decode((string) ($result['stdout'] ?? ''), true);

        if (($result['exit_code'] ?? 1) !== 0 || ! is_array($decoded)) {
            throw new RuntimeException('Admin bootstrap failed: ' . trim((string) (($result['stderr'] ?? '') ?: ($result['stdout'] ?? ''))));
        }

        return [
            'skipped' => false,
            'email' => $email,
            'created' => (bool) ($decoded['created'] ?? false),
            'overwritten' => (bool) ($decoded['overwritten'] ?? false),
            'id' => $decoded['id'] ?? null,
        ];
    }

    public static function writeServiceArtifact(array $config): array
    {
        $targetOs = strtolower((string) ($config['services']['target_os'] ?? 'windows'));
        $manager = strtolower((string) ($config['services']['manager'] ?? ($targetOs === 'windows' ? 'scheduled-task' : 'systemd')));
        $php = PHP_BINARY;
        $root = self::rootPath();

        if ($targetOs === 'windows') {
            $path = self::generatedDir() . DIRECTORY_SEPARATOR . 'register-pbb-maestro-scheduler.ps1';
            $taskName = 'PBB Maestro Scheduler';
            $script = <<<PS1
\$Action = New-ScheduledTaskAction -Execute "{$php}" -Argument "artisan schedule:run" -WorkingDirectory "{$root}"
\$Trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes 1)
\$Settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable
Register-ScheduledTask -TaskName "{$taskName}" -Action \$Action -Trigger \$Trigger -Settings \$Settings -Description "Runs PBB Maestro Laravel scheduler once per minute." -Force
PS1;
            file_put_contents($path, $script . PHP_EOL);
        } else {
            $path = self::generatedDir() . DIRECTORY_SEPARATOR . 'pbb-maestro-scheduler.service';
            $service = <<<SERVICE
[Unit]
Description=PBB Maestro Scheduler

[Service]
Type=oneshot
WorkingDirectory={$root}
ExecStart={$php} artisan schedule:run
SERVICE;
            $timer = <<<TIMER
[Unit]
Description=Run PBB Maestro Scheduler every minute

[Timer]
OnBootSec=60
OnUnitActiveSec=60
Unit=pbb-maestro-scheduler.service

[Install]
WantedBy=timers.target
TIMER;
            file_put_contents($path, $service . PHP_EOL);
            file_put_contents(self::generatedDir() . DIRECTORY_SEPARATOR . 'pbb-maestro-scheduler.timer', $timer . PHP_EOL);
        }

        self::appendLog('Service artifact generated: ' . $path);

        return [
            'id' => 'pbb-maestro-scheduler',
            'name' => 'PBB Maestro Scheduler',
            'target_os' => $targetOs,
            'manager' => $manager,
            'registered' => false,
            'artifact' => $path,
            'command' => $php . ' artisan schedule:run',
        ];
    }

    public static function buildManifest(array $config, array $serviceArtifact, string $healthStatus): array
    {
        $release = self::releaseMetadata();
        $db = $config['database'] ?? [];
        $databaseInstaller = is_array($release['installer']['database'] ?? null) ? $release['installer']['database'] : [];
        $databaseSetup = is_array($config['_database_setup'] ?? null) ? $config['_database_setup'] : [];
        $databaseSetupStrategy = (string) ($databaseSetup['strategy'] ?? ((string) ($config['mode'] ?? 'fresh') === 'fresh' ? 'baseline_schema' : self::upgradeStrategy()));

        return [
            'schema_version' => 1,
            'app' => self::APP_ID,
            'name' => self::APP_NAME,
            'version' => (string) ($release['version'] ?? '0.0.0-dev'),
            'installed_at' => date(DATE_ATOM),
            'install_mode' => (string) ($config['mode'] ?? 'fresh'),
            'install_path' => self::rootPath(),
            'public_path' => (string) ($config['app']['public_path'] ?? ''),
            'app_url' => (string) ($config['app']['app_url'] ?? ''),
            'environment' => (string) ($config['app']['app_env'] ?? 'production'),
            'filesystem_paths' => self::filesystemPathReport($config, [], $serviceArtifact),
            'database' => [
                'driver' => 'mysql',
                'host' => (string) ($db['host'] ?? ''),
                'port' => (int) ($db['port'] ?? 3306),
                'database' => (string) ($db['database'] ?? ''),
                'username' => (string) ($db['username'] ?? ''),
                'fresh_install_strategy' => (string) ($databaseInstaller['fresh_install_strategy'] ?? $databaseInstaller['fresh_strategy'] ?? 'baseline_schema'),
                'baseline_schema' => self::baselineSchemaRelativePath(),
                'upgrade_strategy' => self::upgradeStrategy(),
            ],
            'database_setup' => [
                'strategy' => $databaseSetupStrategy,
                'baseline_schema' => self::baselineSchemaRelativePath(),
                'baseline_schema_used' => $databaseSetupStrategy === 'baseline_schema' && ! ($databaseSetup['skipped'] ?? false),
                'migration_rows' => $databaseSetup['migration_rows'] ?? null,
                'upgrade_strategy' => self::upgradeStrategy(),
            ],
            'services' => [$serviceArtifact],
            'health' => [
                'last_checked_at' => date(DATE_ATOM),
                'status' => $healthStatus,
            ],
        ];
    }

    public static function writeManifest(array $manifest): void
    {
        self::writeJson(self::manifestPath(), $manifest);
    }

    public static function writeReport(array $report): void
    {
        self::writeJson(self::reportPath(), $report);
    }

    public static function writeState(array $state): void
    {
        self::writeJson(self::statePath(), $state);
    }

    public static function buildReport(array $config, string $status, array $steps, array $extra = []): array
    {
        $release = self::releaseMetadata();
        $warnings = $extra['warnings'] ?? [];
        $errors = $extra['errors'] ?? [];

        return array_replace_recursive([
            'schema_version' => 1,
            'app' => self::APP_ID,
            'version' => (string) ($release['version'] ?? '0.0.0-dev'),
            'run_id' => (string) ($config['kit']['run_id'] ?? ''),
            'mode' => (string) ($config['mode'] ?? ''),
            'status' => $status,
            'started_at' => $extra['started_at'] ?? date(DATE_ATOM),
            'finished_at' => date(DATE_ATOM),
            'summary' => $extra['summary'] ?? self::summaryForStatus($status),
            'steps' => $steps,
            'urls' => [
                'app' => (string) ($config['app']['app_url'] ?? ''),
                'health' => rtrim((string) ($config['app']['app_url'] ?? ''), '/') . '/up',
                'bootstrap' => rtrim((string) ($config['app']['app_url'] ?? ''), '/') . '/api/bootstrap',
            ],
            'services' => [],
            'warnings' => $warnings,
            'errors' => $errors,
            'filesystem_paths' => self::filesystemPathReport($config),
        ], $extra['merge'] ?? []);
    }

    public static function filesystemPathReport(array $config, array $environment = [], array $serviceArtifact = []): array
    {
        $root = self::rootPath();
        $publicPath = (string) ($config['app']['public_path'] ?? ($root . DIRECTORY_SEPARATOR . 'public'));
        $createdDirectories = [
            ['id' => 'installer_storage', 'path' => self::storageDir()],
            ['id' => 'installer_generated', 'path' => self::storageDir() . DIRECTORY_SEPARATOR . self::GENERATED_DIR],
        ];

        foreach (self::runtimeDirectoryDefinitions() as $id => $directory) {
            $createdDirectories[] = ['id' => $id, 'path' => $directory['path']];
        }

        $createdFiles = [
            ['id' => 'environment', 'path' => $environment['path'] ?? ($root . DIRECTORY_SEPARATOR . '.env')],
            ['id' => 'install_report', 'path' => self::reportPath()],
            ['id' => 'install_manifest', 'path' => self::manifestPath()],
            ['id' => 'installer_state', 'path' => self::statePath()],
            ['id' => 'install_log', 'path' => self::logPath()],
        ];

        if (($environment['backup_path'] ?? null) !== null) {
            $createdFiles[] = ['id' => 'environment_backup', 'path' => $environment['backup_path']];
        }

        if (($serviceArtifact['artifact'] ?? null) !== null) {
            $createdFiles[] = ['id' => 'service_artifact', 'path' => $serviceArtifact['artifact']];
        }

        return [
            'install_path' => $root,
            'configured_install_path' => (string) ($config['app']['install_path'] ?? ''),
            'public_path' => $publicPath,
            'boundary' => [
                'install_path_matches_deployed_root' => self::pathsEquivalent((string) ($config['app']['install_path'] ?? ''), $root),
                'public_path_within_install_path' => $publicPath !== '' && self::pathIsWithin($publicPath, $root),
            ],
            'created_directories' => $createdDirectories,
            'created_files' => $createdFiles,
            'relied_on' => [
                ['id' => 'deployed_root', 'path' => $root],
                ['id' => 'public_path', 'path' => $publicPath],
                ['id' => 'artisan', 'path' => $root . DIRECTORY_SEPARATOR . 'artisan'],
                ['id' => 'composer_autoload', 'path' => $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php'],
                ['id' => 'baseline_schema', 'path' => $root . DIRECTORY_SEPARATOR . self::baselineSchemaRelativePath()],
            ],
        ];
    }

    public static function buildStatus(): array
    {
        $release = self::releaseMetadata();
        $manifest = self::readJson(self::manifestPath());
        $report = self::readJson(self::reportPath());
        $installed = is_file(self::manifestPath());
        $validation = self::validateLocalState();
        $failed = array_values(array_filter($validation, static fn (array $item): bool => $item['status'] === 'failed'));

        return [
            'schema_version' => 1,
            'app' => self::APP_ID,
            'version' => (string) ($release['version'] ?? '0.0.0-dev'),
            'installed' => $installed,
            'status' => $installed ? ($failed === [] ? 'healthy' : 'degraded') : 'not-installed',
            'mode' => $installed ? 'installed' : 'new',
            'health' => [
                'http' => 'unknown',
                'ready' => $failed === [] ? 'ok' : 'degraded',
                'details' => [
                    'manifest_present' => $installed,
                    'failed_local_checks' => count($failed),
                ],
            ],
            'services' => $manifest['services'] ?? [],
            'warnings' => $report['warnings'] ?? [],
            'validation' => $validation,
        ];
    }

    public static function validateLocalState(): array
    {
        $root = self::rootPath();
        $runtimeDirs = self::repairRuntimeDirectories();
        $checks = [
            self::statusCheck('env', '.env present', is_file($root . '/.env'), $root . '/.env'),
            self::statusCheck('artisan', 'Artisan present', is_file($root . '/artisan'), $root . '/artisan'),
            self::statusCheck('vendor', 'Vendor autoload present', is_file($root . '/vendor/autoload.php'), $root . '/vendor/autoload.php'),
        ];

        foreach ($runtimeDirs as $id => $result) {
            $checks[] = self::statusCheck($id, $result['label'], $result['ok'], $result['message']);
        }

        return array_merge($checks, [
            self::statusCheck('app_js', 'Maestro app JS present', is_file($root . '/public/js/maestro.app.js'), $root . '/public/js/maestro.app.js'),
            self::statusCheck('helpers_bundle_js', 'Vendored Helper bundle JS present', is_file($root . '/public/vendor/helpers.pbb.ph/dist/helpers.ui.bundle.min.js'), $root . '/public/vendor/helpers.pbb.ph/dist/helpers.ui.bundle.min.js'),
            self::statusCheck('helpers_bundle_css', 'Vendored Helper bundle CSS present', is_file($root . '/public/vendor/helpers.pbb.ph/dist/helpers.ui.bundle.min.css'), $root . '/public/vendor/helpers.pbb.ph/dist/helpers.ui.bundle.min.css'),
        ]);
    }

    public static function runValidation(array $config): array
    {
        $results = self::validateLocalState();

        if ((bool) ($config['options']['validate_after_install'] ?? true)) {
            $reconcile = self::runArtisan(['maestro:reconcile-stale-workers']);
            $results[] = self::statusCheck('stale_reconcile_command', 'Stale reconcile command runs', ($reconcile['exit_code'] ?? 1) === 0, trim((string) (($reconcile['stdout'] ?? '') ?: ($reconcile['stderr'] ?? ''))));
        }

        return $results;
    }

    public static function externalReportWrite(string $path, array $report): void
    {
        self::writeJson($path, $report);
    }

    public static function upgradeStrategy(): string
    {
        $release = self::releaseMetadata();
        $databaseInstaller = is_array($release['installer']['database'] ?? null) ? $release['installer']['database'] : [];

        return (string) ($databaseInstaller['upgrade_strategy'] ?? 'laravel_migrations');
    }

    public static function appendLog(string $message, string $level = 'info'): void
    {
        self::ensureDir(self::storageDir());
        file_put_contents(self::logPath(), sprintf("[%s] %s: %s%s", date('Y-m-d H:i:s'), strtoupper($level), $message, PHP_EOL), FILE_APPEND);
    }

    private static function envValues(array $config): array
    {
        $appUrl = (string) ($config['app']['app_url'] ?? '');
        $secure = str_starts_with(strtolower($appUrl), 'https://') ? 'true' : 'false';

        return [
            'APP_NAME' => 'PBB Maestro',
            'APP_ENV' => (string) ($config['app']['app_env'] ?? 'production'),
            'APP_DEBUG' => (bool) ($config['app']['app_debug'] ?? false) ? 'true' : 'false',
            'APP_URL' => $appUrl,
            'APP_FORCE_HTTPS' => (bool) ($config['app']['force_https'] ?? ($secure === 'true')) ? 'true' : 'false',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) ($config['database']['host'] ?? '127.0.0.1'),
            'DB_PORT' => (string) ($config['database']['port'] ?? 3306),
            'DB_DATABASE' => (string) ($config['database']['database'] ?? 'pbb_maestro'),
            'DB_USERNAME' => (string) ($config['database']['username'] ?? 'root'),
            'DB_PASSWORD' => (string) ($config['database']['password'] ?? ''),
            'SESSION_DRIVER' => 'database',
            'SESSION_COOKIE' => 'pbb_maestro_session',
            'SESSION_SECURE_COOKIE' => $secure,
            'MAESTRO_SESSION_DRIVER' => 'database',
            'MAESTRO_SESSION_COOKIE' => 'pbb_maestro_session',
            'MAESTRO_SESSION_SECURE_COOKIE' => $secure,
            'CACHE_STORE' => 'database',
            'QUEUE_CONNECTION' => 'database',
            'MAESTRO_STARTING_THRESHOLD_SECONDS' => (string) ($config['maestro']['starting_threshold_seconds'] ?? 15),
            'MAESTRO_STALE_THRESHOLD_SECONDS' => (string) ($config['maestro']['stale_threshold_seconds'] ?? 45),
            'MAESTRO_CLOCK_SKEW_THRESHOLD_SECONDS' => (string) ($config['maestro']['clock_skew_threshold_seconds'] ?? 60),
            'MAESTRO_TELEMETRY_TOKEN_HEADER' => (string) ($config['maestro']['telemetry_token_header'] ?? 'X-Telemetry-Token'),
            'MAESTRO_TELEMETRY_TRACE' => (bool) ($config['maestro']['telemetry_trace'] ?? false) ? 'true' : 'false',
            'MAESTRO_TELEMETRY_TOKEN_LAST_USED_AT_UPDATE_INTERVAL_SECONDS' => (string) ($config['maestro']['telemetry_token_last_used_at_update_interval_seconds'] ?? 60),
            'MAESTRO_TELEMETRY_SLOW_REQUEST_THRESHOLD_MS' => (string) ($config['maestro']['telemetry_slow_request_threshold_ms'] ?? 1000),
        ];
    }

    private static function runArtisan(array $args): array
    {
        return self::runCommand(array_merge([PHP_BINARY, 'artisan'], $args), self::rootPath());
    }

    private static function applyBaselineSchema(array $config): array
    {
        $path = self::baselineSchemaPath();
        if (! is_file($path)) {
            return [
                'strategy' => 'baseline_schema',
                'schema' => self::baselineSchemaRelativePath(),
                'command' => 'baseline_schema',
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => "Baseline schema not found: {$path}",
            ];
        }

        try {
            $pdo = self::databaseConnection($config['database'] ?? []);
            $statements = self::splitSqlStatements((string) file_get_contents($path));
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }

            self::appendLog('Fresh baseline schema applied: ' . $path);

            return [
                'strategy' => 'baseline_schema',
                'schema' => self::baselineSchemaRelativePath(),
                'command' => 'baseline_schema',
                'exit_code' => 0,
                'stdout' => sprintf('Applied baseline schema %s (%d statements).', self::baselineSchemaRelativePath(), count($statements)),
                'stderr' => '',
                'statements' => count($statements),
                'migration_rows' => self::migrationRows($pdo),
            ];
        } catch (Throwable $exception) {
            self::appendLog('Baseline schema failed: ' . $exception->getMessage(), 'error');

            return [
                'strategy' => 'baseline_schema',
                'schema' => self::baselineSchemaRelativePath(),
                'command' => 'baseline_schema',
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => $exception->getMessage(),
            ];
        }
    }

    private static function baselineSchemaRelativePath(): string
    {
        $release = self::releaseMetadata();
        $databaseInstaller = is_array($release['installer']['database'] ?? null) ? $release['installer']['database'] : [];
        $baselineSchema = $databaseInstaller['baseline_schema'] ?? null;
        if (is_array($baselineSchema)) {
            return (string) ($baselineSchema['path'] ?? 'database/schema/mysql-fresh.sql');
        }

        return (string) ($baselineSchema ?? 'database/schema/mysql-fresh.sql');
    }

    private static function baselineSchemaPath(): string
    {
        return self::rootPath() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, self::baselineSchemaRelativePath());
    }

    private static function databaseConnection(array $db): PDO
    {
        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', (string) ($db['host'] ?? ''), (int) ($db['port'] ?? 3306), (string) ($db['database'] ?? '')),
            (string) ($db['username'] ?? ''),
            (string) ($db['password'] ?? ''),
            [PDO::ATTR_TIMEOUT => 10, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private static function migrationRows(PDO $pdo): int
    {
        try {
            return (int) $pdo->query('SELECT COUNT(*) FROM `migrations`')->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private static function splitSqlStatements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $statements = [];
        $buffer = '';
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $buffer .= $char;

            if (($char === '\'' || $char === '"') && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $quote = $quote === $char ? null : ($quote ?? $char);
            }

            if ($char === ';' && $quote === null) {
                $statement = trim(substr($buffer, 0, -1));
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
            }
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }

    private static function runPhpSnippet(string $script, array $args, array $env = [], array $sensitiveValues = []): array
    {
        $path = self::generatedDir() . DIRECTORY_SEPARATOR . 'snippet-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($path, $script);

        try {
            return self::runCommand(array_merge([PHP_BINARY, $path], $args), self::rootPath(), $env, $sensitiveValues);
        } finally {
            @unlink($path);
        }
    }

    private static function runCommand(array $command, string $cwd, array $env = [], array $sensitiveValues = []): array
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $baseEnv = getenv();
        $processEnv = $env === [] ? null : array_merge(is_array($baseEnv) ? $baseEnv : $_ENV, $env);
        $process = proc_open($command, $descriptor, $pipes, $cwd, $processEnv, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            return ['command' => implode(' ', $command), 'exit_code' => 1, 'stdout' => '', 'stderr' => 'Unable to start command.'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $commandText = self::redactText(implode(' ', array_map(static fn (string $part): string => str_contains($part, ' ') ? '"' . $part . '"' : $part, $command)), $sensitiveValues);
        self::appendLog("Command {$commandText} exited {$exitCode}");
        if (trim((string) $stderr) !== '') {
            self::appendLog(self::redactText(trim((string) $stderr), $sensitiveValues), $exitCode === 0 ? 'warn' : 'error');
        }

        return [
            'command' => $commandText,
            'exit_code' => $exitCode,
            'stdout' => self::redactText((string) $stdout, $sensitiveValues),
            'stderr' => self::redactText((string) $stderr, $sensitiveValues),
        ];
    }

    private static function check(string $id, string $label, bool $passed, string $message, bool $blocking): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'status' => $passed ? 'passed' : 'failed',
            'message' => $message,
            'blocking' => $blocking,
        ];
    }

    private static function statusCheck(string $id, string $label, bool $passed, string $message): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'status' => $passed ? 'passed' : 'failed',
            'message' => $message,
        ];
    }

    private static function canConnectToDatabase(array $db): bool
    {
        try {
            new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', (string) ($db['host'] ?? ''), (int) ($db['port'] ?? 3306), (string) ($db['database'] ?? '')),
                (string) ($db['username'] ?? ''),
                (string) ($db['password'] ?? ''),
                [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            return true;
        } catch (Throwable $exception) {
            self::appendLog('Database preflight failed: ' . $exception->getMessage(), 'warn');

            return false;
        }
    }

    private static function databaseLabel(array $db): string
    {
        return sprintf('%s:%d/%s as %s', (string) ($db['host'] ?? ''), (int) ($db['port'] ?? 3306), (string) ($db['database'] ?? ''), (string) ($db['username'] ?? ''));
    }

    private static function boundaryValidationErrors(array $config): array
    {
        $root = self::rootPath();
        $installPath = (string) ($config['app']['install_path'] ?? '');
        $publicPath = (string) ($config['app']['public_path'] ?? ($root . DIRECTORY_SEPARATOR . 'public'));
        $errors = [];

        if ($installPath !== '' && ! self::pathsEquivalent($installPath, $root)) {
            $errors[] = [
                'id' => 'app.install_path.boundary',
                'message' => "Install path must match the deployed app root: {$root}.",
            ];
        }

        if ($publicPath === '' || ! self::pathIsWithin($publicPath, $root)) {
            $errors[] = [
                'id' => 'app.public_path.boundary',
                'message' => "Public path must stay under the deployed app root: {$root}.",
            ];
        }

        return $errors;
    }

    private static function pathsEquivalent(string $left, string $right): bool
    {
        $leftReal = realpath($left);
        $rightReal = realpath($right);

        if (is_string($leftReal) && is_string($rightReal)) {
            return self::normalizePathForCompare($leftReal) === self::normalizePathForCompare($rightReal);
        }

        return self::normalizePathForCompare($left) === self::normalizePathForCompare($right);
    }

    private static function pathIsWithin(string $path, string $root): bool
    {
        $pathReal = realpath($path);
        $rootReal = realpath($root);
        $normalizedPath = self::normalizePathForCompare(is_string($pathReal) ? $pathReal : $path);
        $normalizedRoot = self::normalizePathForCompare(is_string($rootReal) ? $rootReal : $root);

        return $normalizedPath === $normalizedRoot || str_starts_with($normalizedPath, $normalizedRoot . DIRECTORY_SEPARATOR);
    }

    private static function normalizePathForCompare(string $path): string
    {
        $normalized = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($path)), DIRECTORY_SEPARATOR);

        if (strtolower(PHP_OS_FAMILY) === 'windows') {
            $normalized = strtolower($normalized);
        }

        return $normalized;
    }

    private static function isSafePath(string $path): bool
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return false;
        }

        $normalized = rtrim(str_replace('/', '\\', $trimmed), '\\');

        return ! in_array(strtolower($normalized), ['c:', 'c:\\windows', 'c:\\program files', 'c:\\program files (x86)'], true);
    }

    private static function isNonPlaceholderSecret(string $secret): bool
    {
        $trimmed = trim($secret);

        return $trimmed !== '' && ! in_array(strtolower($trimmed), ['provided-once-in-kit-setup', 'replace-with-real-password', 'replace-me', 'changeme', 'password', 'secret'], true);
    }

    private static function isStrongPassword(string $secret): bool
    {
        return strlen($secret) >= 12
            && preg_match('/[a-z]/', $secret) === 1
            && preg_match('/[A-Z]/', $secret) === 1
            && preg_match('/\d/', $secret) === 1
            && preg_match('/[^a-zA-Z\d]/', $secret) === 1;
    }

    private static function redactText(string $text, array $sensitiveValues): string
    {
        foreach ($sensitiveValues as $value) {
            $value = (string) $value;
            if ($value !== '') {
                $text = str_replace($value, '[redacted]', $text);
            }
        }

        return $text;
    }

    private static function parseEnv(string $content): array
    {
        $values = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            if ($line === '' || str_starts_with(ltrim($line), '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value);
        }

        return $values;
    }

    private static function replaceEnvValues(string $content, array $values): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $seen = [];

        foreach ($lines as $index => $line) {
            if ($line === '' || str_starts_with(ltrim($line), '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key] = explode('=', $line, 2);
            $key = trim($key);
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $lines[$index] = $key . '=' . self::formatEnvValue((string) $values[$key]);
            $seen[$key] = true;
        }

        foreach ($values as $key => $value) {
            if (! isset($seen[$key])) {
                $lines[] = $key . '=' . self::formatEnvValue((string) $value);
            }
        }

        return rtrim(implode(PHP_EOL, $lines)) . PHP_EOL;
    }

    private static function formatEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/\s|"|#/', $value)) {
            return '"' . str_replace('"', '\"', $value) . '"';
        }

        return $value;
    }

    private static function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function writeJson(string $path, array $data): void
    {
        self::ensureDir(dirname($path));
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    private static function ensureDir(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    private static function repairRuntimeDirectories(): array
    {
        $directories = self::runtimeDirectoryDefinitions();
        $results = [];

        foreach ($directories as $id => $directory) {
            $path = $directory['path'];
            $error = null;

            if (! is_dir($path)) {
                set_error_handler(static function (int $severity, string $message) use (&$error): bool {
                    $error = $message;

                    return true;
                });
                try {
                    mkdir($path, 0775, true);
                } finally {
                    restore_error_handler();
                }
            }

            $ok = is_dir($path) && is_writable($path);
            $results[$id] = [
                'label' => $directory['label'],
                'ok' => $ok,
                'message' => $ok ? $path : ($error !== null ? "{$path} ({$error})" : $path),
            ];
        }

        return $results;
    }

    private static function runtimeDirectoryDefinitions(): array
    {
        $root = self::rootPath();

        return [
            'storage' => ['label' => 'Storage root writable', 'path' => $root . DIRECTORY_SEPARATOR . 'storage'],
            'storage_app' => ['label' => 'Storage app writable', 'path' => $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app'],
            'storage_app_private' => ['label' => 'Storage private writable', 'path' => $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'private'],
            'storage_app_public' => ['label' => 'Storage public writable', 'path' => $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'public'],
            'storage_framework_cache' => ['label' => 'Storage framework cache writable', 'path' => $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'cache'],
            'storage_framework_cache_data' => ['label' => 'Storage framework cache data writable', 'path' => $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'data'],
            'storage_framework_sessions' => ['label' => 'Storage framework sessions writable', 'path' => $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'sessions'],
            'storage_framework_testing' => ['label' => 'Storage framework testing writable', 'path' => $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'testing'],
            'storage_framework_views' => ['label' => 'Storage framework views writable', 'path' => $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'views'],
            'storage_logs' => ['label' => 'Storage logs writable', 'path' => $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs'],
            'bootstrap_cache' => ['label' => 'Bootstrap cache writable', 'path' => $root . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'cache'],
        ];
    }

    private static function summaryForStatus(string $status): string
    {
        return match ($status) {
            'success' => 'PBB Maestro installer completed.',
            'warning' => 'PBB Maestro installer completed with warnings.',
            default => 'PBB Maestro installer failed.',
        };
    }
}
