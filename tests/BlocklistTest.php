<?php

declare(strict_types=1);

namespace Wodzy\DisposableEmail\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Wodzy\DisposableEmail\Blocklist;
use Wodzy\DisposableEmail\BlocklistInterface;
use Wodzy\DisposableEmail\DomainSet;
use Wodzy\DisposableEmail\Exception\InvalidEmailException;

#[CoversClass(Blocklist::class)]
final class BlocklistTest extends TestCase
{
    public function testImplementsItsInterface(): void
    {
        self::assertInstanceOf(BlocklistInterface::class, Blocklist::fromDomains([]));
    }

    public function testBlocksListedDomainsAndTheirSubdomains(): void
    {
        $blocklist = Blocklist::fromDomains(['yopmail.com']);

        self::assertTrue($blocklist->blocks('a@yopmail.com'));
        self::assertTrue($blocklist->blocks('a@sub.yopmail.com'));
        self::assertFalse($blocklist->blocks('a@gmail.com'));
    }

    public function testNeverBlockedDomainWins(): void
    {
        $blocklist = new Blocklist(
            new DomainSet(['yopmail.com', 'mail.company.com']),
            new DomainSet(['partner.yopmail.com', 'company.com']),
        );

        self::assertTrue($blocklist->blocks('a@yopmail.com'));
        self::assertFalse($blocklist->blocks('a@partner.yopmail.com'), 'subdomain of a blocked domain');
        self::assertFalse($blocklist->blocks('a@eu.partner.yopmail.com'), 'and its own subdomains');
        self::assertFalse($blocklist->blocks('a@mail.company.com'), 'blocked subdomain of a never-blocked domain');
    }

    public function testInvalidEmailThrows(): void
    {
        $this->expectException(InvalidEmailException::class);

        Blocklist::fromDomains(['yopmail.com'])->blocks('not-an-email');
    }

    public function testBundledBlocklist(): void
    {
        $blocklist = Blocklist::bundled();

        self::assertTrue($blocklist->blocks('a@yopmail.com'));
        self::assertFalse($blocklist->blocks('a@gmail.com'));
        self::assertSame(DomainSet::bundled(), $blocklist->blockedDomains());
    }

    public function testExposesItsSetsAndCount(): void
    {
        $blocked = new DomainSet(['a.com', 'b.com']);
        $neverBlocked = new DomainSet(['ok.a.com']);
        $blocklist = new Blocklist($blocked, $neverBlocked);

        self::assertSame($blocked, $blocklist->blockedDomains());
        self::assertSame($neverBlocked, $blocklist->neverBlockedDomains());
        self::assertCount(2, $blocklist);
        self::assertCount(0, Blocklist::fromDomains([])->neverBlockedDomains());
    }
}
