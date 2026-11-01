<?php

declare(strict_types=1);

namespace Wodzy\DisposableEmail\Exception;

use RuntimeException;

final class DomainListFileNotFoundException extends RuntimeException implements DisposableEmailException
{
    public static function forPath(string $path): self
    {
        return new self(sprintf('Domain list file "%s" was not found or is not readable.', $path));
    }
}
