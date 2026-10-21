<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$count = 0;
foreach (['src', 'tests', 'examples', 'tools'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $process = proc_open([PHP_BINARY, '-l', $file->getPathname()], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            fwrite(STDERR, 'Cannot start PHP syntax check.'.PHP_EOL);
            exit(1);
        }
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            fwrite(STDERR, $output);
            exit(1);
        }
        ++$count;
    }
}
echo 'PHP syntax checks passed for '.$count.' files.'.PHP_EOL;
