<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\UI\sidebar\protocol;

/** Creates the packet builder used by Axolotl-PM. */
final class ScoreboardProtocol
{
    public static function detect(): ScoreboardPackets
    {
        return new AxolotlScoreboardPackets();
    }
}
