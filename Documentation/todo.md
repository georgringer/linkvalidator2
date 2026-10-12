# Documentation – to do

Notes for the documentation (`Documentation/*.rst`) that is written once the extension
replaces the core linkvalidator. Collect here what needs to be explained, so nothing is
forgotten.

## For editors

- Checking from the module: levels in the doc header define what is checked, the levels in
  the filter form what is listed. Progress bar, cancel, summary with new broken links.
- Status "unknown" (external URL could not be checked reliably: 401/403/429/5xx, TLS,
  timeout) is not "broken".
- Status "notice": the target is reachable but restricted – only for logged-in users
  (fe_group), visible from a start time, visible until a stop time, also when set on a
  parent page with "extend to subpages". Typical case: the target page was changed after
  linking it. Notices are listed with their own badge and can be filtered by status;
  they are not counted as broken links.
- Links in hidden or expired records are never reported.
- Anchors (`#…`) are not checked, only the page they point to. A link to a removed
  content element (`#c123`) is therefore not reported (unlike the old linkvalidator).
- A link page (doktype "Link") pointing to the current page links to itself and is
  reported as broken.
- Exclusions: "Ignore" on a result excludes its URL, its domain or its target page for the
  page of the record and its subpages; the result disappears. The view "Exclusions"
  (button in the doc header) lists them with levels, to edit, hide or delete them, and
  shows the ones inherited from pages above. Hiding or deleting one makes the links
  checked again by the next background run.

## For integrators

- Editors get the module through the backend group (module "Check Links"). They see only
  broken links they may fix: edit permission on the page (page fields) or on its content
  (other records), tables to modify, exclude fields, authMode values and languages. Every
  editor may start a check; all records are checked, the report stays filtered.

- Saving in the backend checks the saved records right away: internal links immediately,
  external URLs from the cache. Records with external URLs not checked yet become due for
  the next background run. Deleting removes the results. Only the live workspace is
  checked, publishing counts as saving.
- Changing a record (e.g. hiding or deleting a page) makes the records linking to it due,
  found through the reference index: the next background run or "Check links" reports the
  link. Keep the reference index up to date after imports around the DataHandler.

- Extension configuration: `excludedFields`, `externalCacheLifetime`,
  `externalTimeout`, `externalUserAgent`, `allowManualCheck`.
- No Page TSconfig any more (`mod.linkvalidator.*`), migration table in the breaking
  changelog.
