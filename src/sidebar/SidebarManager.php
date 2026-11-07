<?php

declare(strict_types=1);

namespace ninjaknights\ui\sidebar;

use ninjaknights\ui\sidebar\protocol\ScoreboardPackets;
use ninjaknights\ui\sidebar\protocol\ScoreboardProtocol;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;

/**
 * Shares each player's single sidebar between plugins.
 *
 * A plugin claims the sidebar with an owner name and a priority. The highest
 * priority is shown; on a tie the earlier claim keeps the sidebar. Releasing a claim
 * shows the next one again. Owners are plugin names; a plugin that needs several
 * layers uses "PluginName/suffix". When a plugin is disabled, its claims (the plugin
 * name and every "PluginName/..." owner) are released automatically.
 */
final class SidebarManager
{
    /** @var \WeakMap<Player, SidebarStack> */
    private \WeakMap $stacks;
    private ScoreboardPackets $packets;

    public function __construct(?ScoreboardPackets $packets = null)
    {
        $this->packets = $packets ?? ScoreboardProtocol::detect();
        $this->stacks = new \WeakMap();
    }

    /**
     * Claims the player's sidebar, or returns the owner's existing claim with its
     * priority updated. The claim may not be on screen; see Sidebar::isActive().
     */
    public function claim(Player $player, Plugin|string $owner, int $priority = 0): Sidebar
    {
        $stack = $this->stacks[$player] ?? null;
        if ($stack === null) {
            $stack = new SidebarStack($player, $this->packets);
            $this->stacks[$player] = $stack;
        }
        return $stack->claim(self::ownerName($owner), $priority);
    }

    public function get(Player $player, Plugin|string $owner): ?Sidebar
    {
        return $this->stack($player)?->get(self::ownerName($owner));
    }

    public function release(Player $player, Plugin|string $owner): void
    {
        $this->stack($player)?->release(self::ownerName($owner));
    }

    /** The owner whose claim is on screen for the player, or null. */
    public function activeOwner(Player $player): ?string
    {
        return $this->stack($player)?->top()?->owner();
    }

    /** @return list<Sidebar> the player's claims, highest priority first */
    public function claims(Player $player): array
    {
        return $this->stack($player)?->claims() ?? [];
    }

    /** Releases a plugin's claims for every player: its name and "name/..." owners. */
    public function releasePlugin(Plugin|string $plugin): void
    {
        $name = self::ownerName($plugin);
        $matches = static fn(string $owner): bool => $owner === $name || str_starts_with($owner, $name . '/');
        foreach ($this->stacks as $stack) {
            $stack->releaseWhere($matches);
        }
    }

    /** Releases every claim for the player and removes the sidebar. */
    public function clear(Player $player): void
    {
        $stack = $this->stack($player);
        if ($stack !== null) {
            $stack->releaseAll();
            unset($this->stacks[$player]);
        }
    }

    /** Releases everything for every player. */
    public function close(): void
    {
        foreach ($this->stacks as $stack) {
            $stack->releaseAll();
        }
        $this->stacks = new \WeakMap();
    }

    public static function ownerName(Plugin|string $owner): string
    {
        $name = $owner instanceof Plugin ? $owner->getName() : $owner;
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Sidebar owner must not be empty');
        }
        return $name;
    }

    private function stack(Player $player): ?SidebarStack
    {
        return $this->stacks[$player] ?? null;
    }
}
