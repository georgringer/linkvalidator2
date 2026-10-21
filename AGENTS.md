# linkvalidator2 – agent instructions

Local rewrite of the core `linkvalidator`. The core `AGENTS.md` of the repository
applies; this file adds what is specific to this extension. Design decisions and
the plan live in `Notes/` (German).

## Keep the README feature comparison up to date

Every change that adds, removes or changes behaviour also updates the
**Feature comparison** table in `README.md` in the same step: add rows for new
capabilities, mark known bugs or limitations (🟡) instead of claiming ✅, put the Forge
issue of a core bug into the *linkvalidator* column. Keep cells to a symbol and an issue
number. Explanations go to `Notes/2026-10-21-feature-comparison-full.md`, a snapshot of
the former long table that is not maintained. Do this before reporting the work as done.

## Tests

Run in containers from the extension root, independent of the core checkout:
`Build/Scripts/runTests.sh -s composerInstall` once, then `-s unit` and
`-s functional` (sqlite by default). New behaviour and every bug fix come with a test
(bug: test first). On the core move the tests go to the core's `runTests.sh`; `Build/`,
`.Build/` and the `require-dev`/`config` parts of `composer.json` stay behind.

## Documentation notes

`Documentation/todo.md` collects what the documentation must explain (editors,
integrators, developers). Add to it whenever behaviour or an extension point changes.

## Not part of the core move

`AGENTS.md`, `CLAUDE.md`, `Notes/`, `Resources/Private/Issues/`, `Documentation/todo.md`
(becomes RST documentation) and `.superpowers/` stay out of the patch series when the
extension replaces `typo3/sysext/linkvalidator`.

## Forge issues

`Resources/Private/Issues/` holds one analysis per open Forge issue of the category
Linkvalidator. When a change solves an issue, move its file to `done/`, update its
status line, the index `README.md` there and the *linkvalidator* column of the
feature comparison.
