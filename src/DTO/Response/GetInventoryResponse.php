<?php

namespace App\DTO\Response;

use Symfony\Component\Validator\Constraints\Valid;

class GetInventoryResponse extends AbstractResponse
{
    /**
     * @var InventoryItem[]
     */
    #[Valid]
    private array $inventoryItems = [];

    public function getInventoryItems(): array
    {
        return $this->inventoryItems;
    }

    public function setInventoryItems(array $inventoryItems): GetInventoryResponse
    {
        $this->inventoryItems = $inventoryItems;

        return $this;
    }
}