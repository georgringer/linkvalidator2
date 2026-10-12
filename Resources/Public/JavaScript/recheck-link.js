/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import labels from '~labels/linkvalidator2.messages';

/**
 * "Check this link again" in the report: checks the link of the result again, with a new
 * request for an external URL, and shows the outcome. The results of the report are replaced
 * without reloading the page, so the position in the list stays. If nothing is left to show,
 * the report is reloaded and the outcome is shown after that.
 *
 * @todo Move to Build/Sources/TypeScript/linkvalidator/ when replacing the core extension.
 */
const STORAGE_KEY = 'linkvalidator2-recheck';
const RESULTS = '[data-linkvalidator2-results]';
const notificationByStatus = {
  ok: ['success', 'recheck.ok'],
  broken: ['warning', 'recheck.broken'],
  unknown: ['warning', 'recheck.unknown'],
  info: ['info', 'recheck.info'],
  gone: ['success', 'recheck.gone'],
};

const notify = (status) => {
  const [severity, label] = notificationByStatus[status] ?? [];
  if (severity) {
    Notification[severity](labels.get('recheck.title'), labels.get(label));
  }
};

const showStoredNotification = () => {
  try {
    const status = window.sessionStorage.getItem(STORAGE_KEY);
    window.sessionStorage.removeItem(STORAGE_KEY);
    notify(status);
  } catch {
    // No storage, no notification
  }
};

/**
 * Replaces the results with the ones of the current report. Returns false if there is nothing
 * to replace them with.
 */
const refreshResults = async () => {
  const current = document.querySelector(RESULTS);
  if (current === null) {
    return false;
  }
  const response = await fetch(window.location.href, { credentials: 'same-origin' });
  const fresh = new DOMParser().parseFromString(await response.text(), 'text/html').querySelector(RESULTS);
  if (!response.ok || fresh === null) {
    return false;
  }
  current.replaceWith(document.importNode(fresh, true));
  return true;
};

showStoredNotification();

document.addEventListener('click', async (event) => {
  const button = event.target.closest('[data-linkvalidator2-recheck]');
  if (!button) {
    return;
  }
  button.disabled = true;
  try {
    const result = await (await new AjaxRequest(TYPO3.settings.ajaxUrls.linkvalidator2_check_recheck).post({
      table: button.dataset.table,
      record: button.dataset.record,
      field: button.dataset.field,
      target: button.dataset.target,
    })).resolve();
    if (await refreshResults()) {
      notify(result.status);
      return;
    }
    try {
      window.sessionStorage.setItem(STORAGE_KEY, result.status);
    } catch {
      // The report is reloaded without a notification
    }
    window.location.reload();
  } catch (error) {
    let message = '';
    if (typeof error?.resolve === 'function') {
      message = (await error.resolve().catch(() => ({})))?.error ?? '';
    }
    Notification.error(labels.get('recheck.failed'), message);
    button.disabled = false;
  }
});
