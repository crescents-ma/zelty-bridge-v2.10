<?php

declare(strict_types=1);

namespace App\Util;


class MoneyHelper
{
    /**
     * @param int|float|string|null $amount
     * @param int $decimalCount
     * @return int
     */
    public static function toCents(int|float|string|null $amount, int $decimalCount = 2): int
    {
        return (int)bcmul((string)$amount, (string)pow(10, $decimalCount), 0);
    }

    /**
     * @param int|float|string|null $amount
     * @param int $decimalCount
     * @param int|null $scale
     * @return string
     */
    public static function fromCents(int|float|string|null $amount, int $decimalCount = 2, ?int $scale = null): string
    {
        $scale ??= $decimalCount;

        return bcdiv((string)$amount, (string)pow(10, $decimalCount), $scale);
    }

}
