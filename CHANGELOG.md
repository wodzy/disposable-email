# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

First release as `wodzy/disposable-email`. Rename this section to
`## [1.0.0] - YYYY-MM-DD` when tagging `v1.0.0`.

> **Rebranding:** the project formerly known as
> `wodzy/email-domain-blacklist-checker` (namespace
> `Wodzy\EmailDomainBlacklistChecker`) is now **DisposableEmail**
> (`wodzy/disposable-email`, namespace `Wodzy\DisposableEmail`). This is a clean break:
> all class names, namespaces and the Composer package name have changed.

### Added
- Composer support with PSR-4 autoloading (`Wodzy\DisposableEmail\`).
- `DomainSet`: an immutable, `Countable`, iterable set of normalized domains with
  `matches()`, `contains()`, `merge()`, `toArray()`, `fromFile()`, `fromNormalized()`,
  `bundled()`, and the static helpers `normalize()` and `domainOf()`.
- `Blocklist` (implements `BlocklistInterface`): `blocks()` an email when its domain
  is in the blocked set and not in the never-blocked set. Never-blocked domains
  always win, including subdomains of a blocked domain.
- `Blocklist::bundled()` and `DomainSet::bundled()` use a bundled list of 195
  well-known disposable-email providers, parsed once per process.
- Subdomain-aware matching: an entry of `yopmail.com` also matches `foo.yopmail.com`.
- IDN normalization via UTS-46 (`ext-intl`, optional): `münchen.de` and
  `xn--mnchen-3ya.de` are treated as the same domain.
- IP-literal rejection (`user@[1.2.3.4]` throws `InvalidEmailException`).
- Exception hierarchy implementing `DisposableEmailException`:
  `InvalidEmailException` (extends `\InvalidArgumentException`),
  `DomainListFileNotFoundException` (extends `\RuntimeException`),
  `InvalidDomainListFormatException` (extends `\UnexpectedValueException`).
- Strict syntactic email validation via `FILTER_VALIDATE_EMAIL` with
  `FILTER_FLAG_EMAIL_UNICODE`. The domain is not trimmed: `"a@yopmail.com "` is invalid.
- List entries in common third-party formats are normalized: `*.yopmail.com`,
  `.yopmail.com`, `@yopmail.com` and `yopmail.com.` all match `yopmail.com`.
- PHPUnit test suite, PHPStan (level 10), PHP-CS-Fixer and a GitHub Actions CI
  matrix (PHP 8.2 / 8.3 / 8.4 / 8.5, lowest + highest deps).

### Changed (compared to the 2018 library)
- **BREAKING:** `Validator::isBlacklisted($file, $email)` is replaced by
  `Blocklist::blocks($email)`, which returns `true` when the email must be refused.
  The old method returned `true` for *valid* addresses, contradicting its name.
- **BREAKING:** the list is given once (constructor or factory) instead of a file
  path on every call, so it is parsed once.
- **BREAKING:** entries must be full domain names (`yopmail.com`) instead of bare
  second-level labels (`yopmail`).
- Matching is case-insensitive and trims whitespace; lookups are O(1).
- Minimum PHP version is now 8.2.

### Removed
- **BREAKING:** `Wodzy\EmailDomainBlacklistChecker\CustomException\CustomException`.
  Catch `Wodzy\DisposableEmail\Exception\DisposableEmailException` (or a more
  specific subclass) instead.

### Documentation
- README warns that adding a Public Suffix List entry (`co.uk`, `github.io`, ...)
  matches every domain registered under it. The PSL is not resolved.
- README documents IDN behaviour, the optional `ext-intl` requirement and the
  IP-literal rejection.

## [0.x] (pre-2026)

Legacy releases under the original `wodzy/email-domain-blacklist-checker`
name. No detailed changelog was maintained prior to the DisposableEmail rebranding.

[Unreleased]:
  https://github.com/wodzy/disposable-email/compare/v1.0.0...HEAD
[1.0.0]:
  https://github.com/wodzy/disposable-email/releases/tag/v1.0.0
