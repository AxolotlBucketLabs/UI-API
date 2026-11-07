<?php

declare(strict_types=1);

namespace ninjaknights\ui\tests\sidebar;

use ninjaknights\ui\sidebar\Sidebar;
use ninjaknights\ui\sidebar\SidebarBus;
use ninjaknights\ui\sidebar\SidebarListener;
use ninjaknights\ui\sidebar\SidebarManager;
use ninjaknights\ui\tests\support\CreatesFakeClients;
use ninjaknights\ui\tests\support\FakesServer;
use PHPUnit\Framework\TestCase;
use pocketmine\event\plugin\PluginDisableEvent;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\plugin\Plugin;

/** Two managers on one bus stand for two plugins on one server. */
final class SidebarOwnershipTest extends TestCase
{
    use CreatesFakeClients;
    use FakesServer;

    /** @return array{SidebarManager, SidebarManager} a lobby plugin and an arena plugin sharing one bus */
    private function plugins(): array
    {
        $bus = SidebarBus::isolated();
        return [new SidebarManager($this->plugin("Lobby"), $bus), new SidebarManager($this->plugin("Arena"), $bus)];
    }

    public function testHigherPriorityTakesOverAndPreviousOwnerReturnsOnRelease(): void
    {
        [$lobbyPlugin, $arenaPlugin] = $this->plugins();
        $client = $this->fakeClient();

        $lobby = $lobbyPlugin->claim($client->player, 'Lobby', 0);
        $lobby->show('Lobby', ['Players: 4', 'Coins: 10']);
        self::assertSame(['title' => 'Lobby', 'lines' => ['Players: 4', 'Coins: 10']], $client->sidebar());

        $match = $arenaPlugin->claim($client->player, 'Arena', 10);
        $match->show('Match', ['Red: 3', 'Blue: 1']);
        self::assertSame(['title' => 'Match', 'lines' => ['Red: 3', 'Blue: 1']], $client->sidebar());
        self::assertSame('Arena', $lobbyPlugin->activeOwner($client->player), 'Every plugin sees the same owner');
        self::assertTrue($match->isActive());
        self::assertFalse($lobby->isActive());

        // The covered owner keeps updating without touching the screen.
        $client->clearPackets();
        $lobby->setLines(['Players: 5', 'Coins: 12']);
        self::assertSame([], $client->packets);
        self::assertSame(['Players: 5', 'Coins: 12'], $lobby->lines());
        self::assertSame(['title' => 'Match', 'lines' => ['Red: 3', 'Blue: 1']], $client->sidebar());

        $match->release();
        self::assertTrue($match->isReleased());
        self::assertSame(['title' => 'Lobby', 'lines' => ['Players: 5', 'Coins: 12']], $client->sidebar());
        self::assertSame('Lobby', $arenaPlugin->activeOwner($client->player));

        $lobby->release();
        self::assertNull($client->sidebar());
        self::assertNull($lobbyPlugin->activeOwner($client->player));
        self::assertCleanProtocol($client);
    }

    public function testLowerPriorityCannotOverwrite(): void
    {
        [$lobbyPlugin, $arenaPlugin] = $this->plugins();
        $client = $this->fakeClient();
        $arenaPlugin->claim($client->player, 'Arena', 10)->show('Match', ['Round 1']);
        $client->clearPackets();

        $late = $lobbyPlugin->claim($client->player, 'Lobby', 0);
        $late->show('Lobby', ['Welcome']);

        self::assertSame([], $client->packets);
        self::assertFalse($late->isActive());
        self::assertSame(['title' => 'Match', 'lines' => ['Round 1']], $client->sidebar());
    }

    public function testEqualPriorityKeepsTheEarlierClaim(): void
    {
        [$lobbyPlugin, $arenaPlugin] = $this->plugins();
        $client = $this->fakeClient();
        $first = $lobbyPlugin->claim($client->player, 'First');
        $first->show('First', ['a']);
        $arenaPlugin->claim($client->player, 'Second')->show('Second', ['b']);

        self::assertSame('First', $arenaPlugin->activeOwner($client->player));
        self::assertSame(['First', 'Second'], array_map(static fn(Sidebar $c): string => $c->owner(), $arenaPlugin->claims($client->player)));
        $first->release();
        self::assertSame(['title' => 'Second', 'lines' => ['b']], $client->sidebar());
    }

    public function testHiddenTopClaimStillOwnsTheSidebar(): void
    {
        [$lobbyPlugin, $arenaPlugin] = $this->plugins();
        $client = $this->fakeClient();
        $lobbyPlugin->claim($client->player, 'Lobby')->show('Lobby', ['x']);
        $cutscene = $arenaPlugin->claim($client->player, 'Cutscene', 50);
        $cutscene->show('Cutscene', ['y']);
        $cutscene->hide();

        self::assertNull($client->sidebar(), 'A hidden owner blanks the sidebar instead of revealing lower claims');
        self::assertTrue($cutscene->isActive());
        self::assertFalse($cutscene->isVisible());

        $cutscene->show('Cutscene', ['z']);
        self::assertSame(['title' => 'Cutscene', 'lines' => ['z']], $client->sidebar());
        $cutscene->release();
        self::assertSame(['title' => 'Lobby', 'lines' => ['x']], $client->sidebar());
        self::assertCleanProtocol($client);
    }

