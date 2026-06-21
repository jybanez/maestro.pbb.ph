<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$outputDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'installer-build';
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$phpBinary = PHP_BINARY;

if (! class_exists(ZipArchive::class)) {
    fwrite(STDERR, "ZipArchive is required to build the release package.\n");
    exit(1);
}

$releasePath = $root . DIRECTORY_SEPARATOR . 'release.json';
$release = readJson($releasePath);
$appId = (string) ($release['app'] ?? 'pbb-maestro');
$milestone = (int) ($release['milestone'] ?? 1);
$version = (string) ($release['version'] ?? '0.0.0');
$displayVersion = sprintf('v%d-%s', $milestone, $version);
$timestamp = date('Ymd.His');
$builtAt = date(DATE_ATOM);
$gitCommit = trim(runCommand(['git', 'rev-parse', 'HEAD'], $root));
$buildId = sprintf('%s-%d-%s-%s', $appId, $milestone, $version, $timestamp);
$packageName = sprintf('%s-%s.zip', $appId, $version);
$packagePath = $distDir . DIRECTORY_SEPARATOR . $packageName;
$vendorBuildDir = $outputDir . DIRECTORY_SEPARATOR . 'vendor-prod';

$release['display_version'] = $displayVersion;
$release['build'] = [
    'version' => $version,
    'id' => $buildId,
    'built_at' => $builtAt,
    'git_commit' => $gitCommit !== '' ? $gitCommit : null,
    'builder' => 'pbb-maestro-release-build',
];

ensureDir($outputDir);
ensureDir($distDir);
prepareProductionVendor($root, $vendorBuildDir);

$entries = [];
$directories = [];
$generatedFiles = [];
$addFile = static function (string $source, string $target) use (&$entries): void {
    if (! is_file($source)) {
        throw new RuntimeException("Missing package source file: {$source}");
    }

    $entries[] = ['source' => $source, 'target' => normalizeZipPath($target)];
};

$addGeneratedFile = static function (string $target, string $content) use (&$generatedFiles): void {
    $generatedFiles[normalizeZipPath($target)] = $content;
};

$addDirectoryEntry = static function (string $target) use (&$directories): void {
    $directories[] = rtrim(normalizeZipPath($target), '/') . '/';
};

$addDirectory = static function (string $sourceDir, string $targetDir) use (&$entries): void {
    if (! is_dir($sourceDir)) {
        throw new RuntimeException("Missing package source directory: {$sourceDir}");
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
            continue;
        }

        $path = $file->getPathname();
        $relative = substr($path, strlen($sourceDir) + 1);
        $target = normalizeZipPath($targetDir . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative));
        if (shouldExcludePath($target)) {
            continue;
        }

        $entries[] = [
            'source' => $path,
            'target' => $target,
        ];
    }
};

$addGeneratedFile('composer.json', encodeJson(sanitizedComposerJson($root . DIRECTORY_SEPARATOR . 'composer.json')));

foreach ([
    'artisan',
] as $file) {
    $addFile($root . DIRECTORY_SEPARATOR . $file, $file);
}

foreach ([
    'app',
    'bootstrap',
    'config',
    'database',
    'installer',
    'public',
    'resources',
    'routes',
    'tools',
] as $dir) {
    $addDirectory($root . DIRECTORY_SEPARATOR . $dir, $dir);
}

$addDirectory($vendorBuildDir, 'vendor');

assertRequiredPackageTargets($entries, $generatedFiles, [
    'public/.htaccess',
]);

foreach ([
    'bootstrap/cache',
    'storage/app',
    'storage/app/private',
    'storage/app/public',
    'storage/framework/cache',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/testing',
    'storage/framework/views',
    'storage/logs',
] as $dir) {
    $addDirectoryEntry($dir);
}

$checksums = [
    'release.json' => hash('sha256', encodeJson($release)),
];

foreach ($generatedFiles as $path => $content) {
    $checksums[$path] = hash('sha256', $content);
}

foreach ($entries as $entry) {
    $checksums[$entry['target']] = hash_file('sha256', $entry['source']);
}

ksort($checksums);
$checksumText = '';
foreach ($checksums as $path => $hash) {
    $checksumText .= $hash . '  ' . $path . PHP_EOL;
}

if (is_file($packagePath)) {
    unlink($packagePath);
}

