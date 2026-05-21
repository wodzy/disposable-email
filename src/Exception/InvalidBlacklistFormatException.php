<?php

declare(strict_types=1);

namespace Wodzy\MailSentry\Exception;

use Throwable;
use UnexpectedValueException;

final class InvalidBlacklistFormatException extends UnexpectedValueException implements MailSentryException
{
    public static function malformedJson(string $path, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Blacklist file "%s" does not contain valid JSON.', $path),
            0,
            $previous
        );
    }

    public static function missingDomainsKey(string $path): self
    {
        return new self(
            sprintf('Blacklist file "%s" must contain a "domains" array at the root.', $path)
        );
    }
}
