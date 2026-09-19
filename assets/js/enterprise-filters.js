/* Enhance existing content filters; original links retain routing and handlers. */
(() => {
  const init = () => {
    // Standalone collections without a view toggle also use the list layout.
    document.querySelectorAll('main.content .view-grid').forEach(collection => {
      collection.classList.remove('view-grid');
      collection.classList.add('view-list');
    });
    document.querySelectorAll('main.content .filter-tabs, main.content .appt-filter-row').forEach(group => {
      const links = Array.from(group.querySelectorAll('a.filter-tab, a.appt-filter'));
      if (links.length < 2 || group.dataset.enterpriseFilter) return;
      const label = document.createElement('label');
      label.className = 'enterprise-filter-control';
      const caption = document.createElement('span');
      const keys = links.flatMap(link => [...new URL(link.href).searchParams.keys()]);
      caption.textContent = keys.includes('status') || keys.includes('filter') ? 'Status' : keys.includes('category') ? 'Category' : 'Filter';
      const select = document.createElement('select');
      select.className = 'form-select enterprise-filter-select';
      let previousState = '';
      const sync = () => {
        const state = JSON.stringify(links.map(link => [link.textContent, link.classList.contains('active'), link.getAttribute('aria-current')]));
        if (state === previousState) return;
        previousState = state;
        select.replaceChildren(...links.map((link, index) => {
          const count = link.querySelector('b');
          const copy = link.cloneNode(true);
          copy.querySelectorAll('b, svg').forEach(node => node.remove());
          const name = copy.textContent.replace(/\s+/g, ' ').trim();
          const option = new Option(name + (count ? ` (${count.textContent.trim()})` : ''), String(index));
          option.selected = link.classList.contains('active') || link.getAttribute('aria-current') === 'page';
          return option;
        }));
      };
      sync();
      select.addEventListener('change', () => links[Number(select.value)]?.click());
      label.append(caption, select);
      group.before(label);
      group.dataset.enterpriseFilter = 'true';
      new MutationObserver(sync).observe(group, {subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['class', 'aria-current']});
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
