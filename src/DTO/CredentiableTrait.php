<?php

namespace App\DTO;

use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;

trait CredentiableTrait
{
    #[All(new Collection(fields: [
        'name' => [new NotBlank(), new Type('string')],
        'value' => [new NotBlank(), new Type('string')],
    ]))]
    private array $credentials = [];

    public function addCredential(string $name, string $value): static
    {
        $this->credentials[] = [
            'name' => $name,
            'value' => $value,
        ];

        return $this;
    }

    #[Ignore]
    public function getCredentialValue(string $name): ?string
    {
        return current(
            array_filter(
                $this->credentials ?? [],
                fn($item) => is_array($item) && $name === ($item['name'] ?? null)
            )
        )['value'] ?? null;
    }

    /**
     * @return array{name: string, value: string}[]>
     */
    public function getCredentials(): array
    {
        return $this->credentials;
    }

    /**
     * @param array<int, array{name: string, value: string}>|Credential[] $credentials
     */
    public function setCredentials(array $credentials): static
    {
        $this->credentials = array_map(
            fn(mixed $v) => $v instanceof Credential ? $v->toArray() : $v,
            $credentials
        );

        return $this;
    }

}