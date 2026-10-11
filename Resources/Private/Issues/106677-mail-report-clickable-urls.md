# #106677 – Improve Linkvalidator broken URL output in E-Mail Report

- Forge: https://forge.typo3.org/issues/106677 (Task, Under Review, angelegt 2025-05-12)
- Gerrit: https://review.typo3.org/c/Packages/TYPO3.CMS/+/89357 (abandoned)
- Verwandt: #106487
- **Status linkvalidator2:** 📋 offen (Mail-Report, später)

## Problem

Im HTML-Mail-Report sind kaputte URLs auf 15 Zeichen gekürzt und nur im `title`
vollständig. Wunsch: echte `<a>`-Links. Links zum Bearbeiten scheitern daran, dass im
CLI keine absoluten Backend-URLs gebaut werden können.

## Analyse linkvalidator2

Es gibt noch keinen Mail-Report. Geplant (Notes, TODO „Mail-Report“): Delta seit dem
letzten Report, pro Site, Fluid-Mail, entkoppelt vom Prüflauf.

Anforderungen für die Umsetzung:
- Ziel-URL vollständig und klickbar (HTML) bzw. ungekürzt (Text).
- Link ins Modul / Bearbeiten-Link: Backend-URL absolut bauen. Ansatz prüfen: Report ist
  pro Site → Basis aus der Site (`$site->getBase()`) + Backend-Entry-Point
  (`$GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint']`) bzw. konfigurierbare
  Backend-Basis-URL in den Site Settings, weil das Backend auf einer anderen Domain
  liegen kann.

## Zu tun

Beim Bau des Mail-Reports berücksichtigen, zusammen mit #106487.
