# Security policy

Fahoba holds a family's calendar, photos, children's timetables and the
credentials of connected services. A weakness there matters, so thank you
for reporting it privately.

## Reporting a vulnerability

- **Preferred:** GitHub's private reporting — *Security → Report a
  vulnerability* in this repository.
- **Or by mail** to office@copex.io, subject “Fahoba security”.

Please do not open a public issue. Include the version or image tag, the
steps to reproduce and what an attacker gains. You get an answer within
seven days; a fix for a confirmed problem goes out as a patch release, and
you are credited in the changelog unless you prefer not to be.

## Supported versions

Only the latest release receives security fixes. Updating is one command
(`./rollout.sh` in `deploy/`, see [`docs/installation.md`](docs/installation.md#updating)).

## In scope

- Crossing household boundaries: reading or changing another family's data
  on a shared server.
- Signing in without the right credentials, bypassing the parent PIN or a
  permission on the server side.
- Reaching internal addresses through an address a household enters
  (calendar, recipe or holiday links) — `OutboundHttp` is meant to stop it.
- Leaking credentials, tokens or the photos of another household.

## Out of scope

- Anything that needs the operator's server access or `APP_KEY` — the
  operator can read every household by design (support access is logged).
- Plugins: an installed package runs with full rights. Only install
  packages you trust (see `AGENTS.md`, “No cage”).
- Missing hardening headers on a server you configured differently from
  `deploy/`.

## What the image does for you

- Dependency audits (`pnpm audit`, `composer audit`) stop the image build
  on a known vulnerability.
- A Content Security Policy from one place; the app runs no inline script.
- Uploaded photos are redrawn, which drops EXIF and location data.
