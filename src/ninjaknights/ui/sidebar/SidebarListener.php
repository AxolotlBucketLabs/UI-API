<?php

declare(strict_types=1);

namespace ninjaknights\ui\sidebar;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\plugin\PluginDisableEvent;
use pocketmine\plugin\Plugin;

/**
 * @internal Registered by SidebarManager for its plugin. Drops a player's claims when
 * they leave, and the plugin's claims when it is disabled. PocketMine-MP fires
 * PluginDisableEvent before it removes the plugin's own listeners.
 */
final class SidebarListener implements Listener
{
    public function __construct(private SidebarManager $sidebars, private Plugin $plugin)
    {
    }

    public function onQuit(PlayerQuitEvent $event): void
    {
        $this->sidebars->clear($event->getPlayer());
    }

    public function onPluginDisable(PluginDisableEvent $event): void
    {
        if ($event->getPlugin() === $this->plugin) {
            $this->sidebars->releaseAll();
        }
    }
}
