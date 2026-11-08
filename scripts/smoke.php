<?php

declare(strict_types=1);

/*
 * Boots a real server with two test plugins that each shade their own copy of UI-API,
 * the way a virion is injected, and checks them end to end without a Bedrock client.
 * Everything happens in a fresh temporary folder; nothing here is a release build.
 *
 *   php -d phar.readonly=0 scripts/smoke.php <path/to/Axolotl-PM.phar> [--php=<php binary>] [--port=19170]
 *
 * Checks:
 *   - both plugins enable and use the parts they need (forms, sidebar, boss bars);
 *   - both shaded copies find the same sidebar host, so claims are shared;
 *   - the server stops cleanly, with no errors in the log.
 */

$root = dirname(__DIR__);
$serverPhar = '';
$php = PHP_BINARY;
$port = 19170;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--php=')) {
        $php = substr($arg, 6);
    } elseif (str_starts_with($arg, '--port=')) {
        $port = (int) substr($arg, 7);
    } else {
        $serverPhar = $arg;
    }
}
if ($serverPhar === '' || !is_file($serverPhar) || $port < 1 || $port > 65534) {
    fwrite(STDERR, "Usage: php -d phar.readonly=0 scripts/smoke.php <path/to/Axolotl-PM.phar> [--php=<php>] [--port=<port>]\n");
    exit(2);
}
if (ini_get('phar.readonly') === '1') {
    fwrite(STDERR, "Run with php -d phar.readonly=0 to package the test plugins\n");
    exit(2);
}
// The server runs inside the temporary data folder, so relative paths would break.
$serverPhar = (string) realpath($serverPhar);
if (is_file($php)) {
    $php = (string) realpath($php);
}

$work = sys_get_temp_dir() . '/UI-API-smoke-' . bin2hex(random_bytes(4));
$data = $work . '/data';
$plugins = $work . '/plugins';
mkdir($data, 0777, true);
mkdir($plugins, 0777, true);
file_put_contents($data . '/server.properties', implode("\n", [
    'motd=UI-API smoke test',
    'server-port=' . $port,
    'server-portv6=' . ($port + 1),
    'enable-ipv6=off',
    'xbox-auth=off',
    'max-players=1',
    'level-type=FLAT',
    '',
]));
// Without plugins.legacy-data-dir: false, the server would keep plugin data in the plugins folder.
file_put_contents($data . '/pocketmine.yml', "plugins:\n  legacy-data-dir: false\nauto-updater:\n  enabled: false\nanonymous-statistics:\n  enabled: false\nplayer:\n  save-player-data: false\n");

// Two plugins, each with its own shaded copy; only the first uses boss bars.
buildConsumer($root, $plugins . '/SmokeArena.phar', 'SmokeArena', 'smokearena', true);
buildConsumer($root, $plugins . '/SmokeLobby.phar', 'SmokeLobby', 'smokelobby', false, 'SmokeArena');

$log = $work . '/server.log';
$failures = [];
try {
    $text = runServer($php, $serverPhar, $data, $plugins, $log);
    $expect = static function (string $needle) use ($text, &$failures): void {
        if (!str_contains($text, $needle)) {
            $failures[] = 'expected "' . $needle . '" in the log';
        }
    };
    $expect('[SmokeArena] boss bars ready');
    $expect('[SmokeArena] form buttons: 1');
    $expect('[SmokeLobby] form buttons: 1');
    // The arena plugin enables first, so its shaded copy owns the host both use.
    $expect('[SmokeArena] sidebar host: smokearena\libs\AxolotlBucketLabs\UI\sidebar\host\SidebarHost');
    $expect('[SmokeLobby] sidebar host: smokearena\libs\AxolotlBucketLabs\UI\sidebar\host\SidebarHost');
    $expect('[SmokeLobby] own copy: smokelobby\libs\AxolotlBucketLabs\UI\sidebar\SidebarManager');
    foreach (['CRITICAL', 'EMERGENCY', 'Fatal error', 'Uncaught', 'Exception:', 'Error:'] as $bad) {
        if (str_contains($text, $bad)) {
            $failures[] = 'log contains "' . $bad . '"';
        }
    }
} catch (RuntimeException $e) {
    $failures[] = $e->getMessage();
}

if ($failures === []) {
    echo "PASS  two shaded copies on one server\nSmoke test passed. Work folder: $work\n";
    exit(0);
}
foreach ($failures as $failure) {
    echo "FAIL  $failure\n";
}
foreach ([$log, $log . '.stderr'] as $file) {
    foreach (array_slice(is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [], -25) as $line) {
        echo '      | ' . $line . "\n";
    }
}
echo "Work folder: $work\n";
exit(1);

function runServer(string $php, string $serverPhar, string $data, string $plugins, string $log): string
{
    $process = proc_open(
        [$php, $serverPhar, '--no-wizard', '--disable-ansi', '--data=' . $data, '--plugins=' . $plugins],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log . '.stderr', 'w']],
        $pipes,
        $data
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the server');
    }
    $read = static fn(): string => (string) @file_get_contents($log);
    try {
        $deadline = microtime(true) + 90;
        while (!str_contains($read(), 'Done (')) {
            if (microtime(true) > $deadline || !proc_get_status($process)['running']) {
                throw new RuntimeException('Server did not finish starting');
            }
            usleep(200_000);
        }
    } finally {
        if (proc_get_status($process)['running']) {
            @fwrite($pipes[0], "stop\n");
            @fflush($pipes[0]);
        }
        $deadline = microtime(true) + 30;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(200_000);
        }
        fclose($pipes[0]);
        if (proc_get_status($process)['running']) {
            proc_terminate($process);
            proc_close($process);
            throw new RuntimeException('Server did not stop within 30 seconds');
        }
        proc_close($process);
    }
    return $read() . (string) @file_get_contents($log . '.stderr');
}

/**
 * A plugin with UI-API shaded into it: the library's namespace becomes
 * "<namespace>\libs\AxolotlBucketLabs\UI", as virion injection does.
 */
function buildConsumer(string $root, string $path, string $name, string $namespace, bool $withBossBars, ?string $loadAfter = null): void
{
    $phar = new Phar($path);
    $phar->startBuffering();
    $phar->setStub('<?php __HALT_COMPILER();');
    $phar->addFromString('plugin.yml', implode("\n", [
        'name: ' . $name,
        'main: ' . $namespace . '\Main',
        'src-namespace-prefix: ' . $namespace,
        'version: 1.0.0',
        'api: 5.0.0',
        $loadAfter === null ? '' : 'depend: [' . $loadAfter . ']',
        '',
    ]));

    $library = $namespace . '\libs\AxolotlBucketLabs\UI';
    $source = $root . '/src/AxolotlBucketLabs/UI';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
            $code = str_replace('AxolotlBucketLabs\UI', $library, (string) file_get_contents($file->getPathname()));
            $phar->addFromString('src/libs/AxolotlBucketLabs/UI/' . $relative, $code);
        }
    }

    $main = <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace __NS__;

        use __LIB__\bossbar\BossBarManager;
        use __LIB__\form\SimpleForm;
        use __LIB__\sidebar\SidebarManager;
        use pocketmine\plugin\PluginBase;

        final class Main extends PluginBase {
            protected function onEnable(): void {
                new SidebarManager($this);
                __BOSSBAR__
                $form = (new SimpleForm('Menu'))->button('Play');
                $this->getLogger()->info('form buttons: ' . $form->buttonCount());
                $bus = $GLOBALS['ui-api/sidebar/v1'] ?? null;
                $host = is_array($bus) && ($bus['claim'] ?? null) instanceof \Closure
                    ? (new \ReflectionFunction($bus['claim']))->getClosureScopeClass()?->getName()
                    : null;
                $this->getLogger()->info('sidebar host: ' . ($host ?? 'none'));
                $this->getLogger()->info('own copy: ' . SidebarManager::class);
            }
        }
        PHP;
    $bossBar = $withBossBars ? 'new BossBarManager($this); $this->getLogger()->info("boss bars ready");' : '';
    $main = str_replace('__BOSSBAR__', $bossBar, $main);
    $phar->addFromString('src/Main.php', str_replace(['__NS__', '__LIB__'], [$namespace, $library], $main));
    $phar->stopBuffering();
}
