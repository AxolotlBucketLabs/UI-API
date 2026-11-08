<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\UI\form;

use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\player\Player;

/**
 * A list of buttons.
 *
 * Each button may have its own click handler. The optional form-level handler runs
 * after it with the chosen index, or with null when the player closes the form.
 * Without a form-level handler, closing the form does nothing.
 */
final class SimpleForm implements Form
{
    /** @var list<array{text: string, image?: array{type: string, data: string}}> */
    private array $buttons = [];
    /** @var list<?\Closure(Player): void> */
    private array $handlers = [];

    /** @param ?\Closure(Player, ?int): void $onResponse */
    public function __construct(
        private string $title,
        private string $content = '',
        private ?\Closure $onResponse = null,
    ) {
    }

    /** @param ?\Closure(Player): void $onClick */
    public function button(string $text, ?\Closure $onClick = null, ?FormImage $image = null): self
    {
        $button = ['text' => $text];
        if ($image !== null) {
            $button['image'] = $image->toArray();
        }
        $this->buttons[] = $button;
        $this->handlers[] = $onClick;
        return $this;
    }

    public function buttonCount(): int
    {
        return count($this->buttons);
    }

    /**
     * Responses come from the client, so anything but a real button index is rejected.
     *
     * @return ?int the chosen button, or null when the form was closed
     */
    public function validate(mixed $data): ?int
    {
        if ($data === null) {
            return null;
        }
        if (!is_int($data) || !isset($this->buttons[$data])) {
            throw new FormValidationException('Invalid button index');
        }
        return $data;
    }

    public function handleResponse(Player $player, mixed $data): void
    {
        $choice = $this->validate($data);
        if ($choice !== null) {
            $handler = $this->handlers[$choice];
            if ($handler !== null) {
                $handler($player);
            }
        }
        if ($this->onResponse !== null) {
            ($this->onResponse)($player, $choice);
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['type' => 'form', 'title' => $this->title, 'content' => $this->content, 'buttons' => $this->buttons];
    }
}
