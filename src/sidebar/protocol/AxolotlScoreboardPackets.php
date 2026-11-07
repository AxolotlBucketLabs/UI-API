<?php

declare(strict_types=1);

namespace ninjaknights\ui\sidebar\protocol;

use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntryAction;

/** Axolotl-PM: every score entry carries its own action. */
final class AxolotlScoreboardPackets implements ScoreboardPackets
{
    public function setEntries(string $objective, array $entries): ClientboundPacket
    {
        $list = [];
        foreach ($entries as [$id, $score, $text]) {
            $entry = new ScorePacketEntry();
            $entry->action = ScorePacketEntryAction::CHANGE_FAKE_PLAYER;
            $entry->scoreboardId = $id;
            $entry->objectiveName = $objective;
            $entry->score = $score;
            $entry->customName = $text;
            $list[] = $entry;
        }
        return SetScorePacket::create($list);
    }

    public function removeEntries(string $objective, array $entries): ClientboundPacket
    {
        $list = [];
        foreach ($entries as [$id, $score]) {
            $entry = new ScorePacketEntry();
            $entry->action = ScorePacketEntryAction::REMOVE;
            $entry->scoreboardId = $id;
            $entry->objectiveName = $objective;
            $entry->score = $score;
            $list[] = $entry;
        }
        return SetScorePacket::create($list);
    }
}
