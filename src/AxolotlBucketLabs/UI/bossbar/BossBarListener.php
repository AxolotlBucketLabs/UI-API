<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\UI\bossbar;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\plugin\PluginDisableEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\BossEventPacket;
use pocketmine\plugin\Plugin;

/**
 * @internal Registered by BossBarManager for its plugin. Answers client boss bar
 * queries, drops a player's bars when they leave, and hides all of the plugin's bars
 * when it is disabled.
 */
final class BossBarListener implements Listener
{
    public function __construct(private BossBarManager $bossBars, private Plugin $plugin)
    {
    }

    public function onQuit(PlayerQuitEvent $event): void
    {
        $this->bossBars->clear($event->getPlayer());
    }

    public function onPluginDisable(PluginDisableEvent $event): void
    {
        if ($event->getPlugin() === $this->plugin) {
            $this->bossBars->close();
        }
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
