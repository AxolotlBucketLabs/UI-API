<?php

declare(strict_types=1);

namespace ninjaknights\ui;

use pocketmine\event\Listener;
use pocketmine\event\plugin\PluginDisableEvent;
use pocketmine\plugin\Plugin;

/**
 * @internal Shuts a plugin's nk-ui down when that plugin is disabled.
 * PocketMine-MP fires PluginDisableEvent before removing the plugin's own listeners.
 */
final class LifecycleListener implements Listener
{
    public function __construct(private Plugin $plugin, private NkUi $ui)
    {
    }

    public function onPluginDisable(PluginDisableEvent $event): void
    {
        if ($event->getPlugin() === $this->plugin) {
            $this->ui->shutdown();
        }
    }
}
