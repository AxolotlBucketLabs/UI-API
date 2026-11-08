<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\UI\Tests\sidebar;

use AxolotlBucketLabs\UI\sidebar\protocol\AxolotlScoreboardPackets;
use AxolotlBucketLabs\UI\sidebar\protocol\ScoreboardProtocol;
use AxolotlBucketLabs\UI\sidebar\Sidebar;
use AxolotlBucketLabs\UI\sidebar\SidebarBus;
use AxolotlBucketLabs\UI\sidebar\SidebarManager;
use AxolotlBucketLabs\UI\Tests\support\CreatesFakeClients;
use AxolotlBucketLabs\UI\Tests\support\FakesServer;
use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;

final class SidebarRenderTest extends TestCase
{
    use CreatesFakeClients;
    use FakesServer;

    public function testDetectsTheInstalledProtocol(): void
    {
        self::assertInstanceOf(AxolotlScoreboardPackets::class, ScoreboardProtocol::detect());
    }

    public function testPacketsEncode(): void
    {
        if (!extension_loaded('encoding')) {
            self::markTestSkipped('Packet encoding needs the encoding extension shipped with Axolotl-PM PHP builds');
        }
        $client = $this->fakeClient();
        $claim = (new SidebarManager($this->plugin(), SidebarBus::isolated()))->claim($client->player, 'A');
        $claim->show('Title', ['', '', 'Score']);
        $claim->setLines(['changed']);
        $claim->release();
        // FakeClient::receive() encodes every packet; reaching here means none threw.
        self::assertGreaterThanOrEqual(4, count($client->packets));
        self::assertCleanProtocol($client);
    }

    public function testBlankAndRepeatedLinesStayDistinctAndOrdered(): void
    {
        $client = $this->fakeClient();
        (new SidebarManager($this->plugin(), SidebarBus::isolated()))->claim($client->player, 'A')->show('Title', ['', 'Same', '', 'Same', '']);
        self::assertSame(['title' => 'Title', 'lines' => ['', 'Same', '', 'Same', '']], $client->sidebar());
    }

    public function testLineChangesAreSentAsADiff(): void
    {
        $client = $this->fakeClient();
        $claim = (new SidebarManager($this->plugin(), SidebarBus::isolated()))->claim($client->player, 'A');
        $claim->show('Score', ['Red: 1', 'Blue: 1', 'Time: 3:00']);
        $client->clearPackets();

        $claim->setLine(0, 'Red: 2');
        self::assertCount(0, $client->packetsOf(SetDisplayObjectivePacket::class), 'Changing a line must not redraw the sidebar');
        self::assertCount(0, $client->packetsOf(RemoveObjectivePacket::class));
        self::assertCount(2, $client->packetsOf(SetScorePacket::class), 'One removal and one addition');
        self::assertSame(['Red: 2', 'Blue: 1', 'Time: 3:00'], $client->sidebar()['lines'] ?? null);

        $client->clearPackets();
        $claim->setLines(['Red: 2', 'Blue: 1', 'Time: 3:00']);
        self::assertSame([], $client->packets, 'Unchanged lines send nothing');

        $claim->setLines(['Red: 2']);
        self::assertSame(['Red: 2'], $client->sidebar()['lines'] ?? null);
        $claim->setLine(1, 'Appended');
        self::assertSame(['Red: 2', 'Appended'], $client->sidebar()['lines'] ?? null);
        $claim->setLines([]);
        self::assertSame(['title' => 'Score', 'lines' => []], $client->sidebar());
        self::assertCleanProtocol($client);
    }

    public function testTitleChangeRedraws(): void
    {
        $client = $this->fakeClient();
        $claim = (new SidebarManager($this->plugin(), SidebarBus::isolated()))->claim($client->player, 'A');
        $claim->show('Old', ['a', 'b']);
        $claim->setTitle('New');
        self::assertSame(['title' => 'New', 'lines' => ['a', 'b']], $client->sidebar());
        self::assertCount(1, $client->packetsOf(RemoveObjectivePacket::class));
        self::assertCleanProtocol($client);
    }

    public function testFifteenLineLimitAndValidation(): void
    {
        $client = $this->fakeClient();
        $claim = (new SidebarManager($this->plugin(), SidebarBus::isolated()))->claim($client->player, 'A');
        $max = array_map(static fn(int $i): string => 'Line ' . $i, range(1, Sidebar::MAX_LINES));
        $claim->show('Max', $max);
        self::assertSame($max, $client->sidebar()['lines'] ?? null);

        foreach ([[...$max, 'one more'], ['a', "b\nc"], [1 => 'not a list']] as $bad) {
            try {
                $claim->show('Bad', $bad); // @phpstan-ignore argument.type
                self::fail('Accepted invalid lines');
            } catch (\InvalidArgumentException) {
            }
        }
        try {
            $claim->setLine(Sidebar::MAX_LINES + 1, 'gap');
            self::fail('Accepted a gap');
        } catch (\OutOfRangeException) {
        }
        self::assertSame($max, $client->sidebar()['lines'] ?? null, 'Rejected input leaves the sidebar unchanged');
    }
}
