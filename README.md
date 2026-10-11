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
`table.field` or `table.*` that are never checked; `externalCacheLifetime` (hours),
`externalTimeout` (seconds) and `externalUserAgent` for external URLs.

Tests run standalone in containers (own `.Build/` with `typo3/cms-core` `dev-main` and
the testing framework, independent of the surrounding core checkout):

```bash
Build/Scripts/runTests.sh -s composerInstall
Build/Scripts/runTests.sh -s unit
Build/Scripts/runTests.sh -s functional            # sqlite, or -d mariadb|mysql|postgres
```

Test data for a local instance: `Notes/seed-testdata.php` (creates pages and content
with every kind of broken link below page 1287) and `Notes/seed-bigtree.php` (a large
tree with ~5,000 content elements, record links and EXT:styleguide records for load tests).

## Feature comparison

Legend: ✅ yes · 🟡 partly · ❌ no · ➖ not done on purpose · ❓ not verified · 📋 planned (step of the rewrite plan, see Notes) · 💭 idea, undecided

The column *Forge* lists the open linkvalidator issues on forge.typo3.org (category
Linkvalidator) the row answers. Analysis per issue: [`Resources/Private/Issues/`](Resources/Private/Issues/),
solved ones in `done/`.

| Feature | linkvalidator (core) | brofix | linkvalidator2 done | linkvalidator2 planned | Forge |
|---|---|---|---|---|---|
| **Finding links** | | | | | |
| Fields to check | explicit list in Page TSconfig `searchFields` | explicit list in TSconfig, only if editable in FormEngine | ✅ derived from the TCA schema, only fields shown for the record type (no `bodytext` of plugins, no `pages.link` of other doktypes) | | #96447, #85006 |
| Opt-out of tables / fields | 🟡 by not listing them | ✅ TSconfig, `excludeCtype` | ✅ extension configuration `excludedFields` | | |
| Links in FlexForms | ❌ | 🟡 FormEngine-based field check | ❌ | 📋 later | |
| File fields (`type=file`, e.g. `pages.media`) | ❌ | ❓ | ➖ by design: references, not links; links *to* files are checked | | #103478 |
| Shortcut pages | ❌ | ❌ | 🟡 `shortcut_mode` "selected page" only | 📋 first/random subpage, parent page | #92542 |
| Plain-text URLs (softref `url`) | 🟡 `&nbsp;` after a URL becomes part of it | ✅ | 🟡 same core parser: `https://example.org&nbsp;` reported as unknown host | 📋 split at `&nbsp;` before parsing | #97937 |
| Link type detection | own `fetchType()` heuristics per link type | own link types | ✅ core `LinkService::resolve()` | | |
| Hidden / expired source records | 🟡 option `checkhidden` | 🟡 option `reportHiddenRecords` | ✅ never reported | | |
| Source hidden by its page (hidden/expired page, `extendToSubpages`) | ❌ | ❓ | ❌ reported | 📋 step 7: not reported, shown as skipped | #96447 |
| Translation whose default language record is hidden | ❌ checked | ❓ | ❌ checked | 📋 step 7 | #94195 |
| Translations of untranslated pages | ❌ checked | ❌ checked | ❌ checked | 📋 step 7: not reported, shown as skipped | |
| Workspaces | ❌ live only | 🟡 live only, `doNotCheckLinksOnWorkspace` | ❌ live only, column prepared | 📋 later | |
| **Checking internal targets** | | | | | |
| Page missing / deleted / hidden | ✅ | ✅ | ✅ | | |
| Page hidden / expired via a parent with `extendToSubpages` | ❌ | ❓ | ❌ | 📋 rootline in `PageChecker` | #106675, #101367 |
| Page expired (endtime) | ❌ | ❌ | ✅ | | |
| Page without frontend (folder, spacer) | ❌ | ❌ | ✅ | | |
| Content anchor `#c123` | ✅ | ✅ | ❌ | 📋 step 8 | |
| Anchor on the current page (`t3://page?uid=current#c123`) | ❌ softref parser skips it | ❓ | ❌ skipped | 📋 step 8: resolve `current` from the source | #100933 |
| Target not available in the language of the source | ❌ | ❌ | ❌ | 📋 step 7 (site fallback chain) | |
| Record links (`t3://record`) | ✅ | ✅ | ✅ missing / hidden / expired, table via `TCEMAIN.linkHandler` (fallback: identifier = table) | | |
| Files / folders | ✅ files | ✅ files | ✅ files and folders, offline storage = unknown | | |
| Unresolvable `t3://` links | 🟡 | 🟡 | ✅ reported as invalid | | |
| **Checking external URLs** | | | | | |
| External checking | 🟡 off by default | ✅ | 🟡 CLI; module with 20 s time budget per "Check", rest from cache | 📋 module with browser batches | |
| Cache across runs | ❌ only within one run | ✅ link target cache with expiry | ✅ per target, configurable lifetime (default 7 days), cache-only mode | | #92700 |
| HEAD with GET fallback, redirects | ✅ | ✅ | ✅ GET on 405/501, max. 5 redirects | | |
| 401/403/429/5xx/999/TLS/timeouts as "unknown" instead of broken | ❌ | ❌ | ✅ broken only 404, 410, unknown host; status filter | 📋 escalation after 3 checks / 7 days | #96525, #85006 |
| Full error message (e.g. cURL TLS details) | 🟡 one localised text per cURL code | ❓ | 🟡 stored in `error_details`, not shown | 📋 show details, store `errno` + `ssl_verify_result` | #101935 |
| Polite crawling: own User-Agent | ✅ configurable | ✅ | ✅ `externalUserAgent` | | #89287 |
| Polite crawling: crawl delay per domain, `Retry-After` | ❌ | ✅ crawl delay | ❌ | 📋 later | #89287 |
| `robots.txt` | ❌ | ❌ | ❌ | 💭 single HEAD requests, not a crawler | #89287 |
| **Exclusions** | | | | | |
| Exclude link targets (URL / domain) | ❌ | ✅ records + own module | ❌ | 📋 step 5: records, tab in module, custom permission | #85127, #92822 |
| Accept a single occurrence | ❌ | ❌ | ❌ | 📋 later | |
| **Running checks** | | | | | |
| Scheduler | ✅ own scheduler task | ✅ schedulable command | ❌ | 📋 task 8: command via "Execute console commands" | |
| Disable on staging (survives DB sync) | ❌ task lives in the DB | ❓ | ❌ | 📋 mail report per site: recipients via `%env()%`, global switch in extension configuration | #110615 |
| CLI command | ❌ | ✅ `brofix:checklinks` | ✅ `--record`, `--page`, `--depth`, `--no-external` | 📋 task 8: queue with `--limit` / `--time-limit` | |
| Link types to check | 🟡 TSconfig `linktypes`, report shows stale results of the other types | ✅ | ✅ always all links of a record, results replaced per record; link type is a report filter | | #92586 |
| Interactive check of large trees without timeouts | ❌ synchronous | ❓ not verified | 🟡 synchronous, time budget for external URLs | 📋 browser batches with progress + time budget | #89266 |
| Report during a running check / parallel checks | ❌ list emptied first, duplicates | ❓ | 🟡 previous results stay visible; parallel runs not locked | 📋 batches, transaction in `replaceForRecord()` | #89266 |
| Recheck on save | 🟡 when returning to the module (`actionAfterEditRecord`) | 🟡 when returning to the module | ❌ | 📋 step 4: DataHandler, internal immediately, external from cache | #105911 |
| Clean up on delete / move | ❌ deleted pages stay forever | ✅ delete | ❌ known bug: results of deleted/moved records stay, even after rechecking the page | 📋 reconcile on page check + step 4 (DataHandler, orphan cleanup) | #89426 |
| Record hidden after the check | ❌ stays in the report | ❓ | 🟡 removed on the next check of the record | 📋 step 4 (DataHandler) | #105911 |
| Results kept between runs | ❌ list is rebuilt | ✅ updated | ✅ only changed records rewritten, `first_detected` kept | | |
| **Backend module** | | | | | |
| Placement | Link Management | own module | ✅ Link Management | | |
| Depth selector | ✅ | ✅ | ✅ same scope for checking and listing | | |
| Filter (status, link type, table, language, text) | 🟡 link type only | ✅ | ✅ | | #96447 |
| Paginate | ❌ | ✅ | ✅ 50 per page, in SQL | | #89454 |
| Sort | ❌ | ✅ | ✅ by every column | | #96447 |
| Edit record from the report | ✅ | ✅ | ✅ | | |
| Recheck a single URL | ❌ | ✅ | ❌ | 💭 | #96447 |
| "Broken since" | ❌ | ❌ | ✅ | | |
| Which records were checked | ❌ | ❌ | ❌ only a count | 📋 module step | |
| Access for editors | ✅ filtered by permissions | ✅ | ❌ admins only | 📋 only what the user may fix (pages, tables, languages, fields) | #105413 |
| Links in page properties below a page without edit permission | ❌ hidden: stored pid is the parent page | ❓ | ✅ data model: `pid` of a page is the page itself | 📋 with permissions: collect the tree first, then filter | #105413, #105873 |
| `authMode` restrictions | 🟡 type field only, empty list = no restriction | ❓ | ❌ admins only | 📋 with permissions: every `select` field with `authMode` | #104004 |
| Workspace users | ❌ live users only | ❓ | ❌ `workspaces => live` | 📋 module in workspaces, hint "results show the live state" | #107983 |
| **Visibility elsewhere** | | | | | |
| Markers in the rich text editor | 🟡 page missing/hidden, file, known external | 🟡 similar | 🟡 same checkers as the report, external from cache; record links with a mapped identifier wrongly marked (no page in the event) | 📋 fix | |
| Page module callout | ❌ | ✅ with EXT:page_callouts | ❌ | 📋 later | |
| Dashboard widget | ❌ | ❌ | ❌ | 💭 | |
| **Reporting** | | | | | |
| E-mail report | ✅ full list after each run | ✅ | ❌ | 📋 later: changes since last report, per site | |
| Site name in the mail, links to edit / to the module | ❌ no absolute backend URLs in CLI | ❓ | ❌ | 📋 with the mail report: per site, base URL from site / settings | #106487, #106677 |
| Full, clickable target URL in the mail | ❌ cropped to 15 characters | ❓ | ❌ | 📋 with the mail report | #106677 |
| **Configuration** | | | | | |
| Where | Page TSconfig + scheduler task field | Page TSconfig + extension configuration | extension configuration | 📋 global (extension configuration) + per site (site settings), no Page TSconfig | #96447 |
| **Extensibility** | | | | | |
| Custom link types | ✅ `LinktypeInterface` | ✅ link types | 🟡 tagged checkers per `LinkService` type, but links of a custom link handler are reported as invalid even with a checker | 📋 generic target for types with a checker, skip handler types without one | #94086 |
| PSR-14 events | ✅ 2 events | 🟡 | ❌ | 📋 later, only where needed | |

Sources: core `typo3/sysext/linkvalidator` (code and `page.tsconfig` on `main`), brofix
`backup/brofix` (README, documentation, code). Cells marked 🟡 summarise behaviour that
differs in detail; check the code before relying on them.
