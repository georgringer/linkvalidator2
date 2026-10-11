# #103478 – Linkvalidator should check fields with type "file"

- Forge: https://forge.typo3.org/issues/103478 (Feature, New, angelegt 2024-03-25)
- Verwandt: #83835, #89182
- **Status linkvalidator2:** ✅ erledigt durch Entscheidung (nicht umsetzen)

## Problem

Felder vom Typ `file` (z. B. `pages.media`) sollen geprüft werden.

## Analyse linkvalidator2

Bewusst nicht umgesetzt. Entscheidung in den Notes (Abschnitt „Sonderfelder“):
Relationen (`group`/`select`/`file`) sind keine Links, sondern Referenzen über
`sys_file_reference`/MM. Ein fehlendes Ziel ist dort ein Integritätsproblem
(Referenzindex, `lowlevel:cleanup`), kein Link, den ein Redakteur im Text repariert.

Was linkvalidator2 prüft: Links *auf* Dateien und Ordner (`t3://file`, `t3://folder`)
in Link- und RTE-Feldern – fehlend/`missing=1` → broken, Storage offline → unknown.

Falls doch gewünscht, wäre der Einstieg `CheckableFieldResolver::toCheckableField()`
(neuer `ExtractionMode` für `FileFieldType`, Ziel `file:<uid>` aus `sys_file_reference`).
Bei der Forge-Antwort auf den Unterschied Referenz ↔ Link verweisen.
