<?php

declare(strict_types=1);

namespace Wodzy\MailSentry\Exception;

use RuntimeException;

final class BlacklistFileNotFoundException extends RuntimeException implements MailSentryException
{
    public static function forPath(string $path): self
    {
        return new self(sprintf('Blacklist file "%s" was not found or is not readable.', $path));
    }
}
