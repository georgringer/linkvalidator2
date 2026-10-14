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

import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';
import labels from '~labels/linkvalidator2.messages';

/**
 * "Details" of a problem in the report: shows what the checker reported (e.g. the cURL
 * message) in a modal instead of expanding the table row.
 *
 * @todo Move to Build/Sources/TypeScript/linkvalidator/ when replacing the core extension.
 */
const rows = [
  ['target', 'details.target'],
  ['redirectBy', 'details.redirectBy'],
  ['redirectTarget', 'details.redirectTarget'],
  ['httpStatus', 'details.httpStatus'],
  ['errno', 'details.errno'],
  ['message', 'details.message'],
];

document.addEventListener('click', (event) => {
  const trigger = event.target.closest('[data-linkvalidator2-details]');
  if (!trigger) {
    return;
  }
  let details;
  try {
    details = JSON.parse(trigger.dataset.linkvalidator2Details);
  } catch {
    return;
  }

  const list = document.createElement('dl');
  list.className = 'row mb-0';
  for (const [key, label] of rows) {
    if (details[key] === undefined || details[key] === null || details[key] === '') {
      continue;
    }
    const term = document.createElement('dt');
    term.className = 'col-sm-3';
    term.textContent = labels.get(label);
    const description = document.createElement('dd');
    description.className = 'col-sm-9 text-break';
    description.textContent = String(details[key]);
    list.append(term, description);
  }

  Modal.advanced({
    title: labels.get('details.title'),
    content: list,
    severity: Severity.notice,
    size: Modal.sizes.medium,
    buttons: [{
      text: labels.get('details.close'),
      btnClass: 'btn-default',
      name: 'close',
      active: true,
      trigger: (e, modal) => modal.hideModal(),
    }],
  });
});
