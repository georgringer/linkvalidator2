# Open Forge issues of linkvalidator

All open issues of the category *Linkvalidator* on forge.typo3.org (28, as of
2026-10-11), analysed against linkvalidator2. One file per issue: problem, analysis
against the current code, what is left to do, test idea (German, like `Notes/`).

Solved issues – implemented or obsolete by design – are in [`done/`](done/). When an
issue is solved, move its file there and update the status line, this index and the
*Forge* column of the feature comparison in the extension `README.md`.

Source: https://forge.typo3.org/projects/typo3cms-core/issues?set_filter=1&f[]=status_id&op[status_id]=o&f[]=category_id&op[category_id]==&v[category_id][]=1493

## Open

| Issue | Subject | Status linkvalidator2 |
|---|---|---|
| [#110615](110615-context-dependent-execution.md) | Disable the link validator in certain contexts, or run it only in certain contexts | 📋 offen (Mail-Report, Ausführung) |
| [#107983](107983-workspace-users.md) | Make Link Validator (linkvalidator) accessible for Preview-Workspace users | 📋 offen (Berechtigungen, Workspaces) |
| [#106677](106677-mail-report-clickable-urls.md) | Improve Linkvalidator broken URL output in E-Mail Report | 📋 offen (Mail-Report, später) |
| [#106675](106675-page-hidden-via-rootline.md) | Checking links to pages does not check if page is hidden via rootline (e.g. if child of hidden=1 and extendToSubpages=1) | ❌ offen |
| [#106487](106487-mail-report-template.md) | Improve linkvalidator report mail template | 📋 offen (Mail-Report, später) |
| [#105911](105911-hide-results-for-hidden-records.md) | Linkvalidator should hide results for hidden records | 🟡 teilweise |
| [#105413](105413-permissions-page-attributes.md) | Linkvalidator BE-Module wrong permissions for invalid links in page attributes | 🟡 Ursache im Datenmodell gelöst, Berechtigungen 📋 offen |
| [#104004](104004-authmode-permissions.md) | Incorrect handling of authMode in EditableRestriction in linkvalidator | 📋 offen (Berechtigungen) |
| [#101935](101935-curl-error-codes.md) | Better handling of curl error codes in linkvalidator | 🟡 teilweise |
| [#101367](101367-page-child-of-hidden-extend-to-subpages.md) | page link to not hidden child of hidden page with extendToSubpages is not considered broken (linkvalidator) | ❌ offen |
| [#100933](100933-current-page-missing-anchor.md) | Checking internal links to current page with missing anchor is not reported as broken link | 📋 offen (Schritt 8: Anker) |
| [#96447](96447-concept-and-open-decisions.md) | Linkvalidator concept & open decisions | 🟡 größtenteils durch den Neubau beantwortet |
| [#94195](94195-translation-of-hidden-original.md) | Translated (connected mode) content will still get checked by linkvalidator if original content is hidden | 📋 offen (Schritt 7: Sprachregel) |
| [#92822](92822-ignore-button.md) | Ignore button for link targets | 📋 offen (Schritt 5: Ziel-Ausschlüsse) |
| [#92542](92542-shortcut-pages.md) | Linkvalidator should check shortcut pages | 🟡 teilweise |
| [#89426](89426-remove-stale-broken-links.md) | Remove stale broken links in tx_linkvalidator_link | 🟡 teilweise – Datensätze ✅, gelöschte Seite selbst ❌ |
| [#89287](89287-polite-crawling.md) | Make linkvalidator crawling polite | 🟡 teilweise |
| [#89266](89266-concurrent-checks-feedback.md) | Prevention of running link checking more than once and visible feedback about still running checks | 🟡 teilweise – Feedback ✅, Parallelität 📋 |
| [#85127](85127-exclude-urls-domains.md) | linkvalidator: Add possibility to exclude specific external URLs / domains  or patterns | 📋 offen (Schritt 5: Ziel-Ausschlüsse) |
| [#85006](85006-epic-reduce-false-positives.md) | Reduce falsely reported broken links | 🟡 teilweise (Epic) |

## Done

| Issue | Subject | Status linkvalidator2 |
|---|---|---|
| [#94086](done/94086-custom-linkhandler.md) | Softref for custom linkhandler | ✅ gelöst (2026-10-11) |
| [#97937](done/97937-nbsp-in-plain-urls.md) | Linkvalidator: Links and &nbsp; in tt_content.bodytext cause problems in UrlSoftReferenceParser | ✅ gelöst (2026-10-11) |
| [#105873](done/105873-store-page-id-not-pid.md) | Should always store page id in tx_linkvalidator_broken_links not pid | ✅ gelöst (Datenmodell) |
| [#103478](done/103478-check-file-fields.md) | Linkvalidator should check fields with type "file" | ✅ erledigt durch Entscheidung (nicht umsetzen) |
| [#96525](done/96525-report-only-404.md) | Add the possibility to repport only 404 errors (Don't show 403, 999 LinkedIn and SSL problems) | ✅ gelöst (Klassifizierung), Eskalation 📋 offen |
| [#92700](done/92700-url-cache.md) | Add URL cache for linkvalidator | ✅ gelöst |
| [#92586](done/92586-linktypes-config-scheduler.md) | Configuration for linktypes not considered in scheduler for linkvalidator | ✅ gelöst (entfällt durch Konzept) |
| [#89454](done/89454-pagination.md) | Limit list of broken links displayed / add pagination | ✅ gelöst |
