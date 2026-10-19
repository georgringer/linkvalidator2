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
- Stores the result once per link target (`target`), the places that link to it
  (`broken_link`, only for problems), and a check status per record. The status table doubles as
  the work queue: never checked records first, then the least recently checked ones.
- Shows the result in a backend module below *Link Management* and marks broken links
  in the rich text editor.

## How it works

How a check is started, what `checkRecord` does with a record, the chain of checkers and the
tables (German, with an example): [flow diagrams](https://claude.ai/artifact/VSeBbLmu9wByDuNBZ4WvDs).

## Usage

```bash
# check one record, print every link and its result
bin/typo3 linkvalidator2:check --record=tt_content:471
# check all records of a page and its subpages right away, in this process (prints the progress)
bin/typo3 linkvalidator2:check --page=1287 --depth=99
# message queue: queue a page tree, a worker checks it (several workers may run in parallel)
bin/typo3 linkvalidator2:fillqueue 1287
bin/typo3 messenger:consume linkvalidator2_priority doctrine --time-limit=240
# mail the broken links of an area, here only those broken for a week already, with all of them as CSV
bin/typo3 linkvalidator2:report 1287 --depth=99 --to=editor@example.org --min-age="7 days" --content=both
```

`fillqueue` walks the page tree below the page, level by level, and queues the records of every
page in messages of `--chunk-size` records (25). Queueing a record twice is harmless. Call it once
per area with its own rhythm; overlapping areas only cost time. Records without a page (`pid = 0`)
are not checked. The messages are routed to the `doctrine` transport, set
`$GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing']` to `default` to check synchronously.
Records that became due (saved with URLs not checked yet, changed link targets, removed exclusions) go to the transport `linkvalidator2_priority`: a worker started as above takes them before the regular queue.
`messenger:consume` blocks the scheduler while it runs: use `--time-limit` below the cron interval
or run it as a service.

Backend: *Link Management → Check Links (new)* (editors see what they may fix), select a page,
choose the levels and press *Check*.

Configuration (extension configuration): `excludedFields` – comma-separated
`table.field` or `table.*` that are never checked; `externalCacheLifetime` (hours),
`externalTimeout` (seconds) and `externalUserAgent` for external URLs;
`allowManualCheck` (default on) – switch off to hide the check buttons in the module
and deny the AJAX endpoints, e.g. when links are only checked by the command.

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
| Workspaces | ❌ live only, module not available in a workspace | 🟡 live only, `doNotCheckLinksOnWorkspace` | ❌ live only like the core, module not available in a workspace, columns prepared | 📋 **required before it may replace the core linkvalidator**, not optional: the records changed, created or deleted in a workspace, no moves in the first step (see `Notes/2026-10-12-workspaces.md`) | |
| **Checking internal targets** | | | | | |
| Page missing / deleted / hidden | ✅ | ✅ | ✅ | | |
| Target changed after linking (page hidden, deleted, …) | ❌ only on the next full run | ❓ | ✅ records referencing a changed record (reference index) become due and are queued with priority (a worker takes them before the regular queue), or checked by "Check links"; subpages via `extendToSubpages` only by the regular run | | |
| Shortcut / link page whose target is not reachable | ❌ | ❓ | ✅ `page.targetNotReachable` (frontend resolution) | | #92542 |
| Page hidden / expired via a parent with `extendToSubpages` | ❌ | ❓ | ✅ decided by the frontend's page resolution (`PageInformationFactory`, without rendering) | | #106675, #101367 |
| Restricted or scheduled target (fe_group, start time, future stop time, also via parent) | ❌ | ❓ | ✅ reported as notice, not as broken; listed with a badge, status filter | | #106675 |
| Status "notice" for reachable targets with restrictions | ❌ | ❓ | ✅ own status next to broken / cannot be checked, not counted as broken | 📋 more notices (language fallback) | |
| Page expired (endtime) | ❌ | ❌ | ✅ | | |
| Page without frontend (folder, spacer) | ❌ | ❌ | ✅ | | |
| Content anchor `#c123` | ✅ assumes `#c<uid>` is a content element | ✅ | ❌ by decision: the target page is checked, the fragment ignored – an anchor can be any id (template, plugin, JS); register an own `page` checker to check the rendered HTML | | #100933 |
| Link to the current page (`t3://page?uid=current`, also with anchor) | ❌ softref parser skips it | ❓ | ✅ valid from content (anchors are not checked); a link page pointing to itself is broken `page.linksToItself` | 📋 revisit if the core drops `current` | #100933 |
| Target not available in the language of the source | ❌ | ❌ | ❌ | 📋 step 7 (site fallback chain) | |
| Record links (`t3://record`) | ✅ | ✅ | ✅ missing / hidden / expired, table via `TCEMAIN.linkHandler` (fallback: identifier = table) | | |
| Files / folders | ✅ files | ✅ files | ✅ files and folders, offline storage = unknown | | |
| Empty link (`<a href="">`, e.g. from the RTE for a missing file) | ❌ | ❓ | ✅ reported once per field as broken `link.empty`; anchors without `href` are ignored | | |
| Unresolvable `t3://` links | 🟡 | 🟡 | ✅ reported as invalid | | |
| **Checking external URLs** | | | | | |
| External checking | 🟡 off by default | ✅ | ✅ CLI and module; each URL requested once per run | | |
| Cache across runs | ❌ only within one run | ✅ link target cache with expiry | ✅ per target, configurable lifetime (default 7 days), cache-only mode, checkbox "Request external URLs again" in the module to ignore it for one check | | #92700 |
| HEAD with GET fallback, redirects | ✅ | ✅ | ✅ GET on 405/501, max. 5 redirects; a hop sent by a TYPO3 redirect (header `X-Redirect-By`) is reported as notice `url.redirectedByTypo3` if the end is reachable; redirect and its target are shown in the details, also when the end is broken | | |
| 401/403/429/5xx/999/TLS/timeouts as "unknown" instead of broken | ❌ | ❌ | ✅ broken only 404, 410, unknown host; status filter; cURL error from Guzzle 7 and 8; timeout message names the seconds | 📋 escalation after 3 checks / 7 days, optionally show "since n days" in the report (columns `failure_count`/`first_failed_at` are already kept) | #96525, #85006 |
| Full error message (e.g. cURL TLS details) | 🟡 one localised text per cURL code | ❓ | ✅ short localised text, the cURL message in a "Details" modal (target, HTTP status, error code, message), `errno` stored; `details.message` shown for every checker | | #101935 |
| Polite crawling: own User-Agent | ✅ configurable | ✅ | ✅ `externalUserAgent` | | #89287 |
| Polite crawling: crawl delay per domain, `Retry-After` | ❌ | ✅ crawl delay | ❌ | 📋 later | #89287 |
| `robots.txt` | ➖ | ➖ | ➖ single requests to linked URLs, not a crawler: disallowed paths would be reported as "unknown" although they work; politeness comes from the user agent, the cache and the delay per domain | | #89287 |
| **Exclusions** | | | | | |
| Exclude link targets (URL / domain / page) | ❌ | ✅ records + own module | ✅ records `tx_linkvalidator2_exclusion` on a page, apply to its subtree (root level: everywhere); types "URL or domain" (everything starting with it, incl. subdomains, scheme and anchor ignored), page and "all links" (no check in the subtree, e.g. an archive); excluded links are not checked (no HTTP request) and not stored | 📋 site-wide exclusions as site setting (deployed with the project); regex only as own matcher | #85127, #92822 |
| "Ignore" from the report | ❌ | ❓ | ✅ per result: URL, its domain or the target page, stored on the page of the record through the DataHandler (permissions apply), results disappear right away | | #92822 |
| Count of links skipped by exclusions | ❌ | ❓ | ✅ stored per record (`excluded_count`), summed in the report for the selected pages with a link to the exclusions; a new exclusion removes the results it matches directly (database prefilter on the link string, then the matcher), without checking, so it is fast on large sites; links that were fine are counted by the next check of their record | | |
| List of exclusions | ❌ | ✅ own module | ✅ view in the module (toggle in the doc header): record list with levels, selection, edit/hide/delete, plus the exclusions inherited from pages above | | #92822 |
| Custom exclusion types | ❌ | ❌ | ✅ `ExclusionMatcherInterface` (tagged automatically) + TCA type | | |
| **Running checks** | | | | | |
| Scheduler | ✅ own scheduler task | ✅ schedulable command | ✅ `linkvalidator2:fillqueue <page>` via "Execute console commands" (one entry per area), the worker runs as cron or service | | |
| Message queue (parallel workers) | ❌ | ❌ | ✅ `linkvalidator2:fillqueue <page>` queues a page tree (level by level, in messages of `--chunk-size` records, vanished records cleaned up), `messenger:consume doctrine` checks them; several workers possible, large pages are split | 📋 `--only-if-empty`, per-domain politeness with several workers, hook queues instead of checking | #89266 |
| CLI command | ❌ | ✅ `brofix:checklinks` | ✅ `linkvalidator2:check --record` / `--page` `--depth` `--no-external` checks right away in the process and prints the progress (page tree level by level, vanished records cleaned up); the background run is the message queue | | |
| Link types to check | 🟡 TSconfig `linktypes`, report shows stale results of the other types | ✅ | ✅ always all links of a record, results replaced per record; link type is a report filter | | #92586 |
| Interactive check of large trees without timeouts | ❌ synchronous | ❓ not verified | ✅ browser checks page by page via AJAX (5 s budget per request, a page continues in the next request), progress bar, cancel, summary notification with new broken links | | #89266 |
| Dashboard widget | ❌ | ❌ | ✅ optional (only with EXT:dashboard, registered in `Configuration/Services.php`): the broken links below a page by type of link (external URLs, pages, files …) with their number, every type linking to the report filtered by it, plus a button to the report, both only for users with access to the module; page and levels are settings of every instance of the widget; counts only what the user may fix | | |
| Disable the interactive check | ❌ | ❓ | ✅ extension configuration `allowManualCheck`: buttons hidden, `check/start` and `check/page` answer 403; the command is not affected | | |
| Accessible progress | ❌ | ❓ | ✅ live region (`role="status"`, polite) announces start, every 10 % and cancelling; focus moves to "Cancel" while running; notifications are polite live regions of the core | | |
| Report during a running check / parallel checks | ❌ list emptied first, duplicates | ❓ | 🟡 previous results stay visible, report reloads when done; parallel runs not locked | 📋 transaction in `replaceForRecord()` | #89266 |
| Recheck on save | 🟡 when returning to the module (`actionAfterEditRecord`) | 🟡 when returning to the module | ✅ DataHandler hook (save, new, move, restore, copy, localize, publish): internal immediately, external from cache, records with unchecked URLs become due and are queued with priority | 📋 records linking to a page whose visibility changed are not rechecked | #105911 |
| Clean up on delete / move | ❌ deleted pages stay forever | ✅ delete | ✅ DataHandler: delete removes results (incl. translations, everything below a deleted page), move updates the page; checking a page reconciles changes made around the DataHandler | 📋 orphan cleanup in the queue run | #89426 |
| Record hidden after the check | ❌ stays in the report | ❓ | ✅ removed when saved; an end time passing removes it with the next check | | #105911 |
| Results kept between runs | ❌ list is rebuilt | ✅ updated | ✅ only changed records rewritten, `first_detected` kept | | |
| **Backend module** | | | | | |
| Placement | Link Management | own module | ✅ Link Management | | |
| Depth selector | ✅ | ✅ | ✅ separate levels for checking (doc header, next to "Check links", no reload) and listing (filter form) | | |
| Filter (status, link type, table, language, text) | 🟡 link type only | ✅ | ✅ | | #96447 |
| Language of a result | ❌ | ❓ | ✅ flag with the name of the language as tooltip next to the record, of the site the page of the record belongs to; only for sites with several languages ("all languages" has its own icon) | | |
| Paginate | ❌ | ✅ | ✅ 50 per page, in SQL | | #89454 |
| Sort | ❌ | ✅ | ✅ by every column | | #96447 |
| Edit record from the report | ✅ | ✅ | ✅ | | |
| Open the link target from the report | ❓ | ❓ | ✅ new tab: external URL, page in the frontend preview (hidden/scheduled pages included), file by its public URL | | |
| Recheck a single URL | ❌ | ✅ | ✅ button "Check this link again" next to "Ignore" in every row: checks the record at once (a new request for an external URL, the stored result of the target is skipped) and applies the result to every stored result of the same target, so the report is up to date without checking the other records; the results in the report are replaced without reloading the page and the outcome is shown as a notification; the row is found by record, field and target, not by its uid, which changes whenever a record is checked; off with `allowManualCheck` | | #96447 |
| "First detected" (also for notices and "cannot be checked") | ❌ | ❌ | ✅ | | |
| Empty report explained | ❌ | ❓ | ✅ "not checked yet" vs. "no broken links found" with the time of the last check vs. "nothing matches the filter" | | |
| Which records were checked | ❌ | ❌ | ❌ only a count | 📋 module step | |
| Access for editors | ✅ filtered by permissions | ✅ | ✅ only what the user may fix: page/content edit permission, tables, exclude fields, authMode, languages (SQL, pagination correct) | | #105413, #104004 |
| Links in page properties below a page without edit permission | ❌ hidden: stored pid is the parent page | ❓ | ✅ `pid` of a page is the page itself, edit permission checked on it | | #105413, #105873 |
| `authMode` restrictions | 🟡 type field only, empty list = no restriction | ❓ | ✅ every `select` field with `authMode` (values from `items`) | | #104004 |
| Workspace users | ❌ live users only | ❓ | ❌ `workspaces => live` | 📋 part of the workspace support, which is required, see the row Workspaces | #107983 |
| **Visibility elsewhere** | | | | | |
| Markers in the rich text editor | 🟡 page missing/hidden, file, known external | 🟡 similar | ✅ same checkers as the report, external from cache; page of the edited record from the form request (Page TSconfig `TCEMAIN.linkHandler`), record links to an unknown table never marked | | |
| Page module callout | ❌ | ✅ with EXT:page_callouts | ❌ | 📋 later | |
| **Reporting** | | | | | |
| E-mail report | ✅ full list after each run | ✅ | ✅ `linkvalidator2:report <page>` (via "Execute console commands", one entry per area and recipient): a page with `--depth`, filtered like the module (`--status` broken by default, `--link-type`, `--language`, `--search`, `--include-tables` / `--exclude-tables`) and by the time a link is broken (`--min-age` for a reminder, `--max-age` for the new ones); `--content=inline\|attach\|both` lists the oldest links in the text (`--inline-limit`, 50) and/or attaches all of them with every column as CSV; no mail without links unless `--send-empty`; no state is kept, so a link can be in several mails; the tables lists do not replace permissions | 📋 "changes since last report" (needs a state) | #106487 |
| Site name in the mail, links to edit / to the module | ❌ no absolute backend URLs in CLI | ❓ | ✅ subject "Broken links: <page title>" or `--subject`; absolute links to edit the record and to the module, from the site of the page and a `BE/entryPoint` given as URL (`--base-url` for pages without a site) | | #106487, #106677 |
| Full, clickable target URL in the mail | ❌ cropped to 15 characters | ❓ | ✅ complete and linked in the HTML part, complete in the text part and the CSV | | #106677 |
| **Configuration** | | | | | |
| Where | Page TSconfig + scheduler task field | Page TSconfig + extension configuration | extension configuration | 📋 global (extension configuration) + per site (site settings), no Page TSconfig | #96447 |
| **Extensibility** | | | | | |
| Custom link types | ✅ `LinktypeInterface` | ✅ link types | ✅ checker per `LinkService` type, autoconfigured for every extension; several checkers per type form a chain ordered by tag `priority` (`unknown` and `skip` hand over to the next); links of a registered link handler without checker are skipped | 📋 labels for custom error types, see `Documentation/todo.md` | #94086 |
| PSR-14 events | ✅ 2 events | 🟡 | ❌ | 📋 later, only where needed | |

Sources: core `typo3/sysext/linkvalidator` (code and `page.tsconfig` on `main`), brofix
`backup/brofix` (README, documentation, code). Cells marked 🟡 summarise behaviour that
differs in detail; check the code before relying on them.
