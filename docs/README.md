# Reminders — Documentation

**Status:** 📝 **Planning — 2026-08-03**

Reminders is a personal Laravel + Inertia/Vue PWA for creating reminders that are pushed
to a mobile device via web push notifications. It is installed to the phone's home screen
as a standalone PWA.

## How this docs system works

Same lifecycle convention as NorthernCall_v2:

- [todo/](todo/) — feature specs that have not shipped yet. Each is a self-contained
  implementation brief intended to be handed to a single implementation sub-agent.
- [shipped/](shipped/) — specs are **moved** here (not deleted) when implemented, with a
  close-out section added: what deviated from the spec and *"Things later work must know."*
- [discontinued/](discontinued/) — specs we decided not to build, with the reason.

Each folder has a `README.md` index table. Cross-cutting documents live at the docs root
in SCREAMING_SNAKE_CASE. No YAML frontmatter — a bold `**Status:**` line sits under every H1.

## Root documents

| Doc | Purpose |
|---|---|
| [ARCHITECTURE.md](ARCHITECTURE.md) | Stack, load-bearing decisions, timezone rule, SQLite caveats, deployment/HTTPS constraints |

## Hand-off protocol for implementation sub-agents

1. One agent per spec, in the order given in [todo/README.md](todo/README.md) — the specs
   declare their dependencies.
2. The agent reads [ARCHITECTURE.md](ARCHITECTURE.md) first, then its assigned spec. Specs
   reference concrete files in sibling apps under `C:\Users\binar\Documents\websites\` to
   copy or imitate — prefer copying the proven pattern over inventing a new one.
3. Definition of done is the spec's **Acceptance criteria** section plus a green
   `composer test` (Pint + Larastan + Pest).
4. On completion, move the spec to `shipped/`, update both README indexes, and append the
   close-out section.
