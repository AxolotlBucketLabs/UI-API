<?php

declare(strict_types=1);

namespace ninjaknights\ui\tests\sidebar;

use ninjaknights\ui\sidebar\SidebarManager;
use ninjaknights\ui\tests\support\CreatesFakeClients;
use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\plugin\Plugin;

final class SidebarOwnershipTest extends TestCase
{
    use CreatesFakeClients;

    public function testHigherPriorityTakesOverAndPreviousOwnerReturnsOnRelease(): void
    {
        $client = $this->fakeClient();
        $sidebars = new SidebarManager();

        $lobby = $sidebars->claim($client->player, 'Lobby', 0);
        $lobby->show('Lobby', ['Players: 4', 'Coins: 10']);
        self::assertSame(['title' => 'Lobby', 'lines' => ['Players: 4', 'Coins: 10']], $client->sidebar());

        $match = $sidebars->claim($client->player, 'Match', 10);
        $match->show('Match', ['Red: 3', 'Blue: 1']);
        self::assertSame(['title' => 'Match', 'lines' => ['Red: 3', 'Blue: 1']], $client->sidebar());
        self::assertSame('Match', $sidebars->activeOwner($client->player));
        self::assertTrue($match->isActive());
        self::assertFalse($lobby->isActive());

        // The covered owner keeps updating without touching the screen.
        $client->clearPackets();
        $lobby->setLines(['Players: 5', 'Coins: 12']);
        self::assertSame([], $client->packets);
        self::assertSame(['title' => 'Match', 'lines' => ['Red: 3', 'Blue: 1']], $client->sidebar());

        $match->release();
        self::assertTrue($match->isReleased());
        self::assertSame(['title' => 'Lobby', 'lines' => ['Players: 5', 'Coins: 12']], $client->sidebar());
        self::assertSame('Lobby', $sidebars->activeOwner($client->player));

        $lobby->release();
        self::assertNull($client->sidebar());
        self::assertNull($sidebars->activeOwner($client->player));
        self::assertCleanProtocol($client);
    }

    public function testLowerPriorityCannotOverwrite(): void
    {
        $client = $this->fakeClient();
        $sidebars = new SidebarManager();
        $sidebars->claim($client->player, 'Match', 10)->show('Match', ['Round 1']);
        $client->clearPackets();

        $late = $sidebars->claim($client->player, 'Lobby', 0);
        $late->show('Lobby', ['Welcome']);

        self::assertSame([], $client->packets);
        self::assertFalse($late->isActive());
        self::assertSame(['title' => 'Match', 'lines' => ['Round 1']], $client->sidebar());
    }

    public function testEqualPriorityKeepsTheEarlierClaim(): void
    {
        $client = $this->fakeClient();
        $sidebars = new SidebarManager();
        $first = $sidebars->claim($client->player, 'First');
        $first->show('First', ['a']);
        $second = $sidebars->claim($client->player, 'Second');
        $second->show('Second', ['b']);

        self::assertSame('First', $sidebars->activeOwner($client->player));
        self::assertSame(['First', 'Second'], array_map(static fn($c) => $c->owner(), $sidebars->claims($client->player)));
        $first->release();
        self::assertSame(['title' => 'Second', 'lines' => ['b']], $client->sidebar());
    }

    public function testHiddenTopClaimStillOwnsTheSidebar(): void
    {
        $client = $this->fakeClient();
        $sidebars = new SidebarManager();
        $sidebars->claim($client->player, 'Lobby')->show('Lobby', ['x']);
        $cutscene = $sidebars->claim($client->player, 'Cutscene', 50);
        $cutscene->show('Cutscene', ['y']);
        $cutscene->hide();

        self::assertNull($client->sidebar(), 'A hidden owner blanks the sidebar instead of revealing lower claims');
        self::assertTrue($cutscene->isActive());

        $cutscene->show('Cutscene', ['z']);
        self::assertSame(['title' => 'Cutscene', 'lines' => ['z']], $client->sidebar());
        $cutscene->release();
        self::assertSame(['title' => 'Lobby', 'lines' => ['x']], $client->sidebar());
        self::assertCleanProtocol($client);
    }

