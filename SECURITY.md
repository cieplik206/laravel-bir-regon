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

The `main` branch is the development line for the next `2.x` release. Security
fixes are provided for that development line and, after publication, for the
latest `2.x` release. The legacy `1.x` line is not maintained; this policy does
not promise security fixes for `1.x` while version 2 is being prepared.

| Version | Supported |
| --- | --- |
| 2.x (current `main`; next release) | Yes |
| 1.x | No |
| < 1.0 | No |
