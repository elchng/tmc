/**
 * Celectric Claim Forms → Google Sheets (no Make needed).
 *
 * 1. Create a Google Sheet (e.g. "Claims Register").
 * 2. Extensions → Apps Script. Delete the sample code and paste this file.
 * 3. Change SECRET below to the same text as WordPress
 *    (Claims → Settings → Excel / Google Sheets → Shared secret).
 * 4. Deploy → New deployment → type "Web app".
 *      Execute as: Me      Who has access: Anyone
 *    Authorise when asked, then copy the Web app URL (…/exec).
 * 5. Paste that URL into WordPress → "Apps Script web app URL" → Save,
 *    then click "Send test rows".
 *
 * Travel claims go to the "Travel Claims" tab and mileage claims to the
 * "Mileage Claims" tab (created automatically with headings). A claim number
 * that is already in the sheet is not added twice.
 */
const SECRET = 'change-me';
const TABS = { travel: 'Travel Claims', mileage: 'Mileage Claims' };

function doPost(e) {
  let data;
  try {
    data = JSON.parse(e.postData.contents);
  } catch (err) {
    return reply_({ ok: false, error: 'Invalid JSON' });
  }
  if (data.secret !== SECRET) {
    return reply_({ ok: false, error: 'Wrong secret' });
  }
  const name = TABS[data.form] || 'Claims';
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const ss = SpreadsheetApp.getActiveSpreadsheet();
    const sheet = ss.getSheetByName(name) || ss.insertSheet(name);
    if (sheet.getLastRow() === 0) {
      sheet.appendRow(data.columns);
      sheet.getRange(1, 1, 1, data.columns.length).setFontWeight('bold').setBackground('#D9E1F2');
      sheet.setFrozenRows(1);
    }
    const last = sheet.getLastRow();
    const existing = last > 1 ? sheet.getRange(2, 1, last - 1, 1).getValues().map(function (r) { return String(r[0]); }) : [];
    if (existing.indexOf(String(data.claim_no)) === -1) {
      sheet.appendRow(data.values);
    }
  } finally {
    lock.releaseLock();
  }
  return reply_({ ok: true });
}

function reply_(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}
