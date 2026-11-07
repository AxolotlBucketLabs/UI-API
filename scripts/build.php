<?php

declare(strict_types=1);

/*
 * Builds out/nk-ui.phar from plugin.yml, src/, resources/ and LICENSE.
 * Run with: php -d phar.readonly=0 scripts/build.php   (or: composer build)
 */

$root = dirname(__DIR__);
$out = $root . '/out/nk-ui.phar';

if (ini_get('phar.readonly') === '1') {
    fwrite(STDERR, "phar.readonly is on; run with php -d phar.readonly=0\n");
    exit(1);
}
$manifest = yaml_parse_file($root . '/plugin.yml');
if (!is_array($manifest) || !is_string($manifest['version'] ?? null)) {
    fwrite(STDERR, "plugin.yml has no version\n");
    exit(1);
}

@mkdir(dirname($out), 0777, true);
if (is_file($out)) {
    unlink($out);
}

$files = ['plugin.yml' => $root . '/plugin.yml', 'LICENSE' => $root . '/LICENSE'];
foreach (['src', 'resources'] as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            $relative = $dir . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($root . '/' . $dir) + 1));
            $files[$relative] = $file->getPathname();
        }
    }
}
ksort($files);

$phar = new Phar($out);
$phar->startBuffering();
$phar->setMetadata(['name' => $manifest['name'] ?? 'nk-ui', 'version' => $manifest['version'], 'creationDate' => time()]);
$phar->setStub('<?php __HALT_COMPILER();');
foreach ($files as $local => $path) {
    $phar->addFile($path, $local);
}
$phar->compressFiles(Phar::GZ);
$phar->stopBuffering();

echo 'Built ' . $out . ' (' . count($files) . ' files, v' . $manifest['version'] . ")\n";
