<?php

declare(strict_types=1);

namespace ninjaknights\ui\bossbar;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\plugin\PluginDisableEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\BossEventPacket;

/** Answers client boss bar queries and drops bars on quit or plugin disable. */
final class BossBarListener implements Listener
{
    public function __construct(private BossBarManager $bossBars)
    {
    }

    public function onQuit(PlayerQuitEvent $event): void
    {
        $this->bossBars->clear($event->getPlayer());
    }

    public function onPluginDisable(PluginDisableEvent $event): void
    {
        $this->bossBars->removePlugin($event->getPlugin());
    }

    public function onPacket(DataPacketReceiveEvent $event): void
    {
        $packet = $event->getPacket();
        $player = $event->getOrigin()->getPlayer();
        if ($player !== null && $packet instanceof BossEventPacket && $packet->eventType === BossEventPacket::TYPE_QUERY) {
            $this->bossBars->query($player, $packet->bossActorUniqueId);
        }
    }
}