    public function testClaimingAgainReturnsTheSameClaimAndChangesPriority(): void
    {
        [$lobbyPlugin, $arenaPlugin] = $this->plugins();
        $client = $this->fakeClient();
        $a = $lobbyPlugin->claim($client->player, 'A', 1);
        $a->show('A', ['a']);
        $arenaPlugin->claim($client->player, 'B', 5)->show('B', ['b']);

        $again = $lobbyPlugin->claim($client->player, 'A', 9);
        self::assertSame($a, $again);
        self::assertSame(9, $a->priority());
        self::assertSame(['title' => 'A', 'lines' => ['a']], $client->sidebar());

        $a->setPriority(0);
        self::assertSame(['title' => 'B', 'lines' => ['b']], $client->sidebar());
        self::assertSame($a, $lobbyPlugin->get($client->player, 'A'));
        self::assertCleanProtocol($client);
    }

    public function testReleasedClaimIsInert(): void
    {
        [$lobbyPlugin, $arenaPlugin] = $this->plugins();
        $client = $this->fakeClient();
        $claim = $lobbyPlugin->claim($client->player, 'A');
        $claim->show('A', ['a']);
        $claim->release();
        $client->clearPackets();

        $claim->show('A', ['again']);
        $claim->setTitle('B');
        $claim->setLines(['b']);
        $claim->setPriority(100);
        $claim->hide();
        $claim->release();

        self::assertSame([], $client->packets);
        self::assertSame(['a'], $claim->lines(), 'A released claim keeps its last known state');
        self::assertNull($lobbyPlugin->get($client->player, 'A'));

        // A new claim by the same owner is a different claim; the old handle stays released.
        $fresh = $lobbyPlugin->claim($client->player, 'A');
        self::assertNotSame($claim, $fresh);
        self::assertTrue($claim->isReleased());
        $claim->show('Stale', ['must not show']);
        self::assertSame([], $fresh->lines());
    }

    public function testReleaseAllDropsOnlyThatPluginsClaims(): void
    {
        [$lobbyPlugin, $arenaPlugin] = $this->plugins();
        $client = $this->fakeClient();
        $other = $this->fakeClient();
        $lobbyPlugin->claim($client->player, 'Lobby', 0)->show('Lobby', ['lobby']);
        $arenaPlugin->claim($client->player, 'Arena', 10)->show('Arena', ['arena']);
        $arenaPlugin->claim($client->player, 'Arena/alert', 20)->show('Alert', ['alert']);
        $arenaPlugin->claim($other->player, 'Arena', 0)->show('Arena', ['other']);
        $client->clearPackets();

        $arenaPlugin->releaseAll();

        self::assertSame(['title' => 'Lobby', 'lines' => ['lobby']], $client->sidebar());
        self::assertNull($other->sidebar());
        // One removal and one redraw: the intermediate "Arena" claim never flashed.
        self::assertCount(1, $client->packetsOf(SetDisplayObjectivePacket::class));
        self::assertCleanProtocol($client);
        self::assertCleanProtocol($other);
    }

    public function testClearRemovesEveryPluginsClaims(): void
    {
        [$lobbyPlugin, $arenaPlugin] = $this->plugins();
        $client = $this->fakeClient();
        $lobby = $lobbyPlugin->claim($client->player, 'Lobby');
        $lobby->show('Lobby', ['1']);
        $arena = $arenaPlugin->claim($client->player, 'Arena', 5);
        $arena->show('Arena', ['2']);

        $lobbyPlugin->clear($client->player);
        self::assertNull($client->sidebar());
        self::assertTrue($lobby->isReleased());
        self::assertTrue($arena->isReleased());
        self::assertSame([], $arenaPlugin->claims($client->player));
        self::assertCleanProtocol($client);
    }

    public function testDisconnectedPlayerGetsNoPackets(): void
    {
        [$lobbyPlugin, $arenaPlugin] = $this->plugins();
        $client = $this->fakeClient();
        $claim = $lobbyPlugin->claim($client->player, 'A');
        $claim->show('A', ['a']);
        $client->connected = false;
        $claim->setLines(['b']);
        $lobbyPlugin->clear($client->player);
        self::assertCleanProtocol($client);
    }

    public function testPluginOwnerUsesThePluginName(): void
    {
        [$lobbyPlugin, $arenaPlugin] = $this->plugins();
        $client = $this->fakeClient();
        $plugin = $this->createConfiguredMock(Plugin::class, ['getName' => 'Arena']);
        self::assertSame('Arena', $arenaPlugin->claim($client->player, $plugin)->owner());
        $this->expectException(\InvalidArgumentException::class);
        $arenaPlugin->claim($client->player, ' ');
    }

    public function testRegistersAListenerThatCleansUpOnlyItsOwnPlugin(): void
    {
        $client = $this->fakeClient();
        $bus = SidebarBus::isolated();
        $lobby = $this->plugin('Lobby');
        $arena = $this->plugin('Arena');
        $lobbyPlugin = new SidebarManager($lobby, $bus);
        $arenaPlugin = new SidebarManager($arena, $bus);
        self::assertCount(2, $this->listeners);
        self::assertSame([$lobby, $arena], array_map(static fn(array $l): Plugin => $l[1], $this->listeners), 'Each listener belongs to its own plugin');

        $lobbyPlugin->claim($client->player, 'Lobby')->show('Lobby', ['lobby']);
        $arenaPlugin->claim($client->player, 'Arena', 10)->show('Arena', ['arena']);
        foreach ($this->listeners as [$listener]) {
            if ($listener instanceof SidebarListener) {
                $listener->onPluginDisable(new PluginDisableEvent($arena));
            }
        }
        self::assertSame(['title' => 'Lobby', 'lines' => ['lobby']], $client->sidebar(), 'Only the disabled plugin\'s claims went');
        self::assertCleanProtocol($client);
    }
}
