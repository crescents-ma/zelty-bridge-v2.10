<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints\NotBlank;

class ReverseInput implements InputInterface
{
    use CredentiableTrait;

    #[NotBlank]
    private ?string $transactionId = null;

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function setTransactionId(?string $transactionId): ReverseInput
    {
        $this->transactionId = $transactionId;
        return $this;
    }
}