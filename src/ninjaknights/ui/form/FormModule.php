<?php

declare(strict_types=1);

namespace ninjaknights\ui\form;

use pocketmine\form\Form;
use pocketmine\player\Player;

/**
 * The form module service. The form classes work on their own; this adds sending
 * that tolerates disconnected players, which suits delayed tasks and callbacks.
 */
final class FormModule
{
    /** Sends the form if the player is still connected. Returns whether it was sent. */
    public function send(Player $player, Form $form): bool
    {
        if (!$player->isConnected()) {
            return false;
        }
        $player->sendForm($form);
        return true;
    }

    /** Closes every form the player has open, from any plugin. */
    public function closeAll(Player $player): void
    {
        if ($player->isConnected()) {
            $player->closeAllForms();
        }
    }
}
