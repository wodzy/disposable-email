<?php

declare(strict_types=1);

namespace Wodzy\MailSentry\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Wodzy\MailSentry\Exception\BlacklistFileNotFoundException;
use Wodzy\MailSentry\Exception\InvalidBlacklistFormatException;
use Wodzy\MailSentry\Exception\InvalidEmailException;
use Wodzy\MailSentry\Exception\MailSentryException;
use Wodzy\MailSentry\Validator;
use Wodzy\MailSentry\ValidatorInterface;

#[CoversClass(Validator::class)]
#[CoversClass(InvalidEmailException::class)]
#[CoversClass(BlacklistFileNotFoundException::class)]
#[CoversClass(InvalidBlacklistFormatException::class)]
final class ValidatorTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures';

    public function testValidatorImplementsItsInterface(): void
    {
        self::assertInstanceOf(ValidatorInterface::class, new Validator([]));
    }

    public function testIsBlacklistedReturnsTrueForListedDomain(): void
    {
        $validator = new Validator(['yopmail.com']);

        self::assertTrue($validator->isBlacklisted('john.doe@yopmail.com'));
    }

    public function testIsBlacklistedReturnsFalseForUnlistedDomain(): void
    {
        $validator = new Validator(['yopmail.com']);

        self::assertFalse($validator->isBlacklisted('jane.doe@gmail.com'));
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $validator = new Validator(['YopMail.COM']);

        self::assertTrue($validator->isBlacklisted('john@YOPMAIL.com'));
        self::assertTrue($validator->isBlacklisted('john@yopmail.com'));
    }

    public function testCaseInsensitiveMatchingAlsoWorksWhenBlacklistComesFromFile(): void
    {
        $validator = Validator::fromFile(self::FIXTURES . '/valid_blacklist.json');

        // The fixture stores "Tempmail.com" with a capital T; the email below
        // uses ALL CAPS, so both sides need to be normalized.
        self::assertTrue($validator->isBlacklisted('john@TEMPMAIL.COM'));
        // And the reverse: lowercase fixture entry "yopmail.com" vs. uppercase email.
        self::assertTrue($validator->isBlacklisted('john@YOPMAIL.COM'));
    }

    public function testSubdomainsAreBlacklistedWhenParentDomainIsListed(): void
    {
        $validator = new Validator(['mailinator.com']);

        self::assertTrue($validator->isBlacklisted('spammer@foo.mailinator.com'));
        self::assertTrue($validator->isBlacklisted('spammer@a.b.c.mailinator.com'));
    }

    public function testParentDomainsAreNotBlacklistedWhenOnlySubdomainIsListed(): void
    {
        $validator = new Validator(['foo.mailinator.com']);

        self::assertFalse($validator->isBlacklisted('spammer@mailinator.com'));
        self::assertTrue($validator->isBlacklisted('spammer@foo.mailinator.com'));
    }

    public function testTopLevelDomainAloneIsNeverMatched(): void
    {
        // Even though parent-domain traversal would reach "com", the lookup
        // must never match a bare TLD when only "evil.com" is on the list.
        $validator = new Validator(['evil.com']);

        self::assertFalse($validator->isBlacklisted('john@gmail.com'));
        self::assertFalse($validator->isBlacklisted('john@something.org'));
    }

    /**
     * Documents (rather than hides) the public-suffix trap: if the user
     * puts an actual public suffix like "co.uk" on the blacklist, every
     * domain registered under it will be considered blacklisted. MailSentry
     * does NOT resolve the Public Suffix List; consumers must curate their
     * own list responsibly. The README's "Caveats" section makes this
     * explicit.
     */
    public function testPublicSuffixOnTheBlacklistBlocksEverythingUnderIt(): void
    {
        $validator = new Validator(['co.uk']);

        self::assertTrue($validator->isBlacklisted('user@anything.co.uk'));
        self::assertTrue($validator->isBlacklisted('user@example.co.uk'));
    }

    public function testExactDomainMatchForMultiLabelEntries(): void
    {
        // Exact-match behavior for multi-label entries like "spamthis.co.uk".
        $validator = new Validator(['spamthis.co.uk']);

        self::assertTrue($validator->isBlacklisted('user@spamthis.co.uk'));
        self::assertTrue($validator->isBlacklisted('user@sub.spamthis.co.uk'));
        self::assertFalse($validator->isBlacklisted('user@example.co.uk'));
    }

    public function testWhitespaceInBlacklistEntriesIsTrimmed(): void
    {
        $validator = new Validator(['  yopmail.com  ']);

        self::assertTrue($validator->isBlacklisted('john@yopmail.com'));
    }

    public function testEmptyEntriesInBlacklistAreIgnored(): void
    {
        $validator = new Validator(['', '   ', 'yopmail.com']);

        self::assertSame(1, $validator->count());
        self::assertTrue($validator->isBlacklisted('john@yopmail.com'));
    }

    public function testNonStringEntriesInBlacklistAreSilentlySkipped(): void
    {
        /** @var iterable<mixed> $domains */
        $domains = ['yopmail.com', 123, null, ['nested'], 'mailinator.com'];

        $validator = new Validator($domains);

        self::assertSame(2, $validator->count());
        self::assertTrue($validator->isBlacklisted('john@yopmail.com'));
        self::assertTrue($validator->isBlacklisted('john@mailinator.com'));
    }

    public function testQuotedLocalPartContainingAtSignIsHandledCorrectly(): void
    {
        // For "a@b"@yopmail.com, the *rightmost* @ separates the local and
        // domain parts. extractDomain must return "yopmail.com", not "b".
        $validator = new Validator(['yopmail.com']);

        self::assertTrue($validator->isBlacklisted('"a@b"@yopmail.com'));
    }

    public function testCountableInterfaceWorks(): void
    {
        $validator = new Validator(['a.com', 'b.com', 'c.com']);

        self::assertCount(3, $validator);
    }

    #[DataProvider('invalidEmailProvider')]
    public function testInvalidEmailThrows(string $email): void
    {
        $validator = new Validator(['yopmail.com']);

        $this->expectException(InvalidEmailException::class);
        $this->expectExceptionMessageMatches('/is not a valid email address/');

        $validator->isBlacklisted($email);
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
        yield 'IP literal (rejected on purpose)' => ['user@[192.168.1.1]'];
        yield 'IPv6 literal (rejected on purpose)' => ['user@[IPv6:2001:db8::1]'];
    }

    public function testInvalidEmailExceptionImplementsLibraryInterface(): void
    {
        $validator = new Validator([]);

        try {
            $validator->isBlacklisted('not-an-email');
            self::fail('Expected exception was not thrown.');
        } catch (MailSentryException $e) {
            self::assertInstanceOf(InvalidEmailException::class, $e);
        }
    }

    public function testFromFileLoadsDomains(): void
    {
        $validator = Validator::fromFile(self::FIXTURES . '/valid_blacklist.json');

        self::assertTrue($validator->isBlacklisted('john@yopmail.com'));
        self::assertTrue($validator->isBlacklisted('john@mailinator.com'));
        self::assertTrue($validator->isBlacklisted('john@tempmail.com'), 'should be case-insensitive');
        self::assertTrue($validator->isBlacklisted('john@trashmail.com'), 'whitespace should be trimmed');
        self::assertFalse($validator->isBlacklisted('john@gmail.com'));
    }

    public function testFromFileThrowsWhenFileMissing(): void
    {
        $this->expectException(BlacklistFileNotFoundException::class);

        Validator::fromFile(self::FIXTURES . '/does-not-exist.json');
    }

    public function testFromFileThrowsWhenJsonIsMalformed(): void
    {
        $this->expectException(InvalidBlacklistFormatException::class);
        $this->expectExceptionMessageMatches('/valid JSON/');

        Validator::fromFile(self::FIXTURES . '/malformed.json.txt');
    }

    public function testFromFileThrowsWhenDomainsKeyMissing(): void
    {
        $this->expectException(InvalidBlacklistFormatException::class);
        $this->expectExceptionMessageMatches('/"domains" array/');

        Validator::fromFile(self::FIXTURES . '/missing_key.json');
    }

    public function testWithDefaultBlacklistIsUsable(): void
    {
        $validator = Validator::withDefaultBlacklist();

        self::assertGreaterThan(50, $validator->count(), 'bundled list should contain a meaningful number of domains');
        self::assertTrue($validator->isBlacklisted('john@yopmail.com'));
        self::assertTrue($validator->isBlacklisted('john@mailinator.com'));
        self::assertFalse($validator->isBlacklisted('john@gmail.com'));
    }

    public function testWithDefaultBlacklistReturnsFreshInstanceButSharesData(): void
    {
        $a = Validator::withDefaultBlacklist();
        $b = Validator::withDefaultBlacklist();

        self::assertNotSame($a, $b, 'each call returns a fresh instance');
        self::assertSame($a->count(), $b->count(), 'both share the memoized data');
    }

    public function testGetBlacklistedDomainsReturnsNormalizedList(): void
    {
        $validator = new Validator(['YOPMAIL.com', '  mailinator.com  ']);

        $domains = $validator->getBlacklistedDomains();

        sort($domains);
        self::assertSame(['mailinator.com', 'yopmail.com'], $domains);
    }

    public function testAcceptsGenerators(): void
    {
        $generator = static function (): \Generator {
            yield 'yopmail.com';
            yield 'mailinator.com';
        };

        $validator = new Validator($generator());

        self::assertSame(2, $validator->count());
        self::assertTrue($validator->isBlacklisted('john@mailinator.com'));
    }

    public function testVeryLongEmailIsHandledWithoutCrashingOrThrowing(): void
    {
        // RFC 5321 allows a local-part up to 64 characters. A 64-char
        // local-part on a blacklisted domain must still be matched.
        $localPart = str_repeat('a', 64);
        $email = $localPart . '@yopmail.com';

        $validator = new Validator(['yopmail.com']);

        self::assertTrue($validator->isBlacklisted($email));
    }

    #[RequiresPhpExtension('intl')]
    public function testIdnDomainsMatchTheirPunycodeForm(): void
    {
        // Blacklist written in Unicode, email written in Unicode.
        $validator = new Validator(['münchen.de']);

        self::assertTrue($validator->isBlacklisted('a@münchen.de'));
        // And the punycode form of the same domain.
        self::assertTrue($validator->isBlacklisted('a@xn--mnchen-3ya.de'));
    }

    #[RequiresPhpExtension('intl')]
    public function testPunycodeBlacklistMatchesUnicodeEmail(): void
    {
        // Blacklist stored in punycode, email written in Unicode.
        $validator = new Validator(['xn--mnchen-3ya.de']);

        self::assertTrue($validator->isBlacklisted('a@münchen.de'));
    }
}
