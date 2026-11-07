<?php

declare(strict_types=1);

namespace ninjaknights\ui\bossbar;

use ninjaknights\ui\bossbar\protocol\BossBarPackets;
use ninjaknights\ui\bossbar\protocol\BossBarProtocol;
use pocketmine\player\Player;

/**
 * One plugin's named boss bars per player. Several bars can be shown at once, and
 * bars of different plugins never clash. close() (called for you when your plugin is
 * disabled) hides every bar this manager created.
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
    public function get(Player $player, string $key): BossBar
    {
        self::validateKey($key);
        $bars = $this->bars[$player] ?? [];
        $bar = $bars[$key] ?? null;
        if ($bar === null) {
            $bar = new BossBar($player, $key, $this->packets);
            $bars[$key] = $bar;
            $this->bars[$player] = $bars;
        }
        return $bar;
    }

    public function has(Player $player, string $key): bool
    {
        return isset(($this->bars[$player] ?? [])[$key]);
    }

    /** @return array<string, BossBar> */
    public function all(Player $player): array
    {
        return $this->bars[$player] ?? [];
    }

    /** Hides and forgets one bar. */
    public function remove(Player $player, string $key): void
    {
        $bars = $this->bars[$player] ?? [];
        if (isset($bars[$key])) {
            $bars[$key]->markRemoved();
            unset($bars[$key]);
            $this->bars[$player] = $bars;
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

    private static function validateKey(string $key): void
    {
        if (trim($key) === '') {
            throw new \InvalidArgumentException('Boss bar key must not be empty');
        }
    }
}
