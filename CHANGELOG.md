# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] (1.0.0, MailSentry)

> **Rebranding:** the project formerly known as
> `wodzy/email-domain-blacklist-checker` (namespace
> `Wodzy\EmailDomainBlacklistChecker`) is now **MailSentry**
> (`wodzy/mail-sentry`, namespace `Wodzy\MailSentry`). This is a clean break:
> all class names, namespaces and the Composer package name have changed.

### Added
- Composer support with PSR-4 autoloading (`Wodzy\MailSentry\`).
- Full PHPUnit test suite covering happy paths, edge cases, error handling,
  IDN, IP-literals and quoted local-parts.
- PHPStan (level 10) and PHP-CS-Fixer configurations.
- GitHub Actions CI matrix (PHP 8.2 / 8.3 / 8.4, lowest + highest deps).
- `Validator::withDefaultBlacklist()` factory using a bundled list of ~180
  well-known disposable-email providers. The list is parsed once per process
  and memoized.
- `Validator::fromFile()` factory.
- `ValidatorInterface` public contract. `final class Validator` implements it,
  so consumers can type-hint the interface for easy mocking.
- `Validator` implements `\Countable`, so `count($validator)` works directly.
- Subdomain-aware matching: a blacklist entry of `yopmail.com` also blocks
  `foo.yopmail.com`.
- IDN normalization via UTS-46 (`ext-intl`, optional): `münchen.de` and
  `xn--mnchen-3ya.de` are treated as the same domain.
- IP-literal rejection (`user@[1.2.3.4]` throws `InvalidEmailException`).
- Dedicated exception hierarchy implementing `MailSentryException`:
  `InvalidEmailException` (extends `\InvalidArgumentException`),
  `BlacklistFileNotFoundException` (extends `\RuntimeException`),
  `InvalidBlacklistFormatException` (extends `\UnexpectedValueException`).
- Strict syntactic email validation via `FILTER_VALIDATE_EMAIL` with
  `FILTER_FLAG_EMAIL_UNICODE`.
- `.editorconfig` for consistent contributions.
- `composer.json` declares `ext-filter` explicitly and suggests `ext-intl`.

### Changed
- **BREAKING:** `Validator::isBlacklisted()` now returns `true` when the
  email's domain is blacklisted. Previously it returned `true` for valid /
  non-blacklisted addresses, which contradicted the method name.
- **BREAKING:** `Validator::isBlacklisted()` no longer takes a `$file`
  argument. The blacklist is supplied to the constructor or via the new
  factory methods, so it is parsed once instead of on every call.
- **BREAKING:** Blacklist entries must now be full domain names
  (`yopmail.com`) instead of bare second-level labels (`yopmail`).
- Domain matching is case-insensitive and trims whitespace.
- Non-string entries supplied to the constructor are silently skipped.
- Lookups use an internal hash map for O(1) membership checks.
- Minimum PHP version is now 8.2.

### Removed
- **BREAKING:** `Wodzy\EmailDomainBlacklistChecker\CustomException\CustomException`.
  Catch `Wodzy\MailSentry\Exception\MailSentryException` (or a more specific
  subclass) instead.
- `Validator::fromArray()` factory. Use the `iterable<string>` constructor
  directly: `new Validator([...])`.

### Documentation
- README now explicitly warns that adding a Public Suffix List entry
  (`co.uk`, `github.io`, ...) to the blacklist would block every domain
  registered under it. MailSentry does not resolve the PSL.
- README documents IDN behaviour and the optional `ext-intl` requirement.
- README lists IP-literal rejection as an intentional limitation.

## [0.x] (pre-2026)

Legacy releases under the original `wodzy/email-domain-blacklist-checker`
name. No detailed changelog was maintained prior to the MailSentry rebranding.
