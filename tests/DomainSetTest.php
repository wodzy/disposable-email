<?php

declare(strict_types=1);

namespace Wodzy\DisposableEmail\Tests;

use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Wodzy\DisposableEmail\DomainSet;
use Wodzy\DisposableEmail\Exception\DisposableEmailException;
use Wodzy\DisposableEmail\Exception\DomainListFileNotFoundException;
use Wodzy\DisposableEmail\Exception\InvalidDomainListFormatException;
use Wodzy\DisposableEmail\Exception\InvalidEmailException;

#[CoversClass(DomainSet::class)]
#[CoversClass(InvalidEmailException::class)]
#[CoversClass(DomainListFileNotFoundException::class)]
#[CoversClass(InvalidDomainListFormatException::class)]
final class DomainSetTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures';

    public function testMatchesListedDomain(): void
    {
        $set = new DomainSet(['yopmail.com']);

        self::assertTrue($set->matches('john.doe@yopmail.com'));
        self::assertFalse($set->matches('jane.doe@gmail.com'));
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $set = new DomainSet(['YopMail.COM']);

        self::assertTrue($set->matches('john@YOPMAIL.com'));
        self::assertTrue($set->matches('john@yopmail.com'));
    }

    public function testSubdomainsMatchWhenParentIsListed(): void
    {
        $set = new DomainSet(['mailinator.com']);

        self::assertTrue($set->matches('spammer@foo.mailinator.com'));
        self::assertTrue($set->matches('spammer@a.b.c.mailinator.com'));
    }

    public function testParentDoesNotMatchWhenOnlySubdomainIsListed(): void
    {
        $set = new DomainSet(['foo.mailinator.com']);

        self::assertFalse($set->matches('spammer@mailinator.com'));
        self::assertTrue($set->matches('spammer@foo.mailinator.com'));
    }

    public function testTopLevelDomainAloneIsNeverMatched(): void
    {
        $set = new DomainSet(['evil.com']);

        self::assertFalse($set->matches('john@gmail.com'));
        self::assertFalse($set->matches('john@something.org'));
    }

    /**
     * Documents (rather than hides) the public-suffix trap: listing "co.uk"
     * matches every domain registered under it. The README warns about it.
     */
    public function testPublicSuffixInTheSetMatchesEverythingUnderIt(): void
    {
        $set = new DomainSet(['co.uk']);

        self::assertTrue($set->matches('user@anything.co.uk'));
        self::assertTrue($set->matches('user@example.co.uk'));
    }

    public function testMultiLabelEntriesMatchExactly(): void
    {
        $set = new DomainSet(['spamthis.co.uk']);

        self::assertTrue($set->matches('user@spamthis.co.uk'));
        self::assertTrue($set->matches('user@sub.spamthis.co.uk'));
        self::assertFalse($set->matches('user@example.co.uk'));
    }

    public function testContainsWorksOnDomains(): void
    {
        $set = new DomainSet(['yopmail.com']);

        self::assertTrue($set->contains('yopmail.com'));
        self::assertTrue($set->contains('  Sub.YOPMAIL.com '));
        self::assertFalse($set->contains('notyopmail.com'));
        self::assertFalse($set->contains(''));
    }

    public function testEntriesAreTrimmedAndEmptyOrNonStringEntriesSkipped(): void
    {
        /** @var iterable<mixed> $domains */
        $domains = ['  yopmail.com  ', '', '   ', 123, null, ['nested'], 'mailinator.com'];

        $set = new DomainSet($domains);

        self::assertSame(['yopmail.com', 'mailinator.com'], $set->toArray());
        self::assertCount(2, $set);
    }

    public function testDuplicatesAreRemoved(): void
    {
        self::assertSame(['a.com'], (new DomainSet(['a.com', 'A.com', ' a.com']))->toArray());
    }

    /**
     * Third-party lists often use these formats: they must match, not be silently ignored.
     */
    #[DataProvider('commonEntryFormatProvider')]
    public function testCommonListEntryFormatsAreNormalized(string $entry): void
    {
        $set = new DomainSet([$entry]);

        self::assertSame(['yopmail.com'], $set->toArray());
        self::assertTrue($set->matches('a@yopmail.com'));
        self::assertTrue($set->matches('a@sub.yopmail.com'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function commonEntryFormatProvider(): iterable
    {
        yield 'wildcard' => ['*.yopmail.com'];
        yield 'leading dot' => ['.yopmail.com'];
        yield 'leading at sign' => ['@yopmail.com'];
        yield 'fully-qualified trailing dot' => ['yopmail.com.'];
        yield 'everything at once' => ['  *.YOPMAIL.com.  '];
    }

    public function testEntriesReducedToNothingAreSkipped(): void
    {
        self::assertCount(0, new DomainSet(['*.', '.', '@', '...']));
    }

    public function testAcceptsGenerators(): void
    {
        $generator = static function (): Generator {
            yield 'yopmail.com';
            yield 'mailinator.com';
        };

        $set = new DomainSet($generator());

        self::assertCount(2, $set);
        self::assertTrue($set->matches('john@mailinator.com'));
    }

    public function testIsIterable(): void
    {
        self::assertSame(['a.com', 'b.com'], iterator_to_array(new DomainSet(['a.com', 'b.com'])));
    }

    public function testMergeReturnsANewSetAndKeepsTheOriginalsUnchanged(): void
    {
        $a = new DomainSet(['a.com']);
        $b = new DomainSet(['b.com', 'a.com']);

        $merged = $a->merge($b, new DomainSet(['c.com']));

        self::assertSame(['a.com', 'b.com', 'c.com'], $merged->toArray());
        self::assertSame(['a.com'], $a->toArray());
        self::assertSame(['b.com', 'a.com'], $b->toArray());
    }

    public function testFromNormalizedRoundTripsToArray(): void
    {
        $set = new DomainSet(['YOPMAIL.com', 'mailinator.com']);

        $copy = DomainSet::fromNormalized($set->toArray());

        self::assertSame($set->toArray(), $copy->toArray());
        self::assertTrue($copy->matches('a@sub.yopmail.com'));
    }

    public function testQuotedLocalPartContainingAtSignIsHandledCorrectly(): void
    {
        // The rightmost @ separates the local part from the domain.
        self::assertSame('yopmail.com', DomainSet::domainOf('"a@b"@yopmail.com'));
    }

    public function testVeryLongLocalPartIsHandled(): void
    {
        // RFC 5321 allows a local part up to 64 characters.
        $set = new DomainSet(['yopmail.com']);

        self::assertTrue($set->matches(str_repeat('a', 64) . '@yopmail.com'));
    }

    #[DataProvider('invalidEmailProvider')]
    public function testInvalidEmailThrows(string $email): void
    {
        $this->expectException(InvalidEmailException::class);
        $this->expectExceptionMessageMatches('/is not a valid email address/');

        (new DomainSet(['yopmail.com']))->matches($email);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEmailProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'no at sign' => ['johndoe'];
        yield 'no local part' => ['@yopmail.com'];
        yield 'no domain' => ['john@'];
        yield 'spaces' => ['john doe@yopmail.com'];
        yield 'leading space' => [' john@yopmail.com'];
        yield 'trailing space' => ['john@yopmail.com '];
        yield 'trailing NUL byte' => ["john@yopmail.com\0"];
        yield 'blank domain' => ['john@   '];
        yield 'trailing dot' => ['john@yopmail.com.'];
        yield 'IP literal (rejected on purpose)' => ['user@[192.168.1.1]'];
        yield 'IPv6 literal (rejected on purpose)' => ['user@[IPv6:2001:db8::1]'];
    }

    public function testInvalidEmailExceptionImplementsLibraryInterface(): void
    {
        try {
            DomainSet::domainOf('not-an-email');
            self::fail('Expected exception was not thrown.');
        } catch (DisposableEmailException $e) {
            self::assertInstanceOf(InvalidEmailException::class, $e);
        }
    }

    public function testFromFileLoadsAndNormalizesDomains(): void
    {
        $set = DomainSet::fromFile(self::FIXTURES . '/valid_domain_list.json');

        self::assertTrue($set->matches('john@yopmail.com'));
        self::assertTrue($set->matches('john@mailinator.com'));
        self::assertTrue($set->matches('john@TEMPMAIL.COM'), 'the fixture stores "Tempmail.com"');
        self::assertTrue($set->matches('john@trashmail.com'), 'the fixture stores "  trashmail.com  "');
        self::assertFalse($set->matches('john@gmail.com'));
    }

    public function testFromFileThrowsWhenFileMissing(): void
    {
        $this->expectException(DomainListFileNotFoundException::class);

        DomainSet::fromFile(self::FIXTURES . '/does-not-exist.json');
    }

    public function testFromFileThrowsWhenJsonIsMalformed(): void
    {
        $this->expectException(InvalidDomainListFormatException::class);
        $this->expectExceptionMessageMatches('/valid JSON/');

        DomainSet::fromFile(self::FIXTURES . '/malformed.json.txt');
    }

    public function testFromFileThrowsWhenDomainsKeyMissing(): void
    {
        $this->expectException(InvalidDomainListFormatException::class);
        $this->expectExceptionMessageMatches('/"domains" array/');

        DomainSet::fromFile(self::FIXTURES . '/missing_key.json');
    }

    public function testBundledListIsUsableAndShared(): void
    {
        $bundled = DomainSet::bundled();

        self::assertGreaterThan(50, count($bundled));
        self::assertTrue($bundled->matches('john@yopmail.com'));
        self::assertFalse($bundled->matches('john@gmail.com'));
        self::assertSame($bundled, DomainSet::bundled(), 'the immutable set is parsed once and shared');
        self::assertFileExists(DomainSet::bundledListPath());
    }

    public function testBundledListContainsNoMainstreamProvider(): void
    {
        $bundled = DomainSet::bundled();

        foreach (['gmail.com', 'outlook.com', 'hotmail.com', 'yahoo.com', 'icloud.com', 'orange.fr'] as $provider) {
            self::assertFalse($bundled->contains($provider), $provider . ' must never be in the bundled list');
        }
    }

    #[RequiresPhpExtension('intl')]
    public function testIdnDomainsMatchTheirPunycodeForm(): void
    {
        $set = new DomainSet(['münchen.de']);

        self::assertTrue($set->matches('a@münchen.de'));
        self::assertTrue($set->matches('a@xn--mnchen-3ya.de'));
        self::assertSame(['xn--mnchen-3ya.de'], $set->toArray());
    }

    #[RequiresPhpExtension('intl')]
    public function testPunycodeEntryMatchesUnicodeEmail(): void
    {
        self::assertTrue((new DomainSet(['xn--mnchen-3ya.de']))->matches('a@münchen.de'));
    }
}
