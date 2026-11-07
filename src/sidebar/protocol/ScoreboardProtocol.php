<?php

declare(strict_types=1);

namespace ninjaknights\ui\sidebar\protocol;

/** Picks the score packet format of the running server. */
final class ScoreboardProtocol
{
    private const AXOLOTL_ACTION_ENUM = 'pocketmine\network\mcpe\protocol\types\ScorePacketEntryAction';
    private const POCKETMINE_FAKE_PLAYER = 'pocketmine\network\mcpe\protocol\types\ScorePacketEntry::TYPE_FAKE_PLAYER';

    public static function detect(): ScoreboardPackets
    {
        if (enum_exists(self::AXOLOTL_ACTION_ENUM)) {
            return new AxolotlScoreboardPackets();
        }
        if (defined(self::POCKETMINE_FAKE_PLAYER)) {
            return new PocketMineScoreboardPackets();
        }
        throw new \RuntimeException('Unsupported scoreboard protocol: expected PocketMine-MP 5 or Axolotl-PM 5');
    }
}
