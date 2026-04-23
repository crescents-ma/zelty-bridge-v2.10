<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Constraints\Valid;

class Check
{
    #[PositiveOrZero, Type('int')]
    private int $amount;

    #[NotBlank]
    private ?string $currency = null;

    /**
     * @var Selection[]
     */
    #[Valid]
    private array $selections;

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): Check
    {
        $this->amount = $amount;

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): Check
    {
        $this->currency = $currency;

        return $this;
    }

    public function getSelections(): array
    {
        return $this->selections;
    }

    public function setSelections(array $selections): Check
    {
        $this->selections = $selections;

        return $this;
    }
}