<?php

declare(strict_types=1);

/*
 * Boots a real server with the built plugin and checks it end to end, without a
 * Bedrock client. Everything happens in a fresh temporary data folder.
 *
 *   php scripts/smoke.php <path/to/PocketMine-MP.phar> [--php=<php binary>] [--port=19170]
 *
 * Build first (composer build). For each module setting it starts the server, waits
 * for "Done", runs console commands, stops it and checks the log:
 *   - nk-ui enables and logs the configured modules;
 *   - a second plugin that depends on nk-ui gets each enabled module and a
 *     ModuleDisabledException for each disabled one;
 *   - an invalid config.yml names the bad setting and stops startup (PocketMine-MP 5
 *     shuts down when any plugin fails to enable);
 *   - otherwise no errors, crashes or leftover server process.
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
    fwrite(STDERR, "Usage: php scripts/smoke.php <path/to/PocketMine-MP.phar> [--php=<php>] [--port=<port>]\n");
    exit(2);
}
$pluginPhar = $root . '/out/nk-ui.phar';
if (!is_file($pluginPhar)) {
    fwrite(STDERR, "Build the plugin first: composer build\n");
    exit(2);
}

$work = sys_get_temp_dir() . '/nk-ui-smoke-' . bin2hex(random_bytes(4));
$data = $work . '/data';
$plugins = $work . '/plugins';
mkdir($data, 0777, true);
mkdir($plugins, 0777, true);
copy($pluginPhar, $plugins . '/nk-ui.phar');
buildConsumer($plugins . '/NkUiSmokeConsumer.phar');
file_put_contents($data . '/server.properties', implode("\n", [
    'motd=nk-ui smoke test',
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

$scenarios = [
    'default config' => [null, 'forms on, sidebar on, bossbar on', 'consumer: forms ok, sidebar ok, bossbar ok'],
    'sidebar and boss bar off' => [
        "modules:\n  forms: true\n  sidebar: false\n  bossbar: false\n",
        'forms on, sidebar off, bossbar off',
        'consumer: forms ok, sidebar disabled, bossbar disabled',
    ],
    'forms off' => [
        "modules:\n  forms: false\n  sidebar: true\n  bossbar: true\n",
        'forms off, sidebar on, bossbar on',
        'consumer: forms disabled, sidebar ok, bossbar ok',
    ],
    'invalid config' => ["modules:\n  sidebar: maybe\n", null, null],
];

$failures = 0;
foreach ($scenarios as $name => [$config, $modules, $consumer]) {
    $configFile = $data . '/plugin_data/nk-ui/config.yml';
    if ($config !== null) {
        @mkdir(dirname($configFile), 0777, true);
        file_put_contents($configFile, $config);
    }
    $log = $work . '/' . preg_replace('/\W+/', '-', $name) . '.log';
    try {
        $text = runServer($php, $serverPhar, $data, $plugins, $log, $modules === null ? null : ['nkui status', 'nkui demo', 'nkui bogus']);
        $expect = static function (string $needle) use ($text, $name): void {
            if (!str_contains($text, $needle)) {
                throw new RuntimeException('[' . $name . '] expected "' . $needle . '" in the log');
            }
        };
        if ($modules === null) {
            $expect('[nk-ui] Invalid config.yml: modules.sidebar must be true or false');
            $expect("Could not enable plugin 'nk-ui'");
            if (str_contains($text, 'Done (')) {
                throw new RuntimeException('[' . $name . '] server finished starting with an invalid nk-ui config');
            }
        } else {
            $expect('Enabling nk-ui v0.1.0');
            $expect('Modules: ' . $modules);
            $expect((string) $consumer);
            $expect('modules: ' . $modules);
            $expect('Use this command in-game.');
            $expect('Usage: /nkui [status|demo|clear]');
            foreach (['CRITICAL', 'EMERGENCY', 'Fatal error', 'Uncaught', 'ModuleDisabledException:'] as $bad) {
                if (str_contains($text, $bad)) {
                    throw new RuntimeException('[' . $name . '] log contains "' . $bad . '"');
                }
            }
        }
        echo "PASS  $name\n";
    } catch (RuntimeException $e) {
        ++$failures;
        echo 'FAIL  ' . $e->getMessage() . "\n      log: $log\n";
    }
}
echo $failures === 0 ? "Smoke test passed. Work folder: $work\n" : "$failures scenario(s) failed. Work folder: $work\n";
exit($failures === 0 ? 0 : 1);

/** @param ?list<string> $commands console commands to run once started; null if startup must fail */
function runServer(string $php, string $serverPhar, string $data, string $plugins, string $log, ?array $commands): string
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
    $waitFor = static function (callable $done, int $seconds) use ($process, $read): bool {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if ($done($read())) {
                return true;
            }
            if (!proc_get_status($process)['running']) {
                return $done($read());
            }
            usleep(200_000);
        }
        return false;
    };
    try {
        $started = $waitFor(static fn(string $t): bool => str_contains($t, 'Done ('), 90);
        if ($commands === null) {
            if ($started) {
                throw new RuntimeException('Server started although it should not have; see ' . $log);
            }
        } else {
            if (!$started) {
                throw new RuntimeException('Server did not finish starting; see ' . $log);
            }
            foreach ($commands as $command) {
                fwrite($pipes[0], $command . "\n");
                fflush($pipes[0]);
            }
            // The last command prints the usage line; wait for it before stopping.
            $waitFor(static fn(string $t): bool => str_contains($t, 'Usage: /nkui'), 15);
        }
    } finally {
        if (proc_get_status($process)['running']) {
            @fwrite($pipes[0], "stop\n");
            @fflush($pipes[0]);
        }
        $stopped = waitExit($process, 30);
        fclose($pipes[0]);
        if (!$stopped) {
            proc_terminate($process);
            proc_close($process);
            throw new RuntimeException('Server did not stop within 30 seconds; see ' . $log);
        }
        $code = proc_close($process);
    }
    $text = $read() . (string) @file_get_contents($log . '.stderr');
    if ($commands !== null && $code !== 0 && $code !== -1) {
        throw new RuntimeException('Server exited with code ' . $code . '; see ' . $log);
    }
    return $text;
}

