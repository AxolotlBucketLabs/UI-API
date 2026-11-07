<?php

declare(strict_types=1);

namespace ninjaknights\ui\sidebar;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\plugin\PluginDisableEvent;

/** Drops claims when players leave or their owning plugin is disabled. */
final class SidebarListener implements Listener
{
    public function __construct(private SidebarManager $sidebars)
    {
    }

    public function onQuit(PlayerQuitEvent $event): void
    {
        $this->sidebars->clear($event->getPlayer());
    }

    public function onPluginDisable(PluginDisableEvent $event): void
    {
        $this->sidebars->releasePlugin($event->getPlugin());
    }
}
