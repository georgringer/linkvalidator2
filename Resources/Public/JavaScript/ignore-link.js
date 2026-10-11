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
 * "Ignore" in the report: creates an exclusion on the selected page and reloads the
 * report, from which the results it excludes are gone.
 *
 * @todo Move to Build/Sources/TypeScript/linkvalidator/ when replacing the core extension.
 */
document.addEventListener('click', async (event) => {
  const item = event.target.closest('[data-linkvalidator2-ignore]');
  if (!item) {
    return;
  }
  item.disabled = true;
  try {
    const result = await (await new AjaxRequest(TYPO3.settings.ajaxUrls.linkvalidator2_exclusion_create).post({
      pid: item.dataset.pid,
      type: item.dataset.linkvalidator2Ignore,
      value: item.dataset.value,
    })).resolve();
    Notification.success(labels.get('ignore.done'), labels.get('ignore.storedOn', { page: result.page }));
    window.location.reload();
  } catch (error) {
    let message = '';
    if (typeof error?.resolve === 'function') {
      message = (await error.resolve().catch(() => ({})))?.error ?? '';
    }
    Notification.error(labels.get('ignore.failed'), message);
    item.disabled = false;
  }
});
