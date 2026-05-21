<?php

declare(strict_types=1);

namespace Wodzy\MailSentry;

use Countable;
use JsonException;
use Wodzy\MailSentry\Exception\BlacklistFileNotFoundException;
use Wodzy\MailSentry\Exception\InvalidBlacklistFormatException;
use Wodzy\MailSentry\Exception\InvalidEmailException;

/**
 * Checks whether an email address belongs to a blacklisted domain.
 *
 * Matching is performed against the full email domain AND each of its parent
 * domains, so a blacklist entry of "yopmail.com" also blocks
 * "foo.yopmail.com". For that reason public suffixes (e.g. "co.uk",
 * "github.io") must not appear in the blacklist: doing so would block every
 * domain registered under them. MailSentry does not resolve the
 * Public Suffix List.
 *
 * Domains are normalized to lowercase, trimmed of surrounding whitespace,
 * and (when ext-intl is available) converted to their ASCII / Punycode form
 * via UTS-46 so that "münchen.de" and "xn--mnchen-3ya.de" match.
 */
final class Validator implements ValidatorInterface, Countable
{
    /** @var array<string, true> Lookup map for O(1) membership checks. */
    private readonly array $blacklist;

    /** @var list<string>|null Memoized parse of the bundled default list. */
    private static ?array $defaultDomains = null;

    /**
     * @param iterable<mixed> $domains Full domain names (e.g. "yopmail.com").
     *                                 Non-string entries and empty entries
     *                                 are silently skipped.
     */
    public function __construct(iterable $domains)
    {
        $map = [];
        foreach ($domains as $domain) {
            if (! is_string($domain)) {
                continue;
            }
            $normalized = $this->normalizeDomain($domain);
            if ($normalized === '') {
                continue;
            }
            $map[$normalized] = true;
        }
        $this->blacklist = $map;
    }

    /**
     * Build a validator from a JSON file with the shape `{"domains": ["a.com", ...]}`.
     */
    public static function fromFile(string $path): self
    {
        return new self(self::loadDomainsFromFile($path));
    }

    /**
     * Build a validator from the bundled default disposable-email blacklist.
     *
     * The on-disk file is parsed only once per process; subsequent calls
     * reuse the cached list to build a fresh (immutable) Validator instance.
     */
    public static function withDefaultBlacklist(): self
    {
        if (self::$defaultDomains === null) {
            self::$defaultDomains = self::loadDomainsFromFile(__DIR__ . '/data/disposable_domains.json');
        }

        return new self(self::$defaultDomains);
    }

    public function isBlacklisted(string $email): bool
    {
        $domain = $this->extractDomain($email);

        foreach ($this->candidateDomains($domain) as $candidate) {
            if (isset($this->blacklist[$candidate])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the normalized blacklist as a list of strings, in insertion
     * order, deduplicated and lowercased (and ASCII-encoded when ext-intl is
     * available).
     *
     * @return list<string>
     */
    public function getBlacklistedDomains(): array
    {
        return array_keys($this->blacklist);
    }

    public function count(): int
    {
        return count($this->blacklist);
    }

    /**
     * @return list<string>
     */
    private static function loadDomainsFromFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw BlacklistFileNotFoundException::forPath($path);
        }

        $contents = (string) file_get_contents($path);

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw InvalidBlacklistFormatException::malformedJson($path, $e);
        }

        if (! is_array($decoded) || ! isset($decoded['domains']) || ! is_array($decoded['domains'])) {
            throw InvalidBlacklistFormatException::missingDomainsKey($path);
        }

        /** @var list<string> $domains */
        $domains = array_values(array_filter(
            $decoded['domains'],
            static fn (mixed $value): bool => is_string($value),
        ));

        return $domains;
    }

    private function extractDomain(string $email): string
    {
        // Split on the rightmost "@" so that quoted local-parts containing
        // an "@" (e.g. `"a@b"@host.com`) are handled correctly.
        $atPosition = strrpos($email, '@');
        if ($atPosition === false || $atPosition === 0 || $atPosition === strlen($email) - 1) {
            throw InvalidEmailException::forEmail($email);
        }

        $localPart = substr($email, 0, $atPosition);
        $domainPart = substr($email, $atPosition + 1);

        // IP-literal addresses ("user@[1.2.3.4]") do not have a meaningful
        // DNS domain to match against, and accepting them would let callers
        // bypass any policy built on top of this library.
        if ($domainPart[0] === '[') {
            throw InvalidEmailException::forEmail($email);
        }

        // Normalize the domain BEFORE validation so that IDN domains
        // (written in Unicode) are converted to their ASCII / Punycode form
        // and then accepted by filter_var.
        $normalizedDomain = $this->normalizeDomain($domainPart);
        if ($normalizedDomain === '') {
            throw InvalidEmailException::forEmail($email);
        }

        // Validate the reconstructed (ASCII-domain) email with
        // FILTER_FLAG_EMAIL_UNICODE, which relaxes the local-part check to
        // allow non-ASCII characters (PHP 7.1+).
        $reconstructed = $localPart . '@' . $normalizedDomain;
        if (filter_var($reconstructed, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) === false) {
            throw InvalidEmailException::forEmail($email);
        }

        return $normalizedDomain;
    }

    /**
     * Yields the domain and each of its parent domains, longest first.
     * For "a.b.example.com": ["a.b.example.com", "b.example.com", "example.com"].
     *
     * @return iterable<string>
     */
    private function candidateDomains(string $domain): iterable
    {
        $parts = explode('.', $domain);
        $count = count($parts);
        // Stop before a single label to avoid matching a bare TLD like "com".
        for ($i = 0; $i <= $count - 2; $i++) {
            yield implode('.', array_slice($parts, $i));
        }
    }

    private function normalizeDomain(string $domain): string
    {
        $trimmed = strtolower(trim($domain));
        if ($trimmed === '') {
            return '';
        }

        // If the string contains any non-ASCII byte and ext-intl is loaded,
        // convert to UTS-46 ASCII form so blacklist entries written in
        // Unicode match emails written in punycode (and vice versa).
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $trimmed) === 1) {
            $ascii = idn_to_ascii($trimmed, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                return $ascii;
            }
        }

        return $trimmed;
    }
}
