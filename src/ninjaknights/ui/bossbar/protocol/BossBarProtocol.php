<?php

declare(strict_types=1);

namespace ninjaknights\ui\bossbar\protocol;

/** Picks the boss bar packet format of the running server. */
final class BossBarProtocol
{
    private const COLOR_TYPE = 'pocketmine\network\mcpe\protocol\types\BossBarColor';

    public static function detect(): BossBarPackets
    {
        if (!class_exists(self::COLOR_TYPE)) {
            throw new \RuntimeException('Unsupported boss bar protocol: expected PocketMine-MP 5 or Axolotl-PM 5');
        }
        // Axolotl-PM made the color constants an enum; upstream PocketMine-MP keeps ints.
        return (new \ReflectionClass(self::COLOR_TYPE))->isEnum() ? new AxolotlBossBarPackets() : new PocketMineBossBarPackets();
    }
}
