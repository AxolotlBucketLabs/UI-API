<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\UI\Tests\support;

use pocketmine\player\Player;

/** A Player that can be mocked without a server: it has no world or inventories to dispose. */
class TestPlayer extends Player
{
    public function __destruct()
    {
    }
}
