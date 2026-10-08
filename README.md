# linkvalidator2

A rewrite of the TYPO3 system extension `linkvalidator`, developed as a local extension
first. Once complete it replaces `typo3/sysext/linkvalidator` under the extension key
`linkvalidator` (namespace, tables and labels are renamed from `linkvalidator2` then).

Status: **work in progress**. Design decisions and the implementation plan live in
[`Notes/`](Notes/) (German).

## What it does

- Finds links in records: fields of type `link`, rich text fields, fields with a
  `typolink`/`typolink_tag` soft reference, and shortcut pages. The fields are derived
  from the TCA schema of the record type – nothing to configure per extension.
- Resolves every link with the core `LinkService` and checks it with a checker per link
  type (page, record, file, folder, external URL).
- Stores only broken links, plus a check status per record. The status table doubles as
  the work queue: never checked records first, then the least recently checked ones.
- Shows the result in a backend module below *Link Management* and marks broken links
  in the rich text editor.

## Usage

```bash
# check one record, print every link and its result
bin/typo3 linkvalidator2:check --record=tt_content:471
# check all records of a page and its subpages
bin/typo3 linkvalidator2:check --page=1287 --depth=1
```

Backend: *Link Management → Check Links (new)* (admins only for now), select a page,
choose the levels and press *Check*.

Configuration (extension configuration): `excludedFields` – comma-separated
`table.field` or `table.*` that are never checked.

Test data for a local instance: `Notes/seed-testdata.php` (creates pages and content
with every kind of broken link below page 1287).

## Feature comparison

Legend: ✅ yes · 🟡 partly · ❌ no · ❓ not verified · 📋 planned (step of the rewrite plan, see Notes) · 💭 idea, undecided

| Feature | linkvalidator (core) | brofix | linkvalidator2 done | linkvalidator2 planned |
|---|---|---|---|---|
| **Finding links** | | | | |
| Fields to check | explicit list in Page TSconfig `searchFields` | explicit list in TSconfig, only if editable in FormEngine | ✅ derived from the TCA schema, only fields shown for the record type | |
| Opt-out of tables / fields | 🟡 by not listing them | ✅ TSconfig, `excludeCtype` | ✅ extension configuration `excludedFields` | |
| Links in FlexForms | ❌ | 🟡 FormEngine-based field check | ❌ | 📋 later |
| Shortcut pages | ❌ | ❌ | ✅ | |
| Plain-text URLs (softref `url`) | ✅ | ✅ | ❌ | 💭 |
| Link type detection | own `fetchType()` heuristics per link type | own link types | ✅ core `LinkService::resolve()` | |
| Hidden / expired source records | 🟡 option `checkhidden` | 🟡 option `reportHiddenRecords` | ✅ never reported | |
| Translations of untranslated pages | ❌ checked | ❌ checked | ❌ checked | 📋 step 7: not reported, shown as skipped |
| Workspaces | ❌ live only | 🟡 live only, `doNotCheckLinksOnWorkspace` | ❌ live only, column prepared | 📋 later |
| **Checking internal targets** | | | | |
| Page missing / deleted / hidden | ✅ | ✅ | ✅ | |
| Page expired (endtime) | ❌ | ❌ | ✅ | |
| Page without frontend (folder, spacer) | ❌ | ❌ | ✅ | |
| Content anchor `#c123` | ✅ | ✅ | ❌ | 📋 step 8 |
| Target not available in the language of the source | ❌ | ❌ | ❌ | 📋 step 7 (site fallback chain) |
| Record links (`t3://record`) | ✅ | ✅ | ✅ missing / hidden / expired | |
| Files / folders | ✅ files | ✅ files | ✅ files and folders, offline storage = unknown | |
| Unresolvable `t3://` links | 🟡 | 🟡 | ✅ reported as invalid | |
| **Checking external URLs** | | | | |
| External checking | 🟡 off by default | ✅ | ❌ extracted, not checked yet | 📋 step 2 |
| Cache across runs | ❌ only within one run | ✅ link target cache with expiry | 🟡 target table exists | 📋 step 2: TTL |
| HEAD with GET fallback, redirects | ✅ | ✅ | ❌ | 📋 step 2 |
| 401/403/429/5xx/timeouts as "unknown" instead of broken | ❌ | ❌ | ❌ | 📋 step 2, escalation after 3 checks / 7 days |
| Crawl delay per domain, `Retry-After` | ❌ | ✅ crawl delay | ❌ | 📋 step 2 |
| **Exclusions** | | | | |
| Exclude link targets (URL / domain) | ❌ | ✅ records + own module | ❌ | 📋 step 5: records, tab in module, custom permission |
| Accept a single occurrence | ❌ | ❌ | ❌ | 📋 later |
| **Running checks** | | | | |
| Scheduler | ✅ own scheduler task | ✅ schedulable command | ❌ | 📋 task 8: command via "Execute console commands" |
| CLI command | ❌ | ✅ `brofix:checklinks` | ✅ `--record`, `--page`, `--depth` | 📋 task 8: queue with `--limit` / `--time-limit` |
| Interactive check of large trees without timeouts | ❌ synchronous | ❓ not verified | ❌ synchronous | 📋 browser batches with progress + time budget |
| Recheck on save | 🟡 when returning to the module (`actionAfterEditRecord`) | 🟡 when returning to the module | ❌ | 📋 step 4: DataHandler, internal immediately, external from cache |
| Clean up on delete / move | ❌ | ✅ delete | ❌ | 📋 step 4 |
| Results kept between runs | ❌ list is rebuilt | ✅ updated | ✅ only changed records rewritten, `first_detected` kept | |
| **Backend module** | | | | |
| Placement | Link Management | own module | ✅ Link Management | |
| Depth selector | ✅ | ✅ | ✅ provisional | 💭 to be reconsidered |
| Sort / filter / paginate | ❌ | ✅ | ❌ | 📋 module step |
| Edit record from the report | ✅ | ✅ | ✅ | |
| Recheck a single URL | ❌ | ✅ | ❌ | 💭 |
| "Broken since" | ❌ | ❌ | ✅ | |
| Which records were checked | ❌ | ❌ | ❌ only a count | 📋 module step |
| Access for editors | ✅ filtered by permissions | ✅ | ❌ admins only | 📋 only what the user may fix (pages, tables, languages, fields) |
| **Visibility elsewhere** | | | | |
| Markers in the rich text editor | 🟡 page missing/hidden, file, known external | 🟡 similar | ✅ same checkers as the report, external from cache | |
| Page module callout | ❌ | ✅ with EXT:page_callouts | ❌ | 📋 later |
| Dashboard widget | ❌ | ❌ | ❌ | 💭 |
| **Reporting** | | | | |
| E-mail report | ✅ full list after each run | ✅ | ❌ | 📋 later: changes since last report, per site |
| **Configuration** | | | | |
| Where | Page TSconfig + scheduler task field | Page TSconfig + extension configuration | extension configuration | 📋 global (extension configuration) + per site (site settings), no Page TSconfig |
| **Extensibility** | | | | |
| Custom link types | ✅ `LinktypeInterface` | ✅ link types | ✅ tagged checkers per `LinkService` type | |
| PSR-14 events | ✅ 2 events | 🟡 | ❌ | 📋 later, only where needed |

Sources: core `typo3/sysext/linkvalidator` (code and `page.tsconfig` on `main`), brofix
`backup/brofix` (README, documentation, code). Cells marked 🟡 summarise behaviour that
differs in detail; check the code before relying on them.
