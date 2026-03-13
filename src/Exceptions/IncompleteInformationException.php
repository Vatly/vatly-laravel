<?php

declare(strict_types=1);

namespace Vatly\Laravel\Exceptions;

use Vatly\Fluent\Exceptions\VatlyException;

class IncompleteInformationException extends VatlyException
{
    /**
     * @return static
     */
    public static function noCheckoutItems(): self
    {
        return new static('No checkout items provided. At least one item should be set when creating a checkout.');
    }
}
