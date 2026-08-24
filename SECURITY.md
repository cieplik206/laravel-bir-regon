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

The latest `1.1.x` release is the currently supported stable line. Work on the
next `2.x` release is active but unpublished and must not be treated as a
released replacement for `1.1.x`. Security fixes are accepted for both the
supported stable line and active `2.x` development.

| Version | Supported |
| --- | --- |
| 2.x (unreleased) | Development only |
| Latest 1.1.x release | Yes |
| 1.0.x and older | No |

This policy must be updated when `2.0.0` is published. That update should state
the support status and any end-of-life or migration period for the `1.1.x`
line instead of assuming that publication alone ends its support.
