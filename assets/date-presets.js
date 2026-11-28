/* Jalali arithmetic matches the server's conversion in lib.php. */
(function (root) {
  function toGregorian(jy, jm, jd) {
    jy += 1595;
    let days = -355668 + 365 * jy + Math.floor(jy / 33) * 8 + Math.floor((jy % 33 + 3) / 4) + jd + (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186);
    let gy = 400 * Math.floor(days / 146097); days %= 146097;
    if (days > 36524) { days--; gy += 100 * Math.floor(days / 36524); days %= 36524; if (days >= 365) days++; }
    gy += 4 * Math.floor(days / 1461); days %= 1461;
    if (days > 365) { gy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
    let gd = days + 1, gm = 1;
    const leap = (gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0;
    const months = [0,31,leap ? 29 : 28,31,30,31,30,31,31,30,31,30,31];
    while (gm <= 12 && gd > months[gm]) { gd -= months[gm]; gm++; }
    return [gy, gm, gd];
  }
  function toJalali(gy, gm, gd) {
    const offsets = [0,31,59,90,120,151,181,212,243,273,304,334];
    const gy2 = gm > 2 ? gy + 1 : gy;
    let days = 355666 + 365 * gy + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) + gd + offsets[gm - 1];
    let jy = -1595 + 33 * Math.floor(days / 12053); days %= 12053;
    jy += 4 * Math.floor(days / 1461); days %= 1461;
    if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
    return days < 186 ? [jy, 1 + Math.floor(days / 31), 1 + days % 31] : [jy, 7 + Math.floor((days - 186) / 30), 1 + (days - 186) % 30];
  }
  function addJalaliDays(input, count) {
    const digits = '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩';
    const normalized = String(input).trim().replace(/[۰-۹٠-٩]/g, char => String(digits.indexOf(char) % 10));
    const match = /^(\d{4})[/-](\d{1,2})[/-](\d{1,2})$/.exec(normalized);
    if (!match || !Number.isInteger(count) || count < 0) return null;
    const [y,m,d] = match.slice(1).map(Number);
    if (y < 1300 || y > 1500 || m < 1 || m > 12 || d < 1 || d > 31) return null;
    const g = toGregorian(y,m,d), check = toJalali(...g);
    if (check[0] !== y || check[1] !== m || check[2] !== d) return null;
    const result = new Date(Date.UTC(g[0], g[1] - 1, g[2] + count));
    const j = toJalali(result.getUTCFullYear(), result.getUTCMonth() + 1, result.getUTCDate());
    if (j[0] > 1500) return null;
    return String(j[0]).padStart(4,'0') + '/' + String(j[1]).padStart(2,'0') + '/' + String(j[2]).padStart(2,'0');
  }
  if (typeof module !== 'undefined' && module.exports) module.exports = {addJalaliDays};
  if (!root.document) return;
  const form = root.document.querySelector('.form-panel form[enctype="multipart/form-data"]');
  if (!form) return;
  const start = form.elements.namedItem('start_date'), end = form.elements.namedItem('end_date'), message = root.document.getElementById('date-preset-message');
  form.querySelectorAll('[data-add-days]').forEach(button => button.addEventListener('click', () => {
    const result = addJalaliDays(start.value, Number(button.dataset.addDays));
    if (!result) { message.textContent = 'ابتدا تاریخ شروع شمسی معتبر وارد کنید.'; start.focus(); return; }
    end.value = result;
    end.dispatchEvent(new Event('input', {bubbles: true}));
    end.dispatchEvent(new Event('change', {bubbles: true}));
    message.textContent = 'تاریخ پایان براساس تاریخ شروع تنظیم شد.';
  }));
})(typeof window !== 'undefined' ? window : globalThis);
