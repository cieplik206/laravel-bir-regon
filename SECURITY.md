# Security policy

## Reporting a vulnerability

Please do not disclose security vulnerabilities in public issues.

Use [GitHub's private vulnerability reporting](https://github.com/cieplik206/laravel-bir-regon/security/advisories/new)
to submit a report. Include the affected version, a reproduction, the expected
impact, and any suggested mitigation.

You should receive an acknowledgement after the report is reviewed. Confirmed
issues will be handled privately until a fix and coordinated disclosure are
ready.

## Credentials

Never include a production BIR API key in a vulnerability report, issue, pull
request, test fixture, or log excerpt. Replace credentials with redacted or
sandbox values.

## Supported versions

The latest `2.x` release is the supported stable line. The latest `1.1.x`
release remains eligible for security fixes during a three-month migration
period ending on 2026-11-24. This eligibility is a best-effort maintenance
policy, not a service-level commitment.

| Version | Supported |
| --- | --- |
| Latest 2.x release | Yes |
| Latest 1.1.x release | Security fixes through 2026-11-24 |
| 1.0.x and older | No |

Users of version 1 should migrate to version 2 before 2026-11-24. See the
[2.0 upgrade guide](UPGRADE-2.0.md) for the breaking changes and required
platform upgrade.
