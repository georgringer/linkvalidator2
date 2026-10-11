# Documentation – to do

Notes for the documentation (`Documentation/*.rst`) that is written once the extension
replaces the core linkvalidator. Collect here what needs to be explained, so nothing is
forgotten.

## For editors

- Checking from the module: levels in the doc header define what is checked, the levels in
  the filter form what is listed. Progress bar, cancel, summary with new broken links.
- Status "unknown" (external URL could not be checked reliably: 401/403/429/5xx, TLS,
  timeout) is not "broken".
- Links in hidden or expired records are never reported.

## For integrators

- Extension configuration: `excludedFields`, `externalCacheLifetime`,
  `externalTimeout`, `externalUserAgent`.
- No Page TSconfig any more (`mod.linkvalidator.*`), migration table in the breaking
  changelog.
- Scheduler: "Execute console commands" with `linkvalidator2:check` (once the queue exists).
- `robots.txt` is not respected on purpose: single HEAD requests to linked URLs, not a
  crawler (Forge #89287).

## For developers

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
- Expensive checks (HTTP) implement `CacheableLinkCheckerInterface`: results are stored per
  target and reused for `getCacheLifetime()` seconds, and once per run regardless.
- A checker registered for a core type (e.g. `url`) replaces the core checker – the last
  registered checker for a type wins.
- Test: `Tests/Functional/Target/CustomLinkHandlerTest.php` with the fixture extension
  `Tests/Functional/Fixtures/Extensions/test_link_handler`.

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
