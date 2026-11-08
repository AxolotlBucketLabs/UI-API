<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\UI\Tests\bossbar;

use AxolotlBucketLabs\UI\bossbar\BarColor;
use AxolotlBucketLabs\UI\bossbar\BarOverlay;
use AxolotlBucketLabs\UI\bossbar\BossBar;
use AxolotlBucketLabs\UI\bossbar\BossBarListener;
use AxolotlBucketLabs\UI\bossbar\BossBarManager;
use AxolotlBucketLabs\UI\bossbar\protocol\AxolotlBossBarPackets;
use AxolotlBucketLabs\UI\bossbar\protocol\BossBarProtocol;
use AxolotlBucketLabs\UI\Tests\support\CreatesFakeClients;
use AxolotlBucketLabs\UI\Tests\support\FakesServer;
use PHPUnit\Framework\TestCase;
use pocketmine\event\plugin\PluginDisableEvent;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\BossEventPacket;
use pocketmine\plugin\Plugin;
use pocketmine\network\mcpe\protocol\types\BossBarColor;

final class BossBarTest extends TestCase
{
    use CreatesFakeClients;
    use FakesServer;

    public function testDetectsTheInstalledProtocol(): void
    {
        self::assertInstanceOf(AxolotlBossBarPackets::class, BossBarProtocol::detect());
    }

    public function testOwnEnumsMatchTheProtocolIds(): void
    {
        foreach (BarColor::cases() as $color) {
            self::assertSame($color->value, self::protocolValue(constant(BossBarColor::class . '::' . $color->name)), $color->name);
        }
        self::assertSame([0, 1, 2, 3, 4], array_map(static fn(BarOverlay $o): int => $o->value, BarOverlay::cases()));
    }

    public function testRejectsInvalidProgress(): void
    {
        $client = $this->fakeClient();
        $bar = (new BossBarManager($this->plugin()))->get($client->player, 'A');
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
        $bars = new BossBarManager($this->plugin());
        $bar = $bars->get($client->player, 'timer');
        self::assertSame($bar, $bars->get($client->player, 'timer'));

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

    public function testShowsAClientKnownActorBeforeTheBossBar(): void
    {
        $client = $this->fakeClient();
        $bar = (new BossBarManager($this->plugin()))->get($client->player, 'timer');

        $bar->show('Round');

        self::assertInstanceOf(AddActorPacket::class, $client->packets[0]);
        self::assertSame($bar->id(), $client->packets[0]->actorRuntimeId);
        self::assertInstanceOf(BossEventPacket::class, $client->packets[1]);
        self::assertSame(BossEventPacket::TYPE_SHOW, $client->packets[1]->eventType);
        self::assertCleanProtocol($client);
    }

    public function testSeveralBarsAndQueryResend(): void
    {
        $client = $this->fakeClient();
        $bars = new BossBarManager($this->plugin());
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

    public function testPluginsHaveSeparateBarsAndCloseOnlyTheirOwn(): void
    {
        $client = $this->fakeClient();
        $arenaPlugin = new BossBarManager($this->plugin());
        $lobbyPlugin = new BossBarManager($this->plugin());
        $arenaPlugin->get($client->player, 'timer')->show('Arena timer');
        $lobbyPlugin->get($client->player, 'timer')->show('Lobby timer');
        self::assertSame(['Arena timer', 'Lobby timer'], $client->visibleBossBars(), 'The same key in two plugins gives two bars');

        $arenaPlugin->close();
        self::assertSame(['Lobby timer'], $client->visibleBossBars());
        self::assertCleanProtocol($client);
    }

    public function testClearAndClose(): void
    {
        $client = $this->fakeClient();
        $other = $this->fakeClient();
        $bars = new BossBarManager($this->plugin());
        $bars->get($client->player, 'a')->show('A');
        $bars->get($client->player, 'b')->show('B');
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
        $bars = new BossBarManager($this->plugin());
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
        (new BossBarManager($this->plugin()))->get($client->player, '');
    }

    public function testRegistersAListenerThatHidesOnlyItsOwnPluginsBars(): void
    {
        $client = $this->fakeClient();
        $arena = $this->plugin('Arena');
        $lobby = $this->plugin('Lobby');
        (new BossBarManager($arena))->get($client->player, 'timer')->show('Arena timer');
        (new BossBarManager($lobby))->get($client->player, 'timer')->show('Lobby timer');
        self::assertSame([$arena, $lobby], array_map(static fn(array $l): Plugin => $l[1], $this->listeners));

        foreach ($this->listeners as [$listener]) {
            if ($listener instanceof BossBarListener) {
                $listener->onPluginDisable(new PluginDisableEvent($arena));
            }
        }
        self::assertSame(['Lobby timer'], $client->visibleBossBars());
        self::assertCleanProtocol($client);
    }

    private static function protocolValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