/** @param resource $process */
function waitExit($process, int $seconds): bool
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        if (!proc_get_status($process)['running']) {
            return true;
        }
        usleep(200_000);
    }
    return false;
}

/** A plugin that depends on nk-ui and reports which modules it can use. */
function buildConsumer(string $path): void
{
    $phar = new Phar($path);
    $phar->startBuffering();
    $phar->setStub('<?php __HALT_COMPILER();');
    $phar->addFromString('plugin.yml', "name: NkUiSmokeConsumer\nmain: nkuismoke\\Consumer\nversion: 1.0.0\napi: 5.0.0\nsoftdepend: [nk-ui]\n");
    $phar->addFromString('src/nkuismoke/Consumer.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace nkuismoke;

        use ninjaknights\ui\ModuleDisabledException;
        use ninjaknights\ui\NkUi;
        use pocketmine\plugin\PluginBase;

        final class Consumer extends PluginBase {
            protected function onEnable(): void {
                $ui = $this->getServer()->getPluginManager()->getPlugin('nk-ui');
                if (!$ui instanceof NkUi || !$ui->isEnabled()) {
                    $this->getLogger()->info('consumer: nk-ui is not enabled');
                    return;
                }
                $result = [];
                foreach (['forms' => fn() => $ui->forms(), 'sidebar' => fn() => $ui->sidebars(), 'bossbar' => fn() => $ui->bossBars()] as $name => $get) {
                    try {
                        $get();
                        $result[] = $name . ' ok';
                    } catch (ModuleDisabledException) {
                        $result[] = $name . ' disabled';
                    }
                }
                $this->getLogger()->info('consumer: ' . implode(', ', $result));
            }
        }
        PHP);
    $phar->stopBuffering();
}
