<?php

declare(strict_types=1);

namespace Wodzy\DisposableEmail;

use Wodzy\DisposableEmail\Exception\InvalidEmailException;

interface BlocklistInterface
{
    /**
     * Returns true when the email must be refused.
     *
     * @throws InvalidEmailException when the input is not a syntactically valid email.
     */
    public function blocks(string $email): bool;
}
