<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;

class Credential
{
    private const ATTRS = ['name', 'value'];

    #[NotBlank, Type('string')]
    private string $name;

    #[NotBlank, Type('string')]
    private string $value;

    public static function createFromArray(mixed $data): self
    {
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Credential data should be an array');
        }
        $instance = new self();
        foreach(self::ATTRS as $attr) {
            $instance->{$attr} = $data[$attr] ?? throw new \InvalidArgumentException('Expected key ' . $attr);
        }

        return $instance;
    }

    public static function create(string $name, $value): self
    {
        return (new self)
            ->setName($name)
            ->setValue($value);
    }

    public function toArray(): array
    {
        $result = [];
        foreach (self::ATTRS as $attr) {
            $result[$attr] = $this->{$attr};
        }

        return $result;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): Credential
    {
        $this->name = $name;

        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): Credential
    {
        $this->value = $value;

        return $this;
    }
}