<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\UI\sidebar\protocol;

use pocketmine\network\mcpe\protocol\ClientboundPacket;

/** Builds score packets for the Axolotl-PM protocol. */
interface ScoreboardPackets
{
    /** @param list<array{int, int, string}> $entries scoreboard id, score, text */
    public function setEntries(string $objective, array $entries): ClientboundPacket;

    /** @param list<array{int, int}> $entries scoreboard id, score */
    public function removeEntries(string $objective, array $entries): ClientboundPacket;
}
