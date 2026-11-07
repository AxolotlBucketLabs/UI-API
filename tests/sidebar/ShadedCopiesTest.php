<?php

declare(strict_types=1);

namespace ninjaknights\ui\tests\sidebar;

use ninjaknights\ui\sidebar\Sidebar;
use ninjaknights\ui\sidebar\SidebarBus;
use ninjaknights\ui\sidebar\SidebarManager;
use ninjaknights\ui\tests\support\CreatesFakeClients;
use ninjaknights\ui\tests\support\FakesServer;
use PHPUnit\Framework\TestCase;

/**
 * Two plugins that each shade their own copy of nk-ui must still share one sidebar.
 *
 * This builds a real second copy of the library with its namespace renamed, as a
 * virion injector does, loads it next to the original and lets both claim the same
 * player's sidebar.
 */
final class ShadedCopiesTest extends TestCase
{
    use CreatesFakeClients;
    use FakesServer;

    private const SHADED_PREFIX = 'nkuishadetest\libs\ninjaknights\ui\\';

    private static string $prefix = self::SHADED_PREFIX;

    public static function setUpBeforeClass(): void
    {
        $source = dirname(__DIR__, 2) . '/src/ninjaknights/ui';
        $target = sys_get_temp_dir() . '/nk-ui-shaded-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($source) + 1);
            $code = (string) file_get_contents($file->getPathname());
            $path = $target . '/' . str_replace('\\', '/', $relative);
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, str_replace('ninjaknights\ui', rtrim(self::SHADED_PREFIX, '\\'), $code));
        }
        spl_autoload_register(static function (string $class) use ($target): void {
            if (str_starts_with($class, self::SHADED_PREFIX)) {
                $path = $target . '/' . str_replace('\\', '/', substr($class, strlen(self::SHADED_PREFIX))) . '.php';
                if (is_file($path)) {
                    require $path;
                }
            }
        });
    }

    public function testShadedCopyIsADifferentClass(): void
    {
        $shaded = $this->shadedManager();
        self::assertNotInstanceOf(SidebarManager::class, $shaded);
        self::assertStringStartsWith(self::SHADED_PREFIX, $shaded::class);
    }

    public function testShadedCopiesShareOneSidebar(): void
    {
        $client = $this->fakeClient();
        $lobbyPlugin = new SidebarManager($this->plugin("Lobby"));      // plugin A, original namespace
        $arenaPlugin = $this->shadedManager();     // plugin B, its own shaded copy

        $lobby = $lobbyPlugin->claim($client->player, 'Lobby', 0);
        $lobby->show('Lobby', ['Players: 4']);

        $match = self::call($arenaPlugin, 'claim', $client->player, 'Arena', 10);
        self::assertIsObject($match);
        self::call($match, 'show', 'Match', ['Red: 3']);

        self::assertSame(['title' => 'Match', 'lines' => ['Red: 3']], $client->sidebar(), 'The shaded copy took over');
        self::assertSame('Arena', $lobbyPlugin->activeOwner($client->player), 'The original copy sees the shaded copy\'s claim');
        self::assertFalse($lobby->isActive());

        $lobby->setLines(['Players: 5']);
        self::assertSame(['title' => 'Match', 'lines' => ['Red: 3']], $client->sidebar(), 'The original copy cannot overwrite it');

        self::call($match, 'release');
        self::assertSame(['title' => 'Lobby', 'lines' => ['Players: 5']], $client->sidebar(), 'The previous owner came back');
        self::assertCleanProtocol($client);
    }

    public function testDisablingOnePluginReleasesOnlyItsClaims(): void
    {
        $client = $this->fakeClient();
        $arenaPlugin = $this->shadedManager();     // starts first: its copy creates the host
        $lobbyPlugin = new SidebarManager($this->plugin("Lobby"));

        $lobbyPlugin->claim($client->player, 'Lobby', 0)->show('Lobby', ['lobby']);
        $match = self::call($arenaPlugin, 'claim', $client->player, 'Arena', 10);
        self::assertIsObject($match);
        self::call($match, 'show', 'Match', ['arena']);

        self::call($arenaPlugin, 'releaseAll');

        self::assertSame(['title' => 'Lobby', 'lines' => ['lobby']], $client->sidebar());
        self::assertSame(['Lobby'], array_map(static fn(Sidebar $s): string => $s->owner(), $lobbyPlugin->claims($client->player)));
        self::assertCleanProtocol($client);
    }

    public function testRejectsAForeignValueUnderTheKey(): void
    {
        $GLOBALS[SidebarBus::GLOBAL_KEY] = ['protocol' => 1, 'claim' => 'not a closure'];
        $this->expectException(\UnexpectedValueException::class);
        new SidebarManager($this->plugin());
    }

    private function shadedManager(): object
    {
        $class = self::$prefix . 'sidebar\SidebarManager';
        self::assertTrue(class_exists($class), 'Shaded copy did not load');
        return new $class($this->plugin('Arena'));
    }

    private static function call(object $object, string $method, mixed ...$args): mixed
    {
        $call = [$object, $method];
        self::assertIsCallable($call);
        return $call(...$args);
    }
}