    public function testClaimingAgainReturnsTheSameClaimAndChangesPriority(): void
    {
        $client = $this->fakeClient();
        $sidebars = new SidebarManager();
        $a = $sidebars->claim($client->player, 'A', 1);
        $a->show('A', ['a']);
        $sidebars->claim($client->player, 'B', 5)->show('B', ['b']);

        $again = $sidebars->claim($client->player, 'A', 9);
        self::assertSame($a, $again);
        self::assertSame(9, $a->priority());
        self::assertSame(['title' => 'A', 'lines' => ['a']], $client->sidebar());

        $a->setPriority(0);
        self::assertSame(['title' => 'B', 'lines' => ['b']], $client->sidebar());
        self::assertCleanProtocol($client);
    }

    public function testReleasedClaimIsInert(): void
    {
        $client = $this->fakeClient();
        $sidebars = new SidebarManager();
        $claim = $sidebars->claim($client->player, 'A');
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
        self::assertNull($sidebars->get($client->player, 'A'));
        $fresh = $sidebars->claim($client->player, 'A');
        self::assertNotSame($claim, $fresh);
    }

    public function testPluginDisableReleasesItsOwnersOnly(): void
    {
        $client = $this->fakeClient();
        $other = $this->fakeClient();
        $sidebars = new SidebarManager();
        $plugin = $this->createConfiguredMock(Plugin::class, ['getName' => 'Arena']);

        $sidebars->claim($client->player, 'Lobby', 0)->show('Lobby', ['lobby']);
        $sidebars->claim($client->player, $plugin, 10)->show('Arena', ['arena']);
        $sidebars->claim($client->player, 'Arena/alert', 20)->show('Alert', ['alert']);
        $sidebars->claim($client->player, 'ArenaStats', 5)->show('Stats', ['stats']);
        $sidebars->claim($other->player, 'Arena', 0)->show('Arena', ['other']);
        $client->clearPackets();

        $sidebars->releasePlugin($plugin);

        self::assertSame(['title' => 'Stats', 'lines' => ['stats']], $client->sidebar(), '"ArenaStats" is not owned by "Arena"');
        self::assertNull($other->sidebar());
        // One removal and one redraw: the intermediate "Arena" claim never flashed.
        self::assertCount(1, $client->packetsOf(SetDisplayObjectivePacket::class));
        self::assertCleanProtocol($client);
        self::assertCleanProtocol($other);
    }

    public function testClearAndCloseRemoveEverything(): void
    {
        $a = $this->fakeClient();
        $b = $this->fakeClient();
        $sidebars = new SidebarManager();
        $claimA = $sidebars->claim($a->player, 'X');
        $claimA->show('X', ['1']);
        $claimB = $sidebars->claim($b->player, 'X');
        $claimB->show('X', ['2']);

        $sidebars->clear($a->player);
        self::assertNull($a->sidebar());
        self::assertTrue($claimA->isReleased());
        self::assertSame([], $sidebars->claims($a->player));

        $sidebars->close();
        self::assertNull($b->sidebar());
        self::assertTrue($claimB->isReleased());
        self::assertCleanProtocol($a);
        self::assertCleanProtocol($b);
    }

    public function testDisconnectedPlayerGetsNoPackets(): void
    {
        $client = $this->fakeClient();
        $sidebars = new SidebarManager();
        $claim = $sidebars->claim($client->player, 'A');
        $claim->show('A', ['a']);
        $client->connected = false;
        $claim->setLines(['b']);
        $sidebars->clear($client->player);
        self::assertCleanProtocol($client);
    }

    public function testRejectsEmptyOwner(): void
    {
        $client = $this->fakeClient();
        $this->expectException(\InvalidArgumentException::class);
        (new SidebarManager())->claim($client->player, ' ');
    }
}
