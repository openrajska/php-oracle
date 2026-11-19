# CLAUDE.md

## Commits

- Every commit follows [CONTRIBUTING.md](CONTRIBUTING.md): Conventional Commits,
  in English, using the types and scopes listed there. Read it before writing a
  commit message.
- Commit only when the user asks.

## Agent skills

### Issue tracker

Issues live in GitHub Issues at openrajska/php-oracle (via the `gh` CLI). See `docs/agents/issue-tracker.md`.

### Triage labels

Default canonical labels: needs-triage, needs-info, ready-for-agent, ready-for-human, wontfix. See `docs/agents/triage-labels.md`.

### Domain docs

Multi-context: `GLOSSARY-MAP.md` at the root points to one `GLOSSARY.md` per package (Core, Extension, Laravel adapter); system-wide ADRs in `docs/adr/`. See `docs/agents/domain.md`.
