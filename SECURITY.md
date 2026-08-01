# Security policy

## Supported versions

Only the latest release line is supported. Fixes are published as a new tag; there are no backports
to older majors.

## Reporting a vulnerability

**Do not open a public issue.**

Email <info@adriangoldner.com> with:

- what the issue is and which version you found it in,
- the smallest set of steps that reproduces it,
- what an attacker gets out of it.

You will get an acknowledgement within three working days. If the report is valid you will be told
when a fix is tagged, and credited in the changelog entry unless you would rather not be.

## Scope

This package owns brand resolution and the fail-closed brand scope, so the findings that matter most
here are the ones that let data cross a brand boundary: a request resolving to the wrong brand, a
query escaping `BrandScope`, or a Control Panel route writing a membership the acting user is not
permitted to change.

Vulnerabilities in Statamic itself belong to [statamic/cms](https://github.com/statamic/cms/security),
not here.
