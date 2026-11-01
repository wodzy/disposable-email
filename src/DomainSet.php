<?php

declare(strict_types=1);

namespace Wodzy\DisposableEmail;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonException;
use Traversable;
use Wodzy\DisposableEmail\Exception\DomainListFileNotFoundException;
use Wodzy\DisposableEmail\Exception\InvalidDomainListFormatException;
use Wodzy\DisposableEmail\Exception\InvalidEmailException;

/**
 * An immutable set of domain names that knows how to match an email address.
 *
 * A domain also matches its subdomains: a set containing "yopmail.com" matches
 * "foo.yopmail.com". For that reason public suffixes (e.g. "co.uk",
 * "github.io") must not be added: doing so would match every domain
 * registered under them. The Public Suffix List is not resolved.
 *
 * Domains are normalized to lowercase, trimmed of surrounding whitespace,
 * and (when ext-intl is available) converted to their ASCII / Punycode form
 * via UTS-46 so that "münchen.de" and "xn--mnchen-3ya.de" are the same entry.
 *
 * @implements IteratorAggregate<int, string>
 */
final class DomainSet implements Countable, IteratorAggregate
{
    private const BUNDLED_LIST = __DIR__ . '/data/disposable_domains.json';

    /**
     * Lookup map for O(1) membership checks.
     *
     * Not `readonly` so that `fromMap()` can build sets without re-normalizing;
     * the class stays immutable because nothing outside it can write this property.
     *
     * @var array<string, true>
     */
    private array $domains;

    private static ?self $bundled = null;

    /**
     * @param iterable<mixed> $domains Domain names (e.g. "yopmail.com").
     *                                 Non-string and empty entries are skipped.
     */
    public function __construct(iterable $domains)
    {
        $map = [];
        foreach ($domains as $domain) {
            if (! is_string($domain)) {
                continue;
            }
            $normalized = self::normalize($domain);
            if ($normalized !== '') {
                $map[$normalized] = true;
            }
        }
        $this->domains = $map;
    }

    /**
     * Rebuilds a set from the output of `toArray()` without normalizing again.
     *
     * Only pass data produced by `toArray()` (for example read back from a
     * cache): entries are trusted as-is.
     *
     * @param list<string> $normalizedDomains
     */
    public static function fromNormalized(array $normalizedDomains): self
    {
        return self::fromMap(array_fill_keys($normalizedDomains, true));
    }

    /**
     * Loads a JSON file with the shape `{"domains": ["a.com", ...]}`.
     *
     * The caller is responsible for the trustworthiness of `$path`: it is read
     * as-is. Do not pass an unvalidated, user-controlled path.
     *
     * @throws DomainListFileNotFoundException  when the file does not exist or is not readable.
     * @throws InvalidDomainListFormatException when the file is not valid JSON or lacks a "domains" array.
     */
    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw DomainListFileNotFoundException::forPath($path);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw InvalidDomainListFormatException::malformedJson($path, $e);
        }

        if (! is_array($decoded) || ! isset($decoded['domains']) || ! is_array($decoded['domains'])) {
            throw InvalidDomainListFormatException::missingDomainsKey($path);
        }

        return new self($decoded['domains']);
    }

    /**
     * The curated disposable-email list shipped with this library.
     *
     * The file is parsed once per process; the immutable set is then shared.
     */
    public static function bundled(): self
    {
        return self::$bundled ??= self::fromFile(self::BUNDLED_LIST);
    }

    /**
     * Path of the bundled list, e.g. to watch its modification time.
     */
    public static function bundledListPath(): string
    {
        return self::BUNDLED_LIST;
    }

    /**
     * Normalizes a list entry: trims, lowercases and (with ext-intl) converts it to its ASCII form.
     *
     * Formats commonly found in third-party lists are accepted, so that they
     * match instead of being silently ignored: "*.yopmail.com", ".yopmail.com",
     * "@yopmail.com" and the fully-qualified "yopmail.com." all become "yopmail.com".
     */
    public static function normalize(string $domain): string
    {
        $entry = strtolower(trim($domain));
        $entry = (string) preg_replace('/^(?:\*\.|\.|@)+/', '', $entry);

        return self::toAscii(rtrim($entry, '.'));
    }

    /**
     * Returns the normalized domain of a syntactically valid email address.
     *
     * @throws InvalidEmailException when the input is not a valid email, or uses an IP literal.
     */
    public static function domainOf(string $email): string
    {
        // Split on the rightmost "@" so that quoted local-parts containing
        // an "@" (e.g. `"a@b"@host.com`) are handled correctly.
        $atPosition = strrpos($email, '@');
        if ($atPosition === false || $atPosition === 0 || $atPosition === strlen($email) - 1) {
            throw InvalidEmailException::forEmail($email);
        }

        $localPart = substr($email, 0, $atPosition);
        $domainPart = substr($email, $atPosition + 1);

        // IP-literal addresses ("user@[1.2.3.4]") have no DNS domain to match,
        // and accepting them would let callers bypass any domain-based policy.
        if ($domainPart[0] === '[') {
            throw InvalidEmailException::forEmail($email);
        }

        // Convert IDN domains (written in Unicode) to Punycode BEFORE validating,
        // so that filter_var accepts them. Unlike list entries, the domain is
        // not trimmed: "a@yopmail.com " or a trailing NUL byte is invalid.
        $domain = self::toAscii(strtolower($domainPart));

        // FILTER_FLAG_EMAIL_UNICODE allows non-ASCII characters in the local part.
        if (filter_var($localPart . '@' . $domain, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) === false) {
            throw InvalidEmailException::forEmail($email);
        }

        return $domain;
    }

    /**
     * True when the domain, or one of its parent domains, is in the set.
     *
     * A bare TLD ("com") is never looked up.
     */
    public function contains(string $domain): bool
    {
        $parts = explode('.', self::normalize($domain));
        $count = count($parts);
        for ($i = 0; $i <= $count - 2; $i++) {
            if (isset($this->domains[implode('.', array_slice($parts, $i))])) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the email's domain, or one of its parent domains, is in the set.
     *
     * @throws InvalidEmailException when the input is not a valid email.
     */
    public function matches(string $email): bool
    {
        return $this->contains(self::domainOf($email));
    }

    /**
     * Returns a new set containing the domains of this set and of the others.
     */
    public function merge(self ...$others): self
    {
        $map = $this->domains;
        foreach ($others as $other) {
            $map += $other->domains;
        }

        return self::fromMap($map);
    }

    /**
     * The normalized domains, in insertion order.
     *
     * @return list<string>
     */
    public function toArray(): array
    {
        return array_keys($this->domains);
    }

    public function count(): int
    {
        return count($this->domains);
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->toArray());
    }

    /**
     * Converts a lowercase domain to its ASCII / Punycode form (UTS-46) when ext-intl is available.
     */
    private static function toAscii(string $domain): string
    {
        // Only non-ASCII input needs IDN conversion.
        if ($domain === '' || ! function_exists('idn_to_ascii') || preg_match('/[^\x20-\x7e]/', $domain) !== 1) {
            return $domain;
        }

        $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return is_string($ascii) && $ascii !== '' ? $ascii : $domain;
    }

    /**
     * @param array<string, true> $map Already normalized lookup map.
     */
    private static function fromMap(array $map): self
    {
        $set = new self([]);
        $set->domains = $map;

        return $set;
    }
}
