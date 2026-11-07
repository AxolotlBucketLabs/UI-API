<?php

declare(strict_types=1);

namespace ninjaknights\ui\form;

use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\player\Player;

/** A yes/no question. The handler receives true, false, or null when the form was closed. */
final class ModalForm implements Form
{
    /** @param \Closure(Player, ?bool): void $onResponse */
    public function __construct(
        private string $title,
        private string $content,
        private \Closure $onResponse,
        private string $yes = 'Yes',
        private string $no = 'No',
    ) {
    }

    public function validate(mixed $data): ?bool
    {
        if ($data !== null && !is_bool($data)) {
            throw new FormValidationException('Expected boolean response');
        }
        return $data;
    }

    public function handleResponse(Player $player, mixed $data): void
    {
        ($this->onResponse)($player, $this->validate($data));
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'type' => 'modal',
            'title' => $this->title,
            'content' => $this->content,
            'button1' => $this->yes,
            'button2' => $this->no,
        ];
    }
}
