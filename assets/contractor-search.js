(() => {
  const picker = document.querySelector('.contractor-picker');
  if (!picker) return;
  const select = picker.querySelector('select[name="contractor_id"]');
  const search = picker.querySelector('.contractor-search');
  const status = picker.querySelector('.contractor-search-status');
  if (!select || !search || !status) return;
  let expanded = false;
  const normalize = value => value.trim().toLowerCase().replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[۰-۹٠-٩]/g, char => String('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩'.indexOf(char) % 10));
  const options = () => Array.from(select.options).filter(option => option.value !== '');
  function filter() {
    const query = normalize(search.value);
    let count = 0;
    options().forEach(option => { const match = normalize(option.textContent).includes(query); option.hidden = !match; if (match) count++; });
    status.textContent = count ? `${count} پیمانکار یافت شد` : 'پیمانکاری یافت نشد.';
  }
  function open() {
    expanded = true;
    search.hidden = false;
    status.hidden = false;
    select.size = 6;
    filter();
    search.focus();
  }
  function close(focusSelect = false) {
    if (!expanded) return;
    expanded = false;
    search.hidden = true;
    status.hidden = true;
    search.value = '';
    options().forEach(option => { option.hidden = false; });
    select.removeAttribute('size');
    if (focusSelect) select.focus();
  }
  select.addEventListener('pointerdown', event => {
    if (!expanded) { event.preventDefault(); open(); }
  });
  select.addEventListener('keydown', event => {
    if (!expanded && ['Enter', ' ', 'ArrowDown'].includes(event.key)) { event.preventDefault(); open(); }
    else if (expanded && event.key === 'Escape') { event.preventDefault(); close(true); }
  });
  select.addEventListener('change', () => close());
  search.addEventListener('input', filter);
  search.addEventListener('keydown', event => {
    if (event.key === 'Escape') { event.preventDefault(); close(true); }
    else if (event.key === 'ArrowDown') { event.preventDefault(); select.focus(); }
    else if (event.key === 'Enter') {
      event.preventDefault();
      const match = options().find(option => !option.hidden);
      if (match) { select.value = match.value; select.dispatchEvent(new Event('change', {bubbles:true})); select.focus(); }
    }
  });
  document.addEventListener('pointerdown', event => { if (expanded && !picker.contains(event.target)) close(); });
})();
