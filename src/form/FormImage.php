<?php

declare(strict_types=1);

namespace ninjaknights\ui\form;

/** An icon on a SimpleForm button: a resource pack texture path or an image URL. */
final class FormImage
{
    private function __construct(private string $type, private string $data)
    {
        if (trim($data) === '') {
            throw new \InvalidArgumentException('Form image must not be empty');
        }
    }

    /** A texture path from a loaded resource pack, e.g. "textures/items/diamond". */
    public static function path(string $path): self
    {
        return new self('path', $path);
    }

    /** An http(s) image URL. The client downloads it. */
    public static function url(string $url): self
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            throw new \InvalidArgumentException('Form image URL must start with http:// or https://');
        }
        return new self('url', $url);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function data(): string
    {
        return $this->data;
    }

    /** @return array{type: string, data: string} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'data' => $this->data];
    }
}