$zip = new ZipArchive();
if ($zip->open($packagePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException("Unable to create package: {$packagePath}");
}

$zip->addFromString('release.json', encodeJson($release));
$zip->addFromString('checksums.sha256', $checksumText);

foreach (array_unique($directories) as $directory) {
    $zip->addEmptyDir($directory);
}

foreach ($generatedFiles as $target => $content) {
    $zip->addFromString($target, $content);
}

foreach ($entries as $entry) {
    $zip->addFile($entry['source'], $entry['target']);
}

$zip->close();

$manifest = [
    'schema_version' => 1,
    'app' => $appId,
    'milestone' => $milestone,
    'version' => $version,
    'display_version' => $displayVersion,
    'build' => $release['build'],
    'package' => [
        'path' => $packagePath,
        'name' => $packageName,
        'sha256' => hash_file('sha256', $packagePath),
        'bytes' => filesize($packagePath),
        'entries' => count($entries) + count($generatedFiles) + count(array_unique($directories)) + 2,
    ],
];

file_put_contents($outputDir . DIRECTORY_SEPARATOR . 'latest-manifest.json', encodeJson($manifest));

echo encodeJson($manifest);

function readJson(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true);
    if (! is_array($decoded)) {
        throw new RuntimeException("Invalid JSON: {$path}");
    }

    return $decoded;
}

