<?php

declare(strict_types=1);

namespace Vatly\Laravel\Exceptions;

use ReflectionClass;
use RuntimeException;
use Vatly\Fluent\Exceptions\VatlyException;

final class NoVatlyCustomer extends RuntimeException implements VatlyException
{
    public static function notYetCreated(object $owner): self
    {
        $short = (new ReflectionClass($owner))->getShortName();

        return new self("{$short} is not a Vatly customer yet. Call createAsVatlyCustomer() first.");
    }
}
