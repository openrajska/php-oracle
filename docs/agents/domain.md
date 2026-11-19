# Domain Docs

How the engineering skills should consume this repo's domain documentation when exploring the codebase.

## Before exploring, read these

- **`GLOSSARY-MAP.md`** at the repo root: it points at one `GLOSSARY.md` per context. Always read the Core glossary, because the other contexts build on its language. Then read the glossary of each context relevant to the topic.
- **`docs/adr/`**: read ADRs that touch the area you're about to work in. Also check `packages/<package>/docs/adr/` for context-scoped decisions.

If any of these files don't exist, **proceed silently**. Don't flag their absence; don't suggest creating them upfront. The `/domain-modeling` skill (reached via `/grill-with-docs` and `/improve-codebase-architecture`) creates them lazily when terms or decisions actually get resolved.

## File structure

This repo is **multi-context**: each package is a context with its own language.
Core owns the shared domain language, and the Extension and the Laravel adapter
build on it.

```
/
├── GLOSSARY-MAP.md                    ← lists the contexts and how they relate
├── docs/adr/                          ← system-wide decisions (span packages)
├── fixtures/sql/
├── spikes/
└── packages/
    ├── php-oracle/                    ← Core
    │   ├── GLOSSARY.md
    │   └── docs/adr/                  ← Core-only decisions
    ├── php-oracle-ext/                ← Extension
    │   ├── GLOSSARY.md
    │   └── docs/adr/
    └── laravel-oracle/                ← Laravel adapter
        ├── GLOSSARY.md
        └── docs/adr/
```

A decision goes in the root `docs/adr/` when it affects more than one package,
and in `packages/<package>/docs/adr/` when it affects only that package.

## Use the glossary's vocabulary

When your output names a domain concept (in an issue title, a refactor proposal, a hypothesis, a test name), use the term as defined in the relevant context's `GLOSSARY.md` (Core's, for shared Oracle concepts). Don't drift to synonyms the glossary explicitly avoids.

If the concept you need isn't in the glossary yet, that's a signal: either you're inventing language the project doesn't use (reconsider) or there's a real gap (note it for `/domain-modeling`).

## Flag ADR conflicts

If your output contradicts an existing ADR, surface it explicitly rather than silently overriding:

> _Contradicts ADR-0007 (event-sourced orders), but worth reopening because…_
