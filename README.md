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
# background run (scheduler): records never checked, due ones, then those older than 24 h
bin/typo3 linkvalidator2:check --time-limit=300 --interval=24
```

Backend: *Link Management → Check Links (new)* (editors see what they may fix), select a page,
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
| Shortcut pages | ❌ | ❌ | ✅ all modes: a selected page is checked as link target, otherwise (first/random subpage, parent page, nothing selected) the frontend's resolution of the shortcut page itself decides | | #92542 |
| Plain-text URLs (softref `url`) | 🟡 `&nbsp;` after a URL becomes part of it | ✅ | ✅ core parser, URL ends at a non-breaking space | | #97937 |
| Link type detection | own `fetchType()` heuristics per link type | own link types | ✅ core `LinkService::resolve()` | | |
| Hidden / expired source records | 🟡 option `checkhidden` | 🟡 option `reportHiddenRecords` | ✅ never reported | | |
| Source hidden by its page (hidden/expired page, `extendToSubpages`) | ❌ | ❓ | ❌ reported | 📋 step 7: not reported, shown as skipped | #96447 |
| Translation whose default language record is hidden | ❌ checked | ❓ | ❌ checked | 📋 step 7 | #94195 |
| Translations of untranslated pages | ❌ checked | ❌ checked | ❌ checked | 📋 step 7: not reported, shown as skipped | |
| Workspaces | ❌ live only | 🟡 live only, `doNotCheckLinksOnWorkspace` | ❌ live only, column prepared | 📋 later | |
| **Checking internal targets** | | | | | |
| Page missing / deleted / hidden | ✅ | ✅ | ✅ | | |
| Target changed after linking (page hidden, deleted, …) | ❌ only on the next full run | ❓ | ✅ records referencing a changed record (reference index) become due, checked by the next run or "Check links"; subpages via `extendToSubpages` only by the regular run | | |
| Shortcut / link page whose target is not reachable | ❌ | ❓ | ✅ `page.targetNotReachable` (frontend resolution) | | #92542 |
| Page hidden / expired via a parent with `extendToSubpages` | ❌ | ❓ | ✅ decided by the frontend's page resolution (`PageInformationFactory`, without rendering) | | #106675, #101367 |
| Restricted or scheduled target (fe_group, start time, future stop time, also via parent) | ❌ | ❓ | ✅ reported as notice, not as broken; listed with a badge, status filter | | #106675 |
| Status "notice" for reachable targets with restrictions | ❌ | ❓ | ✅ own status next to broken / cannot be checked, not counted as broken | 📋 more notices (redirects, language fallback) | |
| Page expired (endtime) | ❌ | ❌ | ✅ | | |
| Page without frontend (folder, spacer) | ❌ | ❌ | ✅ | | |
| Content anchor `#c123` | ✅ assumes `#c<uid>` is a content element | ✅ | ❌ by decision: the target page is checked, the fragment ignored – an anchor can be any id (template, plugin, JS); register an own `page` checker to check the rendered HTML | | #100933 |
| Link to the current page (`t3://page?uid=current`, also with anchor) | ❌ softref parser skips it | ❓ | ✅ valid from content (anchors are not checked); a link page pointing to itself is broken `page.linksToItself` | 📋 revisit if the core drops `current` | #100933 |
| Target not available in the language of the source | ❌ | ❌ | ❌ | 📋 step 7 (site fallback chain) | |
| Record links (`t3://record`) | ✅ | ✅ | ✅ missing / hidden / expired, table via `TCEMAIN.linkHandler` (fallback: identifier = table) | | |
| Files / folders | ✅ files | ✅ files | ✅ files and folders, offline storage = unknown | | |
| Unresolvable `t3://` links | 🟡 | 🟡 | ✅ reported as invalid | | |
| **Checking external URLs** | | | | | |
| External checking | 🟡 off by default | ✅ | ✅ CLI and module; each URL requested once per run | | |
| Cache across runs | ❌ only within one run | ✅ link target cache with expiry | ✅ per target, configurable lifetime (default 7 days), cache-only mode | | #92700 |
| HEAD with GET fallback, redirects | ✅ | ✅ | ✅ GET on 405/501, max. 5 redirects | | |
| 401/403/429/5xx/999/TLS/timeouts as "unknown" instead of broken | ❌ | ❌ | ✅ broken only 404, 410, unknown host; status filter; cURL error from Guzzle 7 and 8; timeout message names the seconds | 📋 escalation after 3 checks / 7 days | #96525, #85006 |
| Full error message (e.g. cURL TLS details) | 🟡 one localised text per cURL code | ❓ | ✅ short localised text, the cURL message collapsed below it ("Details", without URL and documentation link), `errno` stored; `details.message` shown for every checker | | #101935 |
| Polite crawling: own User-Agent | ✅ configurable | ✅ | ✅ `externalUserAgent` | | #89287 |
| Polite crawling: crawl delay per domain, `Retry-After` | ❌ | ✅ crawl delay | ❌ | 📋 later | #89287 |
| `robots.txt` | ❌ | ❌ | ❌ | 💭 single HEAD requests, not a crawler | #89287 |
| **Exclusions** | | | | | |
| Exclude link targets (URL / domain / page) | ❌ | ✅ records + own module | ✅ records `tx_linkvalidator2_exclusion` on a page, apply to its subtree (root level: everywhere); types "URL or domain" (everything starting with it, incl. subdomains, scheme and anchor ignored), page and "all links" (no check in the subtree, e.g. an archive); excluded links are not checked (no HTTP request) and not stored | 📋 site-wide exclusions as site setting (deployed with the project); regex only as own matcher | #85127, #92822 |
| "Ignore" from the report | ❌ | ❓ | ✅ per result: URL, its domain or the target page, stored on the page of the record through the DataHandler (permissions apply), results disappear right away | 📋 "accept this occurrence" (single link) | #92822 |
| List of exclusions | ❌ | ✅ own module | ✅ view in the module (toggle in the doc header): record list with levels, selection, edit/hide/delete, plus the exclusions inherited from pages above | | #92822 |
| Custom exclusion types | ❌ | ❌ | ✅ `ExclusionMatcherInterface` (tagged automatically) + TCA type | | |
| Accept a single occurrence | ❌ | ❌ | ❌ | 📋 later | |
| **Running checks** | | | | | |
| Scheduler | ✅ own scheduler task | ✅ schedulable command | ✅ queue command via "Execute console commands", lock against overlapping runs | | |
| Disable on staging (survives DB sync) | ❌ task lives in the DB | ❓ | ➖ not linkvalidator-specific: mail transport per environment (Mailpit, `null`) or no scheduler cron on staging; mail report recipients via `%env()%` in site settings | 📋 document | #110615 (closed) |
| CLI command | ❌ | ✅ `brofix:checklinks` | ✅ `--record`, `--page`, `--depth`, `--no-external`; without them the queue: never checked, due, older than `--interval` (24 h), with `--limit` / `--time-limit` | 📋 `--site` | |
| Link types to check | 🟡 TSconfig `linktypes`, report shows stale results of the other types | ✅ | ✅ always all links of a record, results replaced per record; link type is a report filter | | #92586 |
| Interactive check of large trees without timeouts | ❌ synchronous | ❓ not verified | ✅ browser checks page by page via AJAX (5 s budget per request, a page continues in the next request), progress bar, cancel, summary notification with new broken links | | #89266 |
| Report during a running check / parallel checks | ❌ list emptied first, duplicates | ❓ | 🟡 previous results stay visible, report reloads when done; parallel runs not locked | 📋 transaction in `replaceForRecord()` | #89266 |
| Recheck on save | 🟡 when returning to the module (`actionAfterEditRecord`) | 🟡 when returning to the module | ✅ DataHandler hook (save, new, move, restore, copy, localize, publish): internal immediately, external from cache, records with unchecked URLs become due | 📋 records linking to a page whose visibility changed are not rechecked | #105911 |
| Clean up on delete / move | ❌ deleted pages stay forever | ✅ delete | ✅ DataHandler: delete removes results (incl. translations, everything below a deleted page), move updates the page; checking a page reconciles changes made around the DataHandler | 📋 orphan cleanup in the queue run | #89426 |
| Record hidden after the check | ❌ stays in the report | ❓ | ✅ removed when saved; an end time passing removes it with the next check | | #105911 |
| Results kept between runs | ❌ list is rebuilt | ✅ updated | ✅ only changed records rewritten, `first_detected` kept | | |
| **Backend module** | | | | | |
| Placement | Link Management | own module | ✅ Link Management | | |
| Depth selector | ✅ | ✅ | ✅ separate levels for checking (doc header, next to "Check links", no reload) and listing (filter form) | | |
| Filter (status, link type, table, language, text) | 🟡 link type only | ✅ | ✅ | | #96447 |
| Paginate | ❌ | ✅ | ✅ 50 per page, in SQL | | #89454 |
| Sort | ❌ | ✅ | ✅ by every column | | #96447 |
| Edit record from the report | ✅ | ✅ | ✅ | | |
| Open the link target from the report | ❓ | ❓ | ✅ new tab: external URL, page in the frontend preview (hidden/scheduled pages included), file by its public URL | | |
| Recheck a single URL | ❌ | ✅ | ❌ | 💭 | #96447 |
| "First detected" (also for notices and "cannot be checked") | ❌ | ❌ | ✅ | | |
| Which records were checked | ❌ | ❌ | ❌ only a count | 📋 module step | |
| Access for editors | ✅ filtered by permissions | ✅ | ✅ only what the user may fix: page/content edit permission, tables, exclude fields, authMode, languages (SQL, pagination correct) | | #105413, #104004 |
| Links in page properties below a page without edit permission | ❌ hidden: stored pid is the parent page | ❓ | ✅ `pid` of a page is the page itself, edit permission checked on it | | #105413, #105873 |
| `authMode` restrictions | 🟡 type field only, empty list = no restriction | ❓ | ✅ every `select` field with `authMode` (values from `items`) | | #104004 |
| Workspace users | ❌ live users only | ❓ | ❌ `workspaces => live` | 📋 module in workspaces, hint "results show the live state" | #107983 |
| **Visibility elsewhere** | | | | | |
| Markers in the rich text editor | 🟡 page missing/hidden, file, known external | 🟡 similar | ✅ same checkers as the report, external from cache; page of the edited record from the form request (Page TSconfig `TCEMAIN.linkHandler`), record links to an unknown table never marked | | |
| Page module callout | ❌ | ✅ with EXT:page_callouts | ❌ | 📋 later | |
| Dashboard widget | ❌ | ❌ | ❌ | 💭 | |
| **Reporting** | | | | | |
| E-mail report | ✅ full list after each run | ✅ | ❌ | 📋 later: changes since last report, per site | |
| Site name in the mail, links to edit / to the module | ❌ no absolute backend URLs in CLI | ❓ | ❌ | 📋 with the mail report: per site, base URL from site / settings | #106487, #106677 |
| Full, clickable target URL in the mail | ❌ cropped to 15 characters | ❓ | ❌ | 📋 with the mail report | #106677 |
| **Configuration** | | | | | |
| Where | Page TSconfig + scheduler task field | Page TSconfig + extension configuration | extension configuration | 📋 global (extension configuration) + per site (site settings), no Page TSconfig | #96447 |
| **Extensibility** | | | | | |
| Custom link types | ✅ `LinktypeInterface` | ✅ link types | ✅ checker per `LinkService` type, autoconfigured for every extension; links of a registered link handler without checker are skipped | 📋 labels for custom error types, see `Documentation/todo.md` | #94086 |
| PSR-14 events | ✅ 2 events | 🟡 | ❌ | 📋 later, only where needed | |

Sources: core `typo3/sysext/linkvalidator` (code and `page.tsconfig` on `main`), brofix
`backup/brofix` (README, documentation, code). Cells marked 🟡 summarise behaviour that
differs in detail; check the code before relying on them.
