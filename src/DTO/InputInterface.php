<?php

namespace App\DTO;

interface InputInterface
{
    /**
     * @return array<int, array{name: string, value: string}>
     */
    public function getCredentials(): array;

}