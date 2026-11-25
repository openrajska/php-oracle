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

## Comments

Before writing a comment, check:

1. Does a better name make it unnecessary? Then rename instead.
2. Does it say why, not what? A comment that restates the code goes.
3. Would a reader who knows the language and the tool get lost without it?
   Config files (rector.php, pint.json, phpstan.dist.neon) usually explain
   themselves through the tool's docs.
4. Is it public contract? PHPDoc that the IDE or PHPStan reads (generics,
   @internal, @throws, what a public method promises) stays.
5. Does the reason belong somewhere else? Decision history goes in the commit
   message, the ADR or the spec, not in the code.

Write the comment only if it passes 1–3, or if 4 applies.
