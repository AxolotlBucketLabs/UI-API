<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\UI\bossbar\protocol;

/** Creates the packet builder used by Axolotl-PM. */
final class BossBarProtocol
{
    public static function detect(): BossBarPackets
    {
        return new AxolotlBossBarPackets();
    }
}
