<?php

declare(strict_types=1);

namespace ninjaknights\ui\sidebar\protocol;

use pocketmine\network\mcpe\protocol\ClientboundPacket;

/**
 * Builds score packets. Axolotl-PM and upstream PocketMine-MP encode score entries
 * differently, so each runtime has its own implementation. See ScoreboardProtocol.
 */
interface ScoreboardPackets
{
    /** @param list<array{int, int, string}> $entries scoreboard id, score, text */
    public function setEntries(string $objective, array $entries): ClientboundPacket;

    /** @param list<array{int, int}> $entries scoreboard id, score */
    public function removeEntries(string $objective, array $entries): ClientboundPacket;
}
