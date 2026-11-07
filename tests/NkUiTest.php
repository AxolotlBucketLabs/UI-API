<?php

declare(strict_types=1);

namespace ninjaknights\ui\tests;

use ninjaknights\ui\bossbar\BossBarListener;
use ninjaknights\ui\LifecycleListener;
use ninjaknights\ui\ModuleConfig;
use ninjaknights\ui\ModuleDisabledException;
use ninjaknights\ui\NkUi;
use ninjaknights\ui\sidebar\SidebarBus;
use ninjaknights\ui\sidebar\SidebarListener;
use ninjaknights\ui\tests\support\CreatesFakeClients;
use PHPUnit\Framework\TestCase;
use pocketmine\event\Listener;
use pocketmine\event\plugin\PluginDisableEvent;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginManager;
use pocketmine\Server;

final class NkUiTest extends TestCase
{
    use CreatesFakeClients;

    /** @var list<Listener> */
    private array $listeners = [];

    protected function setUp(): void
    {
        unset($GLOBALS[SidebarBus::GLOBAL_KEY]);
        $this->listeners = [];
        $events = $this->getMockBuilder(PluginManager::class)->disableOriginalConstructor()->onlyMethods(['registerEvents'])->getMock();
        $events->method('registerEvents')->willReturnCallback(function (Listener $listener): void {
            $this->listeners[] = $listener;
        });
        $server = $this->getMockBuilder(Server::class)->disableOriginalConstructor()->onlyMethods(['getPluginManager'])->getMock();
        $server->method('getPluginManager')->willReturn($events);
        self::setServer($server);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS[SidebarBus::GLOBAL_KEY]);
        self::setServer(null);
    }

    /** Stands in for the running server, which NkUi reaches through Server::getInstance(). */
    private static function setServer(?Server $server): void
    {
        (new \ReflectionProperty(Server::class, 'instance'))->setValue(null, $server);
    }

    private function plugin(string $name, bool $enabled = true): Plugin
    {
        return $this->createConfiguredMock(Plugin::class, ['getName' => $name, 'isEnabled' => $enabled]);
    }

    /** @return list<class-string> */
    private function listenerClasses(): array
    {
        return array_map(static fn(Listener $l): string => $l::class, $this->listeners);
    }

    public function testRegistersOnlyEnabledModules(): void
    {
        $ui = NkUi::register($this->plugin('OnlyForms'), new ModuleConfig(forms: true, sidebar: false, bossBar: false));
        self::assertSame(['forms' => true, 'sidebar' => false, 'bossbar' => false], $ui->modules());
        self::assertSame([LifecycleListener::class], $this->listenerClasses(), 'Disabled modules register no listeners');
        $ui->forms();
        try {
            $ui->sidebars();
            self::fail('Returned a disabled sidebar module');
        } catch (ModuleDisabledException $e) {
            self::assertStringContainsString('sidebar module is not enabled', $e->getMessage());
        }
        $this->expectException(ModuleDisabledException::class);
        $ui->bossBars();
    }

    public function testAllModulesByDefaultAndRegisterIsIdempotent(): void
    {
        $plugin = $this->plugin('Everything');
        $ui = NkUi::register($plugin);
        self::assertSame(['forms' => true, 'sidebar' => true, 'bossbar' => true], $ui->modules());
        self::assertSame([SidebarListener::class, BossBarListener::class, LifecycleListener::class], $this->listenerClasses());
        self::assertSame($ui, NkUi::register($plugin, new ModuleConfig(sidebar: false)));
        self::assertCount(3, $this->listeners, 'A second register() adds nothing');
        $ui->shutdown();
    }

    public function testRequiresAnEnabledPlugin(): void
    {
        $this->expectException(\LogicException::class);
        NkUi::register($this->plugin('NotYet', false));
    }

    public function testPluginDisableReleasesItsClaimsAndBars(): void
    {
        $client = $this->fakeClient();
        $arenaPlugin = $this->plugin('Arena');
        $arena = NkUi::register($arenaPlugin);
        $lobby = NkUi::register($this->plugin('Lobby'));

        $lobby->sidebars()->claim($client->player, 'Lobby')->show('Lobby', ['lobby']);
        $arena->sidebars()->claim($client->player, 'Arena', 10)->show('Arena', ['arena']);
        $arena->bossBars()->get($client->player, 'timer')->show('Timer');
        self::assertSame('Arena', $lobby->sidebars()->activeOwner($client->player), 'Both plugins share the sidebar');

        foreach ($this->listeners as $listener) {
            if ($listener instanceof LifecycleListener) {
                $listener->onPluginDisable(new PluginDisableEvent($arenaPlugin));
            }
        }

        self::assertSame(['title' => 'Lobby', 'lines' => ['lobby']], $client->sidebar());
        self::assertSame([], $client->visibleBossBars());
        self::assertNotSame($arena, NkUi::register($arenaPlugin), 'After shutdown register() starts fresh');
        self::assertCleanProtocol($client);
    }
}
