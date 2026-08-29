# Security Policy

## Reporting a vulnerability

**Never put the details of a security vulnerability in a public GitHub issue,
pull request, or discussion.** A public report exposes the issue to everyone
before a fix exists. The one exception is the detail-free request for a private
channel described below, which is a way of *reaching* a maintainer, not a way of
reporting.

Report vulnerabilities **privately** instead.

**Preferred route — GitHub private vulnerability reporting.** Open the
repository's **Security** tab and choose **Report a vulnerability**. The report
is visible only to the maintainers until an advisory is published.

**If that button is not there.** Private vulnerability reporting is a repository
setting, and it may not be switched on when you look. In that case:

- For a **non-sensitive** report — a hardening suggestion, a question about this
  policy, or an issue with no exploitable impact — open a normal issue on the
  repository's issue tracker.
- For a **sensitive** finding, open a **minimal public issue asking for a
  private channel** and nothing more. Title it as a request for private
  disclosure, say only that you have a security report and need somewhere
  private to send it, and **include no details**: no affected component, no
  reproduction, no proof of concept, no payloads. A maintainer will open a
  private channel and you can send the report there.

Do not send reports for this package to Ameriabank. Ameriabank operates the
gateway; it does not maintain this bridge, and a defect in this package is not
theirs to receive. See **Scope**, below.

**In the private report** — never in a public issue — please include, as far as
you can determine it:

- the affected version or commit;
- the Laravel and PHP versions in use;
- a description of the vulnerability and its impact;
- reproduction steps or a proof of concept;
- any suggested mitigation.

**Never include real credentials, card numbers, cardholder data, or other
personal data in a report.** Redact them, or describe them in the abstract.

You will receive an acknowledgement of the report. Once the issue is confirmed,
a fix will be prepared and released, and the report will be disclosed publicly
only after users have had a reasonable opportunity to upgrade. Credit is given
to reporters who want it.

## Supported versions

This package has not reached a stable release yet. Until `1.0.0` is tagged, only
the `main` branch is supported.

## Scope

This is an unofficial Laravel bridge. It is not affiliated with, endorsed by, or
supported by Ameriabank.

Vulnerabilities **in this package** — for example credential leakage through
Laravel's exception rendering, log output, container state or serialized
configuration — are in scope. Report them here.

Out of scope, and where each belongs instead:

- **A defect in the core client**, `davit-vardanyan/ameriabank-vpos-php`. That
  package has its own security policy; report it there.
- **A defect in Laravel itself.** Report it to the Laravel project.
- **A defect in the Ameriabank vPOS gateway, or in a merchant account.** That is
  the bank's, and it is not ours to receive or triage. Contact Ameriabank
  through the channels on their merchant documentation.
- **Exposure caused by a merchant's own configuration** — credentials committed
  to a repository, a `.env` served over HTTP, a debug page left enabled in
  production. Rotate the credentials with the bank immediately.

If you are unsure which of these applies, report it here anyway and say so. A
misrouted private report is a far smaller problem than a public one.
