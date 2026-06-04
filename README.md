# MailSentry

[![CI](https://github.com/wodzy/MailSentry/actions/workflows/ci.yml/badge.svg)](https://github.com/wodzy/MailSentry/actions/workflows/ci.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/wodzy/mail-sentry.svg)](https://packagist.org/packages/wodzy/mail-sentry)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue.svg)](composer.json)

MailSentry is a lightweight, dependency-free PHP library that checks whether
an email address belongs to a blacklisted (e.g. disposable) domain. It ships
with a curated list of well-known disposable-email providers, and you can plug
in your own list at any time.

## Features

- Zero runtime dependencies (PHP + `ext-filter` + `ext-json` only)
- Optional IDN support via `ext-intl` (`münchen.de` ≡ `xn--mnchen-3ya.de`)
- Strict types, PHP 8.2+, fully type-hinted, `final`, `readonly`
- Subdomain-aware matching: `foo.yopmail.com` is caught when `yopmail.com` is listed
- O(1) lookups via an internal hash map
- `ValidatorInterface` for easy mocking and substitution
- Implements `Countable`
- Load from file, in-memory list, generator, or use the bundled blacklist
- PSR-4 autoloaded, ready for Composer
- Tested against PHP 8.2, 8.3 and 8.4

## Installation

```bash
composer require wodzy/mail-sentry
```

For IDN normalization (recommended if your users may register with
internationalized domains):

```bash
# Debian/Ubuntu
sudo apt-get install php-intl

# macOS (Homebrew PHP already includes intl)
```

## Usage

### Using the bundled disposable-email blacklist

```php
use Wodzy\MailSentry\Validator;

$validator = Validator::withDefaultBlacklist();

$validator->isBlacklisted('john.doe@yopmail.com');     // true
$validator->isBlacklisted('jane.doe@gmail.com');       // false
$validator->isBlacklisted('user@foo.mailinator.com');  // true (subdomain match)
```

The default list is parsed from disk only once per process and reused across
calls.

> The bundled list (`src/data/disposable_domains.json`) is a manually curated,
> alphabetically sorted set of well-known disposable-email providers at the
> eTLD+1 granularity. It is intentionally conservative. To add or remove a
> provider, open a pull request editing that file — entries are kept lowercase
> and sorted.

### Using your own JSON file

The file must look like:

```json
{
    "domains": [
        "example.com",
        "spam.test"
    ]
}
```

```php
$validator = Validator::fromFile('/path/to/blacklist.json');
$validator->isBlacklisted('hello@example.com'); // true
```

### Using an in-memory list

```php
$validator = new Validator(['example.com', 'spam.test']);
```

The constructor accepts any `iterable<string>` (arrays, generators, or your
own iterator implementation).

### Combining the bundled list with your own additions

```php
$bundled = Validator::withDefaultBlacklist()->getBlacklistedDomains();
$validator = new Validator([...$bundled, 'my-own-bad-domain.com']);
```

### Depending on the interface

```php
use Wodzy\MailSentry\ValidatorInterface;

final class SignupService
{
    public function __construct(private readonly ValidatorInterface $emailValidator) {}
}
```

This makes mocking trivial in your own tests.

## How matching works

For an email `user@a.b.example.com`, MailSentry checks the following candidates
against the blacklist, in order:

1. `a.b.example.com`
2. `b.example.com`
3. `example.com`

A bare TLD (`com`) is never checked, so `evil.com` on the list will not
accidentally block `gmail.com`.

### Caveat: do NOT add public suffixes to your blacklist

MailSentry does **not** resolve the [Public Suffix List](https://publicsuffix.org/).
If you add a public suffix like `co.uk`, `github.io`, `appspot.com`, or
`vercel.app` to your blacklist, **every domain registered under it will be
blocked**. Curate your list at the eTLD+1 granularity (`yopmail.com`,
`my-bad-domain.co.uk`, ...), never at the public-suffix level.

If your use-case actually requires public-suffix awareness, layer
[`jeremykendall/php-domain-parser`](https://github.com/jeremykendall/php-domain-parser)
on top of MailSentry.

## Exception handling

All exceptions thrown by MailSentry implement
`Wodzy\MailSentry\Exception\MailSentryException`, which extends `Throwable`.
You can catch the interface to handle any error raised by the library:

```php
use Wodzy\MailSentry\Exception\InvalidEmailException;
use Wodzy\MailSentry\Exception\MailSentryException;

try {
    $validator->isBlacklisted($userInput);
} catch (InvalidEmailException $e) {
    // The input is not a syntactically valid email.
} catch (MailSentryException $e) {
    // Any other library error.
}
```

| Exception                            | Raised when                                                          |
| ------------------------------------ | -------------------------------------------------------------------- |
| `InvalidEmailException`              | `isBlacklisted()` is given a non-email or an IP-literal address      |
| `BlacklistFileNotFoundException`     | `fromFile()` receives a path that doesn't exist or isn't readable    |
| `InvalidBlacklistFormatException`    | JSON is malformed or the `domains` key is missing                    |

## Limitations

- MailSentry is **not** a full email validator beyond a basic syntactic check
  (PHP's `FILTER_VALIDATE_EMAIL` with `FILTER_FLAG_EMAIL_UNICODE`). It does
  not check MX records, mailbox existence, or deliverability.
- **No Public Suffix List support.** See the caveat above.
- **IP-literal addresses are rejected** (`user@[192.168.1.1]`). They have no
  meaningful DNS domain to match against.
- **IDN support requires `ext-intl`.** Without it, `münchen.de` and
  `xn--mnchen-3ya.de` are treated as two unrelated strings.
- Domain matching is exact: a blacklist entry of `evil.com` will not match a
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

MailSentry is the rebranded, modernized successor of
[`EmailDomainBlacklistChecker`](https://github.com/wodzy/EmailDomainBlacklistChecker),
originally released in 2018. Some of the platforms I work on need to block
disposable-email providers at signup, and the refactor felt worth doing
properly so anyone with the same need can benefit from it.

## Author

**Walid Oukaci** ([@wodzy](https://github.com/wodzy))

## License

Released under the [MIT License](LICENSE).
