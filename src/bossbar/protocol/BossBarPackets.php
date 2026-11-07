<?php

declare(strict_types=1);

namespace ninjaknights\ui\bossbar\protocol;

use ninjaknights\ui\bossbar\BarColor;
use ninjaknights\ui\bossbar\BarOverlay;
use pocketmine\network\mcpe\protocol\ClientboundPacket;

/**
 * Builds the boss bar packets whose color and overlay types differ between
 * Axolotl-PM (enums) and upstream PocketMine-MP (ints). See BossBarProtocol.
 */
interface BossBarPackets
{
    public function show(int $id, string $title, float $progress, BarColor $color, BarOverlay $overlay): ClientboundPacket;

    public function style(int $id, BarColor $color, BarOverlay $overlay): ClientboundPacket;
}
