<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Constraints\Valid;

class AccrueInput implements InputInterface
{
    use CredentiableTrait;

    #[NotBlank]
    private ?string $transactionId = null;

    #[NotBlank, Valid]
    private ?Check $check = null;

    #[Type('string')]
    private ?string $phone = null;

    #[Email]
    private ?string $email = null;

    #[Type('string')]
    private ?string $firstName = null;

    #[Type('string')]
    private ?string $lastName = null;

    #[Type('string')]
    private ?string $serialNumber = null;

    public function getCheck(): ?Check
    {
        return $this->check;
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function setTransactionId(?string $transactionId): AccrueInput
    {
        $this->transactionId = $transactionId;
        return $this;
    }

    public function setCheck(?Check $check): AccrueInput
    {
        $this->check = $check;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): AccrueInput
    {
        $this->phone = $phone;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): AccrueInput
    {
        $this->email = $email;

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): AccrueInput
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): AccrueInput
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getSerialNumber(): ?string
    {
        return $this->serialNumber;
    }

    public function setSerialNumber(?string $serialNumber): AccrueInput
    {
        $this->serialNumber = $serialNumber;

        return $this;
    }


}