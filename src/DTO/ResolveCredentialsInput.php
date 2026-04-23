<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;

class ResolveCredentialsInput implements InputInterface
{
    use CredentiableTrait;

    #[All([new NotBlank(), new Type('string')])]
    private ?array $names = null;

    public function getNames(): ?array
    {
        return $this->names;
    }

    public function setNames(?array $names): ResolveCredentialsInput
    {
        $this->names = $names;

        return $this;
    }
}