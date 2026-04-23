<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Constraints\Type;

class Selection
{
    #[NotBlank]
    private ?string $id = null;

    #[Type('string')]
    private ?string $groupId = null;

    #[NotBlank]
    private ?string $displayName = null;

    private ?int $price = null;

    #[NotBlank, Positive]
    private ?int $quantity = null;

    #[NotBlank, PositiveOrZero]
    private ?int $totalPrice = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(?string $id): Selection
    {
        $this->id = $id;

        return $this;
    }

    public function getGroupId(): ?string
    {
        return $this->groupId;
    }

    public function setGroupId(?string $groupId): Selection
    {
        $this->groupId = $groupId;

        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): Selection
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }

    public function setPrice(?int $price): Selection
    {
        $this->price = $price;

        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(?int $quantity): Selection
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getTotalPrice(): ?int
    {
        return $this->totalPrice;
    }

    public function setTotalPrice(?int $totalPrice): Selection
    {
        $this->totalPrice = $totalPrice;

        return $this;
    }
}