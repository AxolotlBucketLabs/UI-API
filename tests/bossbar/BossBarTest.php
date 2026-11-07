<?php

declare(strict_types=1);

namespace ninjaknights\ui\tests\bossbar;

use ninjaknights\ui\bossbar\BarColor;
use ninjaknights\ui\bossbar\BarOverlay;
use ninjaknights\ui\bossbar\BossBar;
use ninjaknights\ui\bossbar\BossBarManager;
use ninjaknights\ui\bossbar\protocol\AxolotlBossBarPackets;
use ninjaknights\ui\bossbar\protocol\BossBarProtocol;
use ninjaknights\ui\bossbar\protocol\PocketMineBossBarPackets;
use ninjaknights\ui\tests\support\CreatesFakeClients;
use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\BossEventPacket;
use pocketmine\network\mcpe\protocol\types\BossBarColor;
use pocketmine\plugin\Plugin;

final class BossBarTest extends TestCase
{
    use CreatesFakeClients;

    public function testDetectsTheInstalledProtocol(): void
    {
        $expected = (new \ReflectionClass(BossBarColor::class))->isEnum() ? AxolotlBossBarPackets::class : PocketMineBossBarPackets::class;
        self::assertInstanceOf($expected, BossBarProtocol::detect());
    }

    public function testOwnEnumsMatchTheProtocolIds(): void
    {
        foreach (BarColor::cases() as $color) {
            // An enum case on Axolotl-PM, an int constant on PocketMine-MP.
            self::assertSame($color->value, self::protocolValue(constant(BossBarColor::class . '::' . $color->name)), $color->name);
        }
        self::assertSame([0, 1, 2, 3, 4], array_map(static fn(BarOverlay $o): int => $o->value, BarOverlay::cases()));
    }

    public function testRejectsInvalidProgress(): void
    {
        $client = $this->fakeClient();
        $bar = (new BossBarManager())->get($client->player, 'A');
        foreach ([-0.1, 1.1, NAN, INF, -INF] as $bad) {
            try {
                BossBar::validateProgress($bad);
                self::fail('Accepted progress ' . $bad);
            } catch (\InvalidArgumentException) {
            }
            try {
                $bar->setProgress($bad);
                self::fail('setProgress accepted ' . $bad);
            } catch (\InvalidArgumentException) {
            }
        }
        BossBar::validateProgress(0.0);
        BossBar::validateProgress(1.0);
        self::assertSame(1.0, $bar->progress());
    }

    public function testShowUpdateAndHide(): void
    {
        $client = $this->fakeClient();
        $bars = new BossBarManager();
        $bar = $bars->get($client->player, 'Arena/timer');
        self::assertSame($bar, $bars->get($client->player, 'Arena/timer'));

        $bar->setTitle('Stored while hidden');
        self::assertSame([], $client->packets, 'Hidden bars send nothing');

        $bar->show('Round', 0.5, BarColor::RED, BarOverlay::NOTCHED_10);
        $bar->setTitle('Halfway');
        $bar->setProgress(0.25);
        $bar->setStyle(BarColor::GREEN);
        self::assertSame(['title' => 'Halfway', 'progress' => 0.25, 'visible' => true], $client->bossBar($bar->id()));
        self::assertSame(BarColor::GREEN, $bar->color());
        self::assertSame(BarOverlay::NOTCHED_10, $bar->overlay(), 'A null overlay keeps the current one');

        $bar->show('Again');
        self::assertSame(BarColor::GREEN, $bar->color(), 'show() without a color keeps the current one');

        $bar->hide();
        self::assertSame([], $client->visibleBossBars());
        self::assertFalse($bar->isVisible());
        self::assertCleanProtocol($client);
    }

    public function testSeveralBarsAndQueryResend(): void
    {
        $client = $this->fakeClient();
        $bars = new BossBarManager();
        $a = $bars->get($client->player, 'A');
        $b = $bars->get($client->player, 'B');
        self::assertNotSame($a->id(), $b->id());
        $a->show('A');
        $b->show('B');
        self::assertSame(['A', 'B'], $client->visibleBossBars());

        $client->clearPackets();
        $bars->query($client->player, $a->id());
        $bars->query($client->player, 123456789);
        $shows = array_values(array_filter(
            $client->packetsOf(BossEventPacket::class),
            static fn(BossEventPacket $p): bool => $p->eventType === BossEventPacket::TYPE_SHOW
        ));
        self::assertCount(1, $shows);
        self::assertSame($a->id(), $shows[0]->bossActorUniqueId);

        $bars->remove($client->player, 'A');
        self::assertTrue($a->isRemoved());
        self::assertFalse($bars->has($client->player, 'A'));
        self::assertSame(['B'], $client->visibleBossBars());
        $a->show('Ignored');
        self::assertSame(['B'], $client->visibleBossBars(), 'Removed bars are inert');
        self::assertNotSame($a, $bars->get($client->player, 'A'));
        self::assertCleanProtocol($client);
    }

    public function testPluginDisableClearAndClose(): void
    {
        $client = $this->fakeClient();
        $other = $this->fakeClient();
        $bars = new BossBarManager();
        $plugin = $this->createConfiguredMock(Plugin::class, ['getName' => 'Arena']);
        $bars->get($client->player, $plugin)->show('Arena');
        $bars->get($client->player, 'Arena/timer')->show('Timer');
        $bars->get($client->player, 'ArenaStats')->show('Stats');
        $bars->get($other->player, 'Arena')->show('Other');

        $bars->removePlugin($plugin);
        self::assertSame(['Stats'], $client->visibleBossBars());
        self::assertSame([], $other->visibleBossBars());
        self::assertSame(['ArenaStats'], array_keys($bars->all($client->player)));

        $bars->get($other->player, 'Lobby')->show('Lobby');
        $bars->clear($client->player);
        self::assertSame([], $client->visibleBossBars());
        self::assertSame([], $bars->all($client->player));

        $client->clearPackets();
        $bars->close();
        self::assertSame([], $other->visibleBossBars());
        self::assertSame([], $client->packets, 'Cleared players get nothing on close');
        self::assertCleanProtocol($client);
        self::assertCleanProtocol($other);
    }

    public function testDisconnectedPlayerGetsNoPackets(): void
    {
        $client = $this->fakeClient();
        $bars = new BossBarManager();
        $bar = $bars->get($client->player, 'A');
        $bar->show('A');
        $client->connected = false;
        $bar->setProgress(0.1);
        $bars->clear($client->player);
        self::assertCleanProtocol($client);
    }

    public function testRejectsEmptyKey(): void
    {
        $client = $this->fakeClient();
        $this->expectException(\InvalidArgumentException::class);
        (new BossBarManager())->get($client->player, '');
    }

    private static function protocolValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