function encodeJson(array $data): string
{
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

function ensureDir(string $dir): void
{
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

function sanitizedComposerJson(string $path): array
{
    $composer = readJson($path);

    unset(
        $composer['require-dev'],
        $composer['autoload-dev'],
        $composer['scripts'],
        $composer['scripts-descriptions']
    );

    $composer['description'] = 'Production runtime metadata for PBB Maestro.';
    unset($composer['config']['allow-plugins']);
    unset(
        $composer['autoload']['psr-4']['Database\\Factories\\'],
        $composer['autoload']['psr-4']['Database\\Seeders\\']
    );

    return $composer;
}

function prepareProductionVendor(string $root, string $vendorBuildDir): void
{
    if (is_dir($vendorBuildDir)) {
        removeDirectory($vendorBuildDir);
    }

    $workDir = dirname($vendorBuildDir) . DIRECTORY_SEPARATOR . 'vendor-prod-root';
    if (is_dir($workDir)) {
        removeDirectory($workDir);
    }
    ensureDir($workDir);

    file_put_contents($workDir . DIRECTORY_SEPARATOR . 'composer.json', encodeJson(sanitizedComposerJson($root . DIRECTORY_SEPARATOR . 'composer.json')));
    copy($root . DIRECTORY_SEPARATOR . 'composer.lock', $workDir . DIRECTORY_SEPARATOR . 'composer.lock');

    $composerCommand = array_merge(composerCommandPrefix(), [
        'install',
        '--no-dev',
        '--optimize-autoloader',
        '--no-interaction',
        '--no-scripts',
        '--no-progress',
        '--quiet',
        '--working-dir',
        $workDir,
    ]);

    $previousRootVersion = getenv('COMPOSER_ROOT_VERSION');
    putenv('COMPOSER_ROOT_VERSION=1.0.0');
    $result = runProcess($composerCommand, $root);
    if ($previousRootVersion === false) {
        putenv('COMPOSER_ROOT_VERSION');
    } else {
        putenv('COMPOSER_ROOT_VERSION=' . $previousRootVersion);
    }
    if ($result['exit_code'] !== 0) {
        throw new RuntimeException("Composer production install failed:\n" . trim($result['stderr'] . "\n" . $result['stdout']));
    }

    $vendorBinDir = $workDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin';
    if (is_dir($vendorBinDir)) {
        removeDirectory($vendorBinDir);
    }

    $lock = readJson($root . DIRECTORY_SEPARATOR . 'composer.lock');
    $devPackages = [];
    foreach (($lock['packages-dev'] ?? []) as $package) {
        if (! is_array($package) || ! isset($package['name'])) {
            continue;
        }

        $devPackages[strtolower((string) $package['name'])] = true;
    }

    filterComposerInstalledMetadata($workDir . DIRECTORY_SEPARATOR . 'vendor', $devPackages);
    rename($workDir . DIRECTORY_SEPARATOR . 'vendor', $vendorBuildDir);
    removeDirectory($workDir);
}

function filterComposerInstalledMetadata(string $vendorDir, array $devPackages): void
{
    $installedJsonPath = $vendorDir . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.json';
    if (is_file($installedJsonPath)) {
        $installed = readJson($installedJsonPath);
        $packages = $installed['packages'] ?? $installed;
        $packages = array_values(array_filter($packages, static function (array $package) use ($devPackages): bool {
            $name = strtolower((string) ($package['name'] ?? ''));

            return $name !== '' && ! isset($devPackages[$name]);
        }));

        if (isset($installed['packages'])) {
            $installed['packages'] = $packages;
        } else {
            $installed = $packages;
        }

        file_put_contents($installedJsonPath, encodeJson($installed));
    }

    $installedPhpPath = $vendorDir . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.php';
    if (is_file($installedPhpPath)) {
        $installed = require $installedPhpPath;
        if (is_array($installed)) {
            $installed['root']['dev'] = false;
            foreach (($installed['versions'] ?? []) as $name => $package) {
                $normalizedName = strtolower((string) $name);
                if (($package['dev_requirement'] ?? false) || isset($devPackages[$normalizedName])) {
                    unset($installed['versions'][$name]);
                }
            }

            file_put_contents($installedPhpPath, '<?php return ' . var_export($installed, true) . ';' . PHP_EOL);
        }
    }
}

function removeDirectory(string $dir): void
{
    $real = realpath($dir);
    if ($real === false) {
        return;
    }

    $expectedRoot = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'installer-build');
    if ($expectedRoot === false || ! str_starts_with($real, $expectedRoot)) {
        throw new RuntimeException("Refusing to remove unexpected directory: {$dir}");
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if (! $item instanceof SplFileInfo) {
            continue;
        }

        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($real);
}

function copyDirectoryFiltered(string $sourceDir, string $targetDir, callable $include): void
{
    ensureDir($targetDir);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if (! $item instanceof SplFileInfo) {
            continue;
        }

        $relativePath = substr($item->getPathname(), strlen($sourceDir) + 1);
        if (! $include($relativePath)) {
            continue;
        }

        $target = $targetDir . DIRECTORY_SEPARATOR . $relativePath;
        if ($item->isDir()) {
            ensureDir($target);
            continue;
        }

        ensureDir(dirname($target));
        copy($item->getPathname(), $target);
    }
}

function runCommand(array $command, string $cwd): string
{
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptor, $pipes, $cwd, null, ['bypass_shell' => true]);
    if (! is_resource($process)) {
        return '';
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return (string) $stdout;
}

function runProcess(array $command, string $cwd): array
{
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptor, $pipes, $cwd, null, ['bypass_shell' => true]);
    if (! is_resource($process)) {
        return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Unable to start process.'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return ['exit_code' => $exitCode, 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
}

function composerCommandPrefix(): array
{
    if (PHP_OS_FAMILY === 'Windows') {
        $composerPhar = 'C:\ProgramData\ComposerSetup\bin\composer.phar';
        if (is_file($composerPhar)) {
            return [PHP_BINARY, $composerPhar];
        }

        return ['cmd', '/c', 'composer.bat'];
    }

    return ['composer'];
}

function normalizeZipPath(string $path): string
{
    return str_replace('\\', '/', $path);
}

function assertRequiredPackageTargets(array $entries, array $generatedFiles, array $requiredTargets): void
{
    $targets = array_fill_keys(array_keys($generatedFiles), true);
    foreach ($entries as $entry) {
        $targets[(string) ($entry['target'] ?? '')] = true;
    }

    $missing = [];
    foreach ($requiredTargets as $target) {
        if (! isset($targets[$target])) {
            $missing[] = $target;
        }
    }

    if ($missing !== []) {
        throw new RuntimeException('Release package is missing required app-owned file(s): ' . implode(', ', $missing));
    }
}

function shouldExcludePath(string $path): bool
{
    $normalized = '/' . str_replace('\\', '/', $path);
    $normalizedLower = strtolower($normalized);

    if ($normalizedLower === '/public/.htaccess') {
        return false;
    }

    foreach ([
        '/.git/',
        '/.github/',
        '/.vscode/',
        '/docs/',
        '/doc/',
        '/examples/',
        '/example/',
        '/demo/',
        '/demos/',
        '/node_modules/',
        '/bootstrap/cache/',
        '/database/factories/',
        '/database/seeders/',
        '/installer/docs/',
        '/resources/css/',
        '/resources/js/',
        '/resources/vendor/',
        '/storage/app/installer/',
        '/storage/app/installer-build/',
        '/storage/',
        '/test/',
        '/tests/',
    ] as $fragment) {
        if (str_contains($normalizedLower, $fragment)) {
            return true;
        }
    }

    $basename = strtolower(basename($path));
    if (str_starts_with($basename, '.')) {
        return true;
    }

    if (in_array($basename, ['composer.lock', 'package.json', 'readme.md', 'changelog.md', 'vendored.md', 'upgrade.md', 'contributing.md', 'playground.php', '.env', '.env.example', '.phpunit.result.cache', 'build-release-package.php'], true)) {
        return true;
    }

    return preg_match('/\.(log|tmp|crt|key)$/i', $basename) === 1;
}
