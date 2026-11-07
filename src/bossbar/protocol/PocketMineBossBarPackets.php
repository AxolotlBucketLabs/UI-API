<?php

declare(strict_types=1);

namespace ninjaknights\ui\bossbar\protocol;

use ninjaknights\ui\bossbar\BarColor;
use ninjaknights\ui\bossbar\BarOverlay;
use pocketmine\network\mcpe\protocol\BossEventPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;

/** Upstream PocketMine-MP: color and overlay are plain ints. */
final class PocketMineBossBarPackets implements BossBarPackets
{
    public function show(int $id, string $title, float $progress, BarColor $color, BarOverlay $overlay): ClientboundPacket
    {
        return BossEventPacket::show($id, $title, $progress, $color->value, $overlay->value);
    }

    public function style(int $id, BarColor $color, BarOverlay $overlay): ClientboundPacket
    {
        return BossEventPacket::properties($id, $color->value, $overlay->value);
    }
}
