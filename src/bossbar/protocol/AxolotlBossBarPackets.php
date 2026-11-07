<?php

declare(strict_types=1);

namespace ninjaknights\ui\bossbar\protocol;

use ninjaknights\ui\bossbar\BarColor;
use ninjaknights\ui\bossbar\BarOverlay;
use pocketmine\network\mcpe\protocol\BossEventPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\types\BossBarColor;
use pocketmine\network\mcpe\protocol\types\BossBarOverlay;

/** Axolotl-PM: color and overlay are protocol enums. */
final class AxolotlBossBarPackets implements BossBarPackets
{
    public function show(int $id, string $title, float $progress, BarColor $color, BarOverlay $overlay): ClientboundPacket
    {
        return BossEventPacket::show($id, $title, $progress, BossBarColor::from($color->value), BossBarOverlay::from($overlay->value));
    }

    public function style(int $id, BarColor $color, BarOverlay $overlay): ClientboundPacket
    {
        return BossEventPacket::properties($id, BossBarColor::from($color->value), BossBarOverlay::from($overlay->value));
    }
}
