<?php

declare(strict_types=1);

namespace ninjaknights\ui\bossbar;

use ninjaknights\ui\bossbar\protocol\BossBarPackets;
use ninjaknights\ui\bossbar\protocol\BossBarProtocol;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;

/**
 * Named boss bars per player. Several bars can be shown at once.
 *
 * Keys are a plugin name, or "PluginName/suffix" for several bars. When a plugin is
 * disabled, the bars keyed by its name or "name/..." are removed automatically.
 */
final class BossBarManager
{
    /** @var \WeakMap<Player, array<string, BossBar>> */
    private \WeakMap $bars;
    private BossBarPackets $packets;

    public function __construct(?BossBarPackets $packets = null)
    {
        $this->packets = $packets ?? BossBarProtocol::detect();
        $this->bars = new \WeakMap();
    }

    /** Returns the player's bar with this key, creating a hidden one if needed. */
    public function get(Player $player, Plugin|string $key): BossBar
    {
        $key = self::keyName($key);
        $bars = $this->bars[$player] ?? [];
        $bar = $bars[$key] ?? null;
        if ($bar === null) {
            $bar = new BossBar($player, $key, $this->packets);
            $bars[$key] = $bar;
            $this->bars[$player] = $bars;
        }
        return $bar;
    }

    public function has(Player $player, Plugin|string $key): bool
    {
        return isset(($this->bars[$player] ?? [])[self::keyName($key)]);
    }

    /** @return array<string, BossBar> */
    public function all(Player $player): array
    {
        return $this->bars[$player] ?? [];
    }

    /** Hides and forgets one bar. */
    public function remove(Player $player, Plugin|string $key): void
    {
        $key = self::keyName($key);
        $bars = $this->bars[$player] ?? [];
        if (isset($bars[$key])) {
            $bars[$key]->markRemoved();
            unset($bars[$key]);
            $this->bars[$player] = $bars;
        }
    }

    /** Removes a plugin's bars for every player: its name and "name/..." keys. */
    public function removePlugin(Plugin|string $plugin): void
    {
        $name = self::keyName($plugin);
        $players = [];
        foreach ($this->bars as $player => $bars) {
            $players[] = $player;
        }
        foreach ($players as $player) {
            foreach ($this->all($player) as $key => $bar) {
                if ($key === $name || str_starts_with($key, $name . '/')) {
                    $this->remove($player, $key);
                }
            }
        }
    }

    /** Resends the bar the client asked about, if it is one of the player's bars. */
    public function query(Player $player, int $id): void
    {
        foreach ($this->bars[$player] ?? [] as $bar) {
            if ($bar->id() === $id) {
                $bar->resend();
            }
        }
    }

    /** Hides and forgets every bar of the player. */
    public function clear(Player $player): void
    {
        foreach ($this->bars[$player] ?? [] as $bar) {
            $bar->markRemoved();
        }
        unset($this->bars[$player]);
    }

    /** Hides and forgets every bar of every player. */
    public function close(): void
    {
        foreach ($this->bars as $bars) {
            foreach ($bars as $bar) {
                $bar->markRemoved();
            }
        }
        $this->bars = new \WeakMap();
    }

    public static function keyName(Plugin|string $key): string
    {
        $name = $key instanceof Plugin ? $key->getName() : $key;
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Boss bar key must not be empty');
        }
        return $name;
    }
}
