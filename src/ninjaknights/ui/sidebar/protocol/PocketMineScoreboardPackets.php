<?php

declare(strict_types=1);

namespace ninjaknights\ui\sidebar\protocol;

use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;

/** Upstream PocketMine-MP: one change/remove type per packet. */
final class PocketMineScoreboardPackets implements ScoreboardPackets
{
    public function setEntries(string $objective, array $entries): ClientboundPacket
    {
        $list = [];
        foreach ($entries as [$id, $score, $text]) {
            $entry = new ScorePacketEntry();
            $entry->type = ScorePacketEntry::TYPE_FAKE_PLAYER;
            $entry->scoreboardId = $id;
            $entry->objectiveName = $objective;
            $entry->score = $score;
            $entry->customName = $text;
            $list[] = $entry;
        }
        return SetScorePacket::create(SetScorePacket::TYPE_CHANGE, $list);
    }

    public function removeEntries(string $objective, array $entries): ClientboundPacket
    {
        $list = [];
        foreach ($entries as [$id, $score]) {
            $entry = new ScorePacketEntry();
            $entry->scoreboardId = $id;
            $entry->objectiveName = $objective;
            $entry->score = $score;
            $list[] = $entry;
        }
        return SetScorePacket::create(SetScorePacket::TYPE_REMOVE, $list);
    }
}
