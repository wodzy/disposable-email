# Security Policy

## Supported Versions

Security fixes are provided for the latest stable release line.

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |
| < 1.0   | :x:                |

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities.

Instead, report them privately:

- Preferred: open a [GitHub security advisory](https://github.com/wodzy/MailSentry/security/advisories/new)
  on this repository, or
- Email **hello@oukaci.com** with the details.

Please include:

- A description of the vulnerability and its impact.
- Steps to reproduce (a minimal proof of concept is ideal).
- The affected version(s).

You can expect an acknowledgement within **5 business days**. Once the issue is
confirmed, a fix will be prepared and released, and the reporter will be
credited unless they prefer to remain anonymous.

## Scope

MailSentry performs **syntactic** email checks and blacklist membership lookups.
It is **not** an authentication or anti-fraud boundary on its own and does not
verify mailbox existence or deliverability. Reports about the inherent
limitations documented in the README (no Public Suffix List resolution,
IP-literal rejection, homoglyph domains) are not considered vulnerabilities.
