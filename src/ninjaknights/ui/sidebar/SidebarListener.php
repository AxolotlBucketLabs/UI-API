<?php

declare(strict_types=1);

namespace ninjaknights\ui\sidebar;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;

/** Drops a player's claims when they leave. Clearing is idempotent across plugins. */
final class SidebarListener implements Listener
{
    public function __construct(private SidebarManager $sidebars)
    {
    }

    public function onQuit(PlayerQuitEvent $event): void
    {
        $this->sidebars->clear($event->getPlayer());
    }
}
