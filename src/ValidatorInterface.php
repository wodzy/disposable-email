<?php

declare(strict_types=1);

namespace Wodzy\MailSentry;

use Wodzy\MailSentry\Exception\InvalidEmailException;

interface ValidatorInterface
{
    /**
     * Returns true when the email's domain (or one of its parent domains)
     * appears in the blacklist.
     *
     * @throws InvalidEmailException when the input is not a syntactically valid email.
     */
    public function isBlacklisted(string $email): bool;
}
