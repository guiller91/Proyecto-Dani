<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [$root . '/bootstrap.php'];
foreach (['src', 'public', 'bin', 'tests'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
foreach ($files as $file) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
        exit($status);
    }
    $output = [];
}
echo 'Sintaxis correcta en ' . count($files) . " ficheros PHP.\n";