- Message queue: `linkvalidator2:fillqueue <page>` fills the core's messenger queue, `messenger:consume
  doctrine` works it off (cron or systemd, not the scheduler without `--time-limit`); messages are routed
  to `doctrine` by `ext_localconf.php`, route to `default` to check synchronously; records of `pid = 0`
  are not checked; several workers are possible, per-domain politeness is not solved yet.
- Scheduler: task "Execute console commands" with `linkvalidator2:check` and e.g.
  `--time-limit=300`. Each run checks records never checked first, then records marked due
  (saved with external URLs not checked yet), then records checked longer ago than
  `--interval` hours (default 24). `--limit` caps the number of records. Overlapping runs
  are prevented by a lock. Results of deleted pages are cleaned up at the start.
- Staging with a database synced from live: do not set up the scheduler cron there, or
  send mails to a catcher (`MAIL.transport` per environment, e.g. Mailpit or `null`).
  Mail report recipients can come from an environment variable (`%env()%` in site
  settings). No own switch (Forge #110615).
- `robots.txt` is not respected on purpose: single HEAD requests to linked URLs, not a
  crawler (Forge #89287).

- Exclusions are records on a page and apply to its subtree, on the root level to all
  pages (admins only). Types: "URL or domain" – everything starting with the value is
  excluded: `example.org` the domain incl. subdomains, `example.org/blog` everything below
  it; scheme and anchor do not matter –, "page" (links to one page) and "all links"
  (turns checking off for the subtree, e.g. an archive). Editors need "tables_modify" for
  `tx_linkvalidator2_exclusion` and content edit permission on the page. They can have a
  start and stop time.

## For developers

### Add an exclusion type

Implement `ExclusionMatcherInterface` (`getType()` = value of the field `type`,
`matches(Exclusion, LinkTarget)`); it is tagged automatically. Add the item to the field
`type` and a TCA type with its fields to `tx_linkvalidator2_exclusion`, e.g. a regular
expression. The scope (page and subpages) is handled for every type.

### Check a custom link type (Forge #94086)

Links of a link handler registered in `$GLOBALS['TYPO3_CONF_VARS']['SYS']['linkHandler']`
are found in all link and rich text fields without further configuration – no soft
reference parser, no TSconfig.

- Without a checker for the type, its links are skipped: neither checked nor reported.
- With a checker they are checked. A checker implements `LinkCheckerInterface` and returns
  the link types it handles; it is tagged automatically (autoconfiguration of the
  interface, `Configuration/Services.php`), so a class in an extension with the usual
  `Services.yaml` (`autoconfigure: true`) is enough:

```php
final readonly class ProductLinkChecker implements LinkCheckerInterface
{
    public function getSupportedTypes(): array
    {
        return ['product'];
    }

    public function check(LinkTarget $target, LinkCandidate $source): CheckResult
    {
        // $target->resolved is what the link handler's resolveHandlerData() returned
        return $this->productExists((int)$target->resolved['uid'])
            ? CheckResult::ok()
            : CheckResult::broken('product.missing', ['uid' => $target->resolved['uid']]);
    }
}
```

- `CheckResult::broken()` / `unknown()` take an error type and details. The module shows
  the label `error.<errorType>` of `messages.xlf`, falling back to the error type – a
  way for extensions to provide their own labels is still missing (see below).
- The detail `message` (string) is shown below that label, collapsed ("Details"), for every
  link type – e.g. the cURL message of external URLs. Put the technical cause there, not
  in the label.
- Expensive checks (HTTP) implement `CacheableLinkCheckerInterface`: results are stored per
  target and reused for `getCacheLifetime()` seconds, and once per run regardless.
- A checker registered for a core type (e.g. `url`) replaces the core checker – the last
  registered checker for a type wins.
- Test: `Tests/Functional/Target/CustomLinkHandlerTest.php` with the fixture extension
  `Tests/Functional/Fixtures/Extensions/test_link_handler`.

### How page links are checked

Whether a visitor gets a page is decided by the frontend's own page resolution
(`PageInformationFactory`, as for a request, but without rendering): hidden pages and
rootline, page types, shortcuts, languages and access – including listeners of its events
(`AfterPageWithRootLineIsResolvedEvent`, …), so extensions changing page access are
respected. The page must belong to a site.

### Check pages through the frontend (idea)

The page checker decides from the database (or the frontend's page resolution without
rendering, see Notes "Frontend-Wahrheit"). Anchors (`#…`) are not checked: a fragment can be
any id of the rendered page, the target page is checked and the fragment ignored. Whoever
needs the rendered result – e.g. anchors that only exist in the HTML – can register a
checker for the type `page` that requests the frontend over HTTP, like cs_seo's `FrontendPageService` (`PreviewUriBuilder`,
`RequestFactory`, forwarding basic auth). Document as an example once decided.

### Labels in JavaScript

`check-links.js` imports its labels as `~labels/linkvalidator2.messages` and formats them
with ICU MessageFormat (`{count, plural, one {…} other {…}}`), no labels in data attributes.
On the core move the domain becomes `linkvalidator.messages`.

### Open for extensibility

- Labels of custom error types: let `CheckResult` carry a label key, or look up
  `error.<errorType>` in a translation domain of the checker's extension.
- Identifier of custom targets is `<type>:<link>` as written in the field, so the same
  target written differently (parameter order) is cached twice. Option: an interface for
  checkers to normalize the identifier.
- PSR-14 events: decide which are needed (e.g. modify extracted links, modify a result,
  after a record was checked). Only where a real use case exists.
- Additional link sources (FlexForms, custom field types): `CheckableFieldResolver` and
  `ExtractionMode` are internal for now.
