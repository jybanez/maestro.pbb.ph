<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$outputDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'installer-build';
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
$packageName = sprintf('%s-m%d-%s.zip', $appId, $milestone, $version);
$packagePath = $outputDir . DIRECTORY_SEPARATOR . $packageName;

$release['display_version'] = $displayVersion;
$release['build'] = [
    'version' => $version,
    'id' => $buildId,
    'built_at' => $builtAt,
    'git_commit' => $gitCommit !== '' ? $gitCommit : null,
    'builder' => 'pbb-maestro-release-build',
];

ensureDir($outputDir);

$entries = [];
$addFile = static function (string $source, string $target) use (&$entries): void {
    if (! is_file($source)) {
        throw new RuntimeException("Missing package source file: {$source}");
    }

    $entries[] = ['source' => $source, 'target' => normalizeZipPath($target)];
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
        if (shouldExcludePath($path)) {
            continue;
        }

        $relative = substr($path, strlen($sourceDir) + 1);
        $entries[] = [
            'source' => $path,
            'target' => normalizeZipPath($targetDir . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative)),
        ];
    }
};

foreach ([
    '.editorconfig',
    '.env.example',
    '.gitattributes',
    '.gitignore',
    'README.md',
    'VENDORED.md',
    'artisan',
    'composer.json',
    'composer.lock',
    'package.json',
] as $file) {
    $addFile($root . DIRECTORY_SEPARATOR . $file, 'app/' . $file);
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
    'storage',
    'tools',
    'vendor',
] as $dir) {
    $addDirectory($root . DIRECTORY_SEPARATOR . $dir, 'app/' . $dir);
}

$checksums = [
    'release.json' => hash('sha256', encodeJson($release)),
    'app/release.json' => hash('sha256', encodeJson($release)),
];

foreach ($entries as $entry) {
    $checksums[$entry['target']] = hash_file('sha256', $entry['source']);
}

ksort($checksums);
$checksumText = '';
foreach ($checksums as $path => $hash) {
    $checksumText .= $hash . '  ' . $path . PHP_EOL;
}

$checksums['checksums.sha256'] = hash('sha256', $checksumText);

if (is_file($packagePath)) {
    unlink($packagePath);
}

$zip = new ZipArchive();
if ($zip->open($packagePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException("Unable to create package: {$packagePath}");
}

$zip->addFromString('release.json', encodeJson($release));
$zip->addFromString('app/release.json', encodeJson($release));
$zip->addFromString('checksums.sha256', $checksumText);

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
        'entries' => count($entries) + 3,
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

function normalizeZipPath(string $path): string
{
    return str_replace('\\', '/', $path);
}

function shouldExcludePath(string $path): bool
{
    $normalized = '/' . str_replace('\\', '/', $path);

    foreach ([
        '/.git/',
        '/.github/',
        '/.vscode/',
        '/node_modules/',
        '/storage/app/installer/',
        '/storage/app/installer-build/',
        '/storage/framework/sessions/',
        '/storage/framework/views/',
        '/storage/logs/',
        '/tests/',
    ] as $fragment) {
        if (str_contains($normalized, $fragment)) {
            return true;
        }
    }

    $basename = basename($path);
    if (in_array($basename, ['.env', '.phpunit.result.cache', 'build-release-package.php'], true)) {
        return true;
    }

    return preg_match('/\.(log|tmp|crt|key)$/i', $basename) === 1;
}
