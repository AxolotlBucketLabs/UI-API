<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\UI\bossbar\protocol;

use AxolotlBucketLabs\UI\bossbar\BarColor;
use AxolotlBucketLabs\UI\bossbar\BarOverlay;
use pocketmine\network\mcpe\protocol\ClientboundPacket;

/** Builds boss bar packets for the Axolotl-PM protocol. */
interface BossBarPackets
{
    public function show(int $id, string $title, float $progress, BarColor $color, BarOverlay $overlay): ClientboundPacket;

    public function style(int $id, BarColor $color, BarOverlay $overlay): ClientboundPacket;
}
