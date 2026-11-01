<?php

declare(strict_types=1);

namespace Wodzy\DisposableEmail\Exception;

use InvalidArgumentException;

final class InvalidEmailException extends InvalidArgumentException implements DisposableEmailException
{
    public static function forEmail(string $email): self
    {
        return new self(sprintf('The value "%s" is not a valid email address.', $email));
    }
}
