# DisposableEmail

[![CI](https://github.com/wodzy/disposable-email/actions/workflows/ci.yml/badge.svg)](https://github.com/wodzy/disposable-email/actions/workflows/ci.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/wodzy/disposable-email.svg)](https://packagist.org/packages/wodzy/disposable-email)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue.svg)](composer.json)

DisposableEmail is a lightweight, dependency-free PHP library that checks whether
an email address belongs to a disposable (throwaway) email provider. It ships
with a curated list of well-known providers, lets you plug in your own lists,
and exempt domains that must never be blocked.

## Features

- Zero runtime dependencies (PHP + `ext-filter` + `ext-json` only)
- Optional IDN support via `ext-intl` (`münchen.de` ≡ `xn--mnchen-3ya.de`)
- Strict types, PHP 8.2+, fully type-hinted, immutable `final` classes
- Subdomain-aware matching: `foo.yopmail.com` is caught when `yopmail.com` is listed
- O(1) lookups via an internal hash map
- "Never block" exceptions that always win, even for subdomains of a blocked domain
- `DomainSet`, a neutral domain matcher you can reuse for any policy (e.g. allow-only)
- `BlocklistInterface` for easy mocking and substitution
- Load from file, in-memory list, generator, or use the bundled list
- PSR-4 autoloaded, ready for Composer
- Tested against PHP 8.2, 8.3, 8.4 and 8.5

## Installation

```bash
composer require wodzy/disposable-email
```

For IDN normalization (recommended if your users may register with
internationalized domains):

```bash
# Debian/Ubuntu
sudo apt-get install php-intl

# macOS (Homebrew PHP already includes intl)
```

## Usage

### Block disposable emails with the bundled list

```php
use Wodzy\DisposableEmail\Blocklist;

$blocklist = Blocklist::bundled();

$blocklist->blocks('john.doe@yopmail.com');     // true
$blocklist->blocks('jane.doe@gmail.com');       // false
$blocklist->blocks('user@foo.mailinator.com');  // true (subdomain match)
```

The bundled list is parsed from disk only once per process and shared.

> The bundled list (`src/data/disposable_domains.json`) is a manually curated,
> alphabetically sorted set of well-known disposable-email providers at the
> eTLD+1 granularity. It is intentionally conservative. To add or remove a
> provider, open a pull request editing that file — entries are kept lowercase
> and sorted.

### Use your own domains

```php
use Wodzy\DisposableEmail\Blocklist;
use Wodzy\DisposableEmail\DomainSet;

// In memory (any iterable: arrays, generators...)
$blocklist = Blocklist::fromDomains(['example.com', 'spam.test']);

// From a JSON file shaped like {"domains": ["example.com", "spam.test"]}
$blocklist = new Blocklist(DomainSet::fromFile('/path/to/domains.json'));

// Bundled list plus your own additions
$blocklist = new Blocklist(DomainSet::bundled()->merge(new DomainSet(['my-own-bad-domain.com'])));
```

Entries are trimmed and lowercased. Formats common in third-party lists are
accepted too: `*.example.com`, `.example.com`, `@example.com` and `example.com.`
all mean `example.com`.

### Never block some domains

The second argument lists domains that are never blocked, even when a parent
domain is blocked. It always wins:

```php
use Wodzy\DisposableEmail\Blocklist;
use Wodzy\DisposableEmail\DomainSet;

$blocklist = new Blocklist(
    new DomainSet(['yopmail.com']),
    new DomainSet(['partner.yopmail.com']),
);

$blocklist->blocks('a@yopmail.com');          // true
$blocklist->blocks('a@partner.yopmail.com');  // false
```

### Match domains for any other policy

`DomainSet` is the neutral building block: an immutable set of normalized
domains that matches emails, e.g. to accept only company addresses.

```php
use Wodzy\DisposableEmail\DomainSet;

$company = new DomainSet(['company.com', 'company.fr']);
$company->matches('jane@paris.company.com');  // true
$company->contains('company.fr');             // true
DomainSet::domainOf('Jane@Company.COM');      // "company.com"
```

### Depend on the interface

```php
use Wodzy\DisposableEmail\BlocklistInterface;

final class SignupService
{
    public function __construct(private readonly BlocklistInterface $blocklist) {}
}
```

This makes mocking trivial in your own tests.

## How matching works

For an email `user@a.b.example.com`, DisposableEmail looks up the following candidates,
in order:

1. `a.b.example.com`
2. `b.example.com`
3. `example.com`

A bare TLD (`com`) is never checked, so `evil.com` on the list will not
accidentally block `gmail.com`.

### Caveat: do NOT add public suffixes to a list

DisposableEmail does **not** resolve the [Public Suffix List](https://publicsuffix.org/).
If you add a public suffix like `co.uk`, `github.io`, `appspot.com`, or
`vercel.app` to a list, **every domain registered under it will match**. Curate your list at the eTLD+1 granularity (`yopmail.com`,
`my-bad-domain.co.uk`, ...), never at the public-suffix level.

If your use-case actually requires public-suffix awareness, layer
[`jeremykendall/php-domain-parser`](https://github.com/jeremykendall/php-domain-parser)
on top of DisposableEmail.

## Exception handling

All exceptions thrown by DisposableEmail implement
`Wodzy\DisposableEmail\Exception\DisposableEmailException`, which extends `Throwable`.
You can catch the interface to handle any error raised by the library:

```php
use Wodzy\DisposableEmail\Exception\DisposableEmailException;
use Wodzy\DisposableEmail\Exception\InvalidEmailException;

try {
    $blocklist->blocks($userInput);
} catch (InvalidEmailException $e) {
    // The input is not a syntactically valid email.
} catch (DisposableEmailException $e) {
    // Any other library error.
}
```

| Exception | Raised when |
| --- | --- |
| `InvalidEmailException` | `blocks()`, `matches()` or `domainOf()` get a non-email or an IP literal |
| `DomainListFileNotFoundException` | `DomainSet::fromFile()` gets a path that does not exist or is unreadable |
| `InvalidDomainListFormatException` | the JSON is malformed or the `domains` key is missing |

## Limitations

- DisposableEmail is **not** a full email validator beyond a basic syntactic check
  (PHP's `FILTER_VALIDATE_EMAIL` with `FILTER_FLAG_EMAIL_UNICODE`). It does
  not check MX records, mailbox existence, or deliverability.
- **No Public Suffix List support.** See the caveat above.
- **IP-literal addresses are rejected** (`user@[192.168.1.1]`). They have no
  meaningful DNS domain to match against.
- **IDN support requires `ext-intl`.** Without it, `münchen.de` and
  `xn--mnchen-3ya.de` are treated as two unrelated strings.
- Domain matching is exact: an entry of `evil.com` will not match a
  homoglyph variation like `evіl.com` (the second `і` is Cyrillic).

## Development

```bash
composer install
composer test          # run PHPUnit
composer phpstan       # run PHPStan (level 10)
composer cs:check      # check code style
composer cs:fix        # fix code style
composer qa            # run everything
```

## Contributing

Pull requests are welcome. For substantial changes, please open an issue first
to discuss what you would like to change. New tests must accompany behavioural
changes, and the full QA suite must pass (`composer qa`).

## Credits

DisposableEmail is the rebranded, modernized successor of
[`EmailDomainBlacklistChecker`](https://github.com/wodzy/EmailDomainBlacklistChecker),
originally released in 2018. Some of the platforms I work on need to block
disposable-email providers at signup, and the refactor felt worth doing
properly so anyone with the same need can benefit from it.

## Author

**Walid Oukaci** ([@wodzy](https://github.com/wodzy))

## License

Released under the [MIT License](LICENSE).
