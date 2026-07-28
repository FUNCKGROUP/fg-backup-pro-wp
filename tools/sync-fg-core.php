<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__);
$workingDirectory = getcwd();

$projectRoot = getenv('FG_CORE_PROJECT_ROOT');
if (!is_string($projectRoot) || trim($projectRoot) === '') {
    $projectRoot = is_string($workingDirectory) && $workingDirectory !== ''
        ? $workingDirectory
        : $packageRoot;
}
$projectRoot = rtrim($projectRoot, '/\\');

$target = getenv('FG_CORE_TARGET');
if (!is_string($target) || trim($target) === '') {
    $target = $projectRoot . '/includes/fg-core';
}
$target = rtrim($target, '/\\');

$candidates = [];

$envSource = getenv('FG_CORE_SOURCE');
if (is_string($envSource) && trim($envSource) !== '') {
    $candidates[] = rtrim($envSource, '/\\');
}

// Normal Composer installation: this script itself lives inside
// vendor/funckgroup/fg-core and carries the source runtime with it.
$candidates[] = $packageRoot . '/includes/fg-core';

// Fallbacks for custom development layouts.
$candidates[] = $projectRoot . '/vendor/funckgroup/fg-core/includes/fg-core';
$candidates[] = dirname($projectRoot) . '/fg-core/includes/fg-core';
$candidates[] = dirname($projectRoot) . '/fg-core/package';

$source = null;
foreach (array_unique($candidates) as $candidate) {
    if (is_file($candidate . '/bootstrap.php') && is_file($candidate . '/manifest.php')) {
        $source = $candidate;
        break;
    }
}

if ($source === null) {
    fwrite(STDERR, "FG Core wurde nicht gefunden.\n");
    fwrite(STDERR, "Setze FG_CORE_SOURCE oder installiere funckgroup/fg-core über Composer.\n");
    exit(1);
}

$sourceReal = realpath($source);
$targetReal = realpath($target);

if ($sourceReal !== false && $targetReal !== false && $sourceReal === $targetReal) {
    fwrite(STDOUT, "FG Core liegt bereits im Zielverzeichnis; keine Synchronisierung erforderlich.\n");
    exit(0);
}

function remove_tree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        if (!unlink($path)) {
            throw new RuntimeException('Datei konnte nicht entfernt werden: ' . $path);
        }
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        throw new RuntimeException('Verzeichnis konnte nicht gelesen werden: ' . $path);
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        remove_tree($path . DIRECTORY_SEPARATOR . $item);
    }

    if (!rmdir($path)) {
        throw new RuntimeException('Verzeichnis konnte nicht entfernt werden: ' . $path);
    }
}

function copy_tree(string $source, string $target): void
{
    if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
        throw new RuntimeException('Zielverzeichnis konnte nicht erstellt werden: ' . $target);
    }

    $items = scandir($source);
    if ($items === false) {
        throw new RuntimeException('Quellverzeichnis konnte nicht gelesen werden: ' . $source);
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $from = $source . DIRECTORY_SEPARATOR . $item;
        $to = $target . DIRECTORY_SEPARATOR . $item;

        if (is_dir($from)) {
            copy_tree($from, $to);
            continue;
        }

        if (!copy($from, $to)) {
            throw new RuntimeException('Datei konnte nicht kopiert werden: ' . $from);
        }
    }
}

try {
    $targetParent = dirname($target);
    if (!is_dir($targetParent) && !mkdir($targetParent, 0775, true) && !is_dir($targetParent)) {
        throw new RuntimeException('Zielbasis konnte nicht erstellt werden: ' . $targetParent);
    }

    $temporaryTarget = $targetParent . '/.fg-core-sync-' . bin2hex(random_bytes(6));
    copy_tree($source, $temporaryTarget);
    remove_tree($target);

    if (!rename($temporaryTarget, $target)) {
        remove_tree($temporaryTarget);
        throw new RuntimeException('Temporäres Verzeichnis konnte nicht aktiviert werden.');
    }

    $version = 'unbekannt';
    $manifestContents = file_get_contents($target . '/manifest.php');
    if (
        is_string($manifestContents) &&
        preg_match("/['\"]version['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $manifestContents, $matches) === 1
    ) {
        $version = (string) $matches[1];
    }

    fwrite(STDOUT, sprintf(
        "FG Core %s wurde synchronisiert:\n%s\n→ %s\n",
        $version,
        $source,
        $target
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Synchronisierung fehlgeschlagen: ' . $exception->getMessage() . "\n");
    exit(1);
}
