<?php

namespace App\DTO\Response;

use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Constraints\Valid;

class InventoryItem extends AbstractResponse
{
    public const TYPE_GROUP = 'group';
    public const TYPE_ITEM = 'item';

    #[NotBlank, Choice(choices: [self::TYPE_GROUP, self::TYPE_ITEM])]
    private ?string $type = null;

    #[NotBlank, Type('string')]
    private ?string $id = null;

    #[NotBlank, Type('string')]
    private ?string $title = null;

    /**
     * @var InventoryItem[]|null
     */
    #[Valid]
    private ?array $items = null;

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): InventoryItem
    {
        $this->type = $type;

        return $this;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(?string $id): InventoryItem
    {
        $this->id = $id;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): InventoryItem
    {
        $this->title = $title;

        return $this;
    }

    public function getItems(): ?array
    {
        return $this->items;
    }

    public function setItems(?array $items): InventoryItem
    {
        $this->items = $items;

        return $this;
    }

}