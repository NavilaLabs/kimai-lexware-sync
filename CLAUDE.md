# CLAUDE.md

This file orients a Claude session working in this repository. It complements, rather than
repeats, `README.md`.

## What this is

A Kimai 2 plugin, a bundle under the namespace `KimaiPlugin\KimaiLexwareSyncBundle`, that
synchronizes one company's own Kimai instance with that same company's own Lexware account
(formerly known as lexoffice), through the Lexware Public API. That is a static bearer key, not
the Partner API or its OAuth flow. See `.claude/skills/kimai-lexware-sync/references/lexware-api.md`
for why that distinction matters.

The authoritative design document is
`docs/superpowers/specs/2026-09-04-kimai-lexware-sync-design.md`. Read it before making any
architectural change. It covers the data model, the configuration keys, the matching rule
semantics, error handling, and the reasoning behind every decision that is not obvious on its
own.

## Writing conventions for this repository

Write every file in this repository in English: code, comments if any survive the guidance
below, commit messages, and documentation. The only exception is a direct conversation with the
user in chat, since the user has asked to be addressed in German there.

Do not use an em dash or a double hyphen as punctuation anywhere in a project file. Use the
punctuation a person would normally write: a comma, a period, a colon, a semicolon, or
parentheses, whichever the sentence actually calls for. A table separator in Markdown is markup,
not punctuation, and stays as it is.

Avoid abbreviations, both in prose and in code identifiers. Write "for example" instead of the
shortened Latin form, "customer" instead of "cust", "configuration" instead of "config" in a
class or method name. A reader who does not already know the shorthand should still be able to
follow along.

## PHP best practices for this plugin

- Declare `strict_types=1` in every file.
- Use constructor property promotion and typed, readonly properties where the value never
  changes after construction.
- Mark classes `final` unless there is a concrete, current reason for something else to extend
  them.
- Keep a class responsible for one thing. When a class is doing two unrelated jobs, split it
  into two classes rather than growing it.
- Prefer an enum over a raw string for a fixed set of values, such as the status of a tracked
  order confirmation.
- Inject dependencies through the constructor. Do not reach for a static call or a service
  locator when a constructor argument will do.
- Use strict comparison, `===` and `!==`, always.
- Follow the naming and formatting conventions Kimai enforces on its own core code, described in
  the `kimai-plugin` skill: English identifiers, four space indentation, single quoted strings,
  PHP attributes for routing and mapping, and business logic living in services rather than
  controllers.

Avoid comments. If a piece of code seems to need a comment to explain what it does, that is
usually a sign the code itself is not written clearly enough yet: rename something, extract a
method, or restructure the logic until the comment becomes unnecessary. A comment explaining
why a non obvious constraint exists, such as a workaround forced by an external API, is the rare
exception, not the rule.

Do not divide a class into sections with banner comments. If a class has grown enough that it
feels like it needs headings, that is a sign it should be split into smaller classes, most
likely in a new namespace, rather than organized with comments inside one large file.

## Load these skills before writing plugin code

- `.claude/skills/kimai-plugin`. This covers the mechanics of a Kimai plugin: the bundle layout,
  the extension points, the rule that a plugin may not add its own Composer dependencies,
  permission and configuration registration, the required `kimai2_ext_` table prefix, and the
  lint, boot and exercise verification loop. Read this before scaffolding anything.
- `.claude/skills/kimai-lexware-sync`. This covers the two API reference documents,
  `references/kimai-api.md` and `references/lexware-api.md`. Both were checked against real
  source code or a real account during design. Verify anything not already covered there before
  shipping a new mapping.

## Constraints that are easy to violate by accident

- No Composer dependencies of our own. Only what Kimai core already ships, including the
  Symfony HTTP client and Doctrine ORM, is usable at runtime. This is why the Lexware API client
  is written from scratch instead of pulled in as a package.
- This repository is licensed under MIT. Never add a dependency licensed under GPL or AGPL, and
  never vendor code under those licenses. This was ruled out deliberately during design, when
  the AGPL version three licensed package `baebeca/lexware-php-api` was considered and rejected,
  because this plugin may be published publicly later.
- Never trust the content of a webhook body. `LexwareWebhookController` verifies the signature
  and then uses the payload only to know what to refetch. The data that gets processed always
  comes from a fresh, authenticated request against the Lexware API.
- Every regular expression configuration field is optional, and an empty value means it matches
  everything, not that it matches nothing. This applies to `title_regex` and `line_regex`, and
  should apply to any matching rule added later.
- Do not match customers by name similarity. A Lexware contact with no row in the contact
  mapping table always becomes a new Kimai customer. Adding name based matching would need a
  deliberate design change, since it was rejected during brainstorming as a correctness risk.
- One Doctrine transaction per conversion. The tracking record upsert and the resulting Kimai
  customer, project and activity creation happen together. Do not split them across separate
  requests or separate messages.
- Plugin database tables need the `kimai2_ext_` prefix required by Kimai's plugin convention.
  The design specification uses shorter names for readability. Apply the real prefix when the
  actual Doctrine entities and migrations are created.
- Milestone two, the invoice synchronization, is described only at the roadmap level. Its open
  questions are listed in the design specification. Do not implement any part of it ahead of its
  own brainstorming and specification pass, even when a milestone one change makes part of it
  look easy to add along the way.

## Where things live

- `docs/superpowers/specs/` holds the design specifications. This project follows the
  superpowers skill's flow of brainstorming, then a written specification, then an
  implementation plan, for any change that is not trivial. Check here before re-deriving a
  decision from scratch.
- `.env` and `.devcontainer/.env` hold local development secrets, such as the Lexware API key,
  and devcontainer settings. Never commit a real production secret here, only development
  sandbox data such as what is already present.
