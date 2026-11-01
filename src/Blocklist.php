<?php

declare(strict_types=1);

namespace Wodzy\DisposableEmail;

use Countable;

/**
 * Decides whether an email address must be refused.
 *
 * An address is blocked when its domain (or a parent domain) is in the blocked
 * set AND neither its domain nor a parent domain is in the never-blocked set.
 * The never-blocked set always wins: with "yopmail.com" blocked and
 * "partner.yopmail.com" never blocked, "a@partner.yopmail.com" is accepted.
 */
final class Blocklist implements BlocklistInterface, Countable
{
    private readonly DomainSet $neverBlocked;

    public function __construct(
        private readonly DomainSet $blocked,
        ?DomainSet $neverBlocked = null,
    ) {
        $this->neverBlocked = $neverBlocked ?? new DomainSet([]);
    }

    /**
     * Blocklist built from the curated disposable-email list shipped with this library.
     */
    public static function bundled(): self
    {
        return new self(DomainSet::bundled());
    }

    /**
     * Blocklist built from a list of domains, e.g. `Blocklist::fromDomains(['spam.test'])`.
     *
     * @param iterable<mixed> $domains
     */
    public static function fromDomains(iterable $domains): self
    {
        return new self(new DomainSet($domains));
    }

    public function blocks(string $email): bool
    {
        $domain = DomainSet::domainOf($email);

        return $this->blocked->contains($domain) && ! $this->neverBlocked->contains($domain);
    }

    public function blockedDomains(): DomainSet
    {
        return $this->blocked;
    }

    public function neverBlockedDomains(): DomainSet
    {
        return $this->neverBlocked;
    }

    /**
     * Number of blocked domains.
     */
    public function count(): int
    {
        return count($this->blocked);
    }
}
