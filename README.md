# linkvalidator2

A rewrite of the TYPO3 system extension `linkvalidator`, developed as a local extension
first. Once complete it replaces `typo3/sysext/linkvalidator` under the extension key
`linkvalidator`. Status: **work in progress**. It can be tested on TYPO3 14.3 LTS next to the
core linkvalidator.

```bash
composer require georgringer/linkvalidator2:dev-main
vendor/bin/typo3 extension:setup
```

Requires TYPO3 14.3 or main, PHP 8.4 or 8.5. Documentation: [`Documentation/`](Documentation/)
(editors, integrators, developers). Design decisions: [`Notes/`](Notes/) (German).
Flow diagrams: [claude.ai](https://claude.ai/artifact/VSeBbLmu9wByDuNBZ4WvDs) (German).

## What it does

- Finds links in records from the TCA schema: link fields, rich text, plain URLs, shortcut pages.
- Resolves each link with the core `LinkService` and checks it with a checker per link type.
- Stores one result per target, the places with a problem and a status per record
  (which is also the work queue).
- Shows the result in the module *Link Management > Check Links (new)*, the rich text editor,
  the page module, a dashboard widget and a mail report.

## Usage

```bash
# check one record, print every link and its result
bin/typo3 linkvalidator2:check --record=tt_content:471
# check a page and its subpages right away
bin/typo3 linkvalidator2:check --page=1287 --depth=99
# message queue: queue a page tree, a worker checks it
bin/typo3 linkvalidator2:fillqueue 1287
bin/typo3 messenger:consume linkvalidator2_priority doctrine --time-limit=240
# mail broken links of an area, here only those broken for a week
bin/typo3 linkvalidator2:report 1287 --depth=99 --to=editor@example.org --min-age="7 days"
```

In the backend: select a page, choose the levels and press *Check links*. Settings are in the
extension configuration (`ext_conf_template.txt`).

## Tests

Standalone in containers, independent of the surrounding core checkout:

```bash
Build/Scripts/runTests.sh -s composerInstall
Build/Scripts/runTests.sh -s unit
Build/Scripts/runTests.sh -s functional            # sqlite, or -d mariadb|mysql|postgres
```

Test data for a local instance: `Notes/seed-testdata.php` (every kind of broken link below
page 1287) and `Notes/seed-bigtree.php` (about 5,000 content elements for load tests).

## Feature comparison

✅ yes · 🟡 partly · ❌ no · ➖ not on purpose · ❓ not verified · 📋 planned

The *linkvalidator* column names the Forge issue where the core has a known problem.
Details and explanations: [`Notes/2026-10-21-feature-comparison-full.md`](Notes/2026-10-21-feature-comparison-full.md).

| Feature | linkvalidator | brofix | linkvalidator2 |
|---|---|---|---|
| **Finding links** | | | |
| Fields derived from TCA | ❌ list in TSconfig #96447 | ❌ | ✅ |
| Opt-out of tables / fields | 🟡 | ✅ | ✅ |
| FlexForms | ❌ | 🟡 | 📋 |
| Shortcut pages | ❌ #92542 | ❌ | ✅ |
| Plain-text URLs | 🟡 `&nbsp;` #97937 | ✅ | ✅ |
| Custom link types | 🟡 #94086 | ✅ | ✅ |
| Hidden source records | 🟡 option | 🟡 option | ✅ |
| Source hidden by its page | ❌ #96447 | ❓ | 📋 |
| Translation of a hidden original | ❌ #94195 | ❓ | 📋 |
| Workspaces | ❌ | 🟡 live only | 📋 required |
| **Checking** | | | |
| Pages, records, files, folders | ✅ | ✅ | ✅ |
| Page hidden via parent (`extendToSubpages`) | 🟡 #101367 fix in review (96918), #106675 | ❓ | ✅ |
| Restricted or scheduled target as notice | ❌ | ❓ | ✅ |
| Shortcut / link page without reachable target | ❌ #92542 | ❓ | ✅ |
| Empty or unresolvable links | ❌ | ❓ | ✅ |
| Anchors (`#c123`) | 🟡 #100933 | ✅ | ➖ |
| External URLs | 🟡 off by default | ✅ | ✅ |
| Result cache across runs | ❌ #92700 | ✅ | ✅ |
| 401/403/429/5xx/TLS/timeout as "unknown" | ❌ #96525 #85006 | ❌ | ✅ |
| Full error message | 🟡 #101935 | ❓ | ✅ |
| Escalate "unknown" to broken | ❌ | ❓ | 📋 |
| Polite crawling (delay, `Retry-After`) | 🟡 user agent #89287 | ✅ | 🟡 user agent |
| **Exclusions** | | | |
| Exclude URLs, domains, pages | ❌ #85127 | ✅ | ✅ |
| "Ignore" from the report | ❌ #92822 | ❓ | ✅ |
| **Running checks** | | | |
| Scheduler / command | ✅ task | ✅ command | ✅ command |
| Message queue, parallel workers | ❌ | ❌ | ✅ |
| Checking large trees in the browser | ❌ timeouts #89266 | ❓ | ✅ |
| Recheck on save | 🟡 | 🟡 | ✅ |
| Results of hidden records removed | ❌ #105911 | ❓ | ✅ |
| Cleanup on delete / move | ❌ #89426 | ✅ delete | ✅ |
| Parallel runs locked | ❌ #89266 | ❓ | 🟡 |
| **Backend** | | | |
| Filter, sort, paginate | 🟡 #89454 | ✅ | ✅ |
| Editors see only what they may fix | 🟡 #105413 #104004 | ✅ | ✅ |
| Recheck a single link | ❌ | ✅ | ✅ |
| Markers in the rich text editor | 🟡 | 🟡 | ✅ |
| Page module callout | ❌ | ✅ | ✅ |
| Dashboard widget | ❌ | ❌ | ✅ |
| Mail report | ✅ #106487 #106677 | ✅ | ✅ |
| **Configuration and extending** | | | |
| Configuration | Page TSconfig | TSconfig + ext. conf. | ext. conf., 📋 site settings |
| PSR-14 events | ✅ 2 | 🟡 | 📋 |

Sources: core `typo3/sysext/linkvalidator` on `main` and brofix `backup/brofix`. Cells marked
🟡 or ❓ differ in detail or are not verified, check the code before relying on them.
Open Forge issues: [`Resources/Private/Issues/`](Resources/Private/Issues/).
