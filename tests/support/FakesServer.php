<?php

declare(strict_types=1);

namespace ninjaknights\ui\tests\support;

use ninjaknights\ui\sidebar\SidebarBus;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use pocketmine\event\Listener;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginManager;
use pocketmine\Server;

/**
 * Stands in for the running server, which the managers reach through
 * Server::getInstance() to register their listeners. Registered listeners are kept
 * in $listeners. Also resets the shared sidebar host between tests.
 *
 * @phpstan-require-extends TestCase
 */
trait FakesServer
{
    /** @var list<array{Listener, Plugin}> */
    protected array $listeners = [];

    #[Before]
    protected function installFakeServer(): void
    {
        unset($GLOBALS[SidebarBus::GLOBAL_KEY]);
        $this->listeners = [];
        $events = $this->getMockBuilder(PluginManager::class)->disableOriginalConstructor()->onlyMethods(['registerEvents'])->getMock();
        $events->method('registerEvents')->willReturnCallback(function (Listener $listener, Plugin $plugin): void {
            $this->listeners[] = [$listener, $plugin];
        });
        $server = $this->getMockBuilder(Server::class)->disableOriginalConstructor()->onlyMethods(['getPluginManager'])->getMock();
        $server->method('getPluginManager')->willReturn($events);
        (new \ReflectionProperty(Server::class, 'instance'))->setValue(null, $server);
    }

    #[After]
    protected function removeFakeServer(): void
    {
        unset($GLOBALS[SidebarBus::GLOBAL_KEY]);
        (new \ReflectionProperty(Server::class, 'instance'))->setValue(null, null);
    }

    protected function plugin(string $name = 'TestPlugin'): Plugin
    {
        return $this->createConfiguredMock(Plugin::class, ['getName' => $name, 'isEnabled' => true]);
    }
}
