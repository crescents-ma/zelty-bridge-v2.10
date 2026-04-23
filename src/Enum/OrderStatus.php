<?php

namespace App\Enum;

/**
 * Zelty order statuses (from OrderStatus schema in OpenAPI spec).
 */
enum OrderStatus: string
{
    case OPENED = 'opened';
    case CANCELLED = 'cancelled';
    case ENDED = 'ended';
}
