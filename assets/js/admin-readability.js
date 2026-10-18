/* Expose existing native validation beside fields; do not change validation rules. */
(() => {
  let serial = 0;
  const errors = new WeakMap();
  const controls = 'input:not([type="hidden"]),select,textarea';
  const labelFor = field => field.labels?.[0] || (field.previousElementSibling?.matches('label') ? field.previousElementSibling : null);
  const markRequired = root => {
    root.querySelectorAll(`${controls}`).forEach(field => {
      if (!field.required || ['checkbox', 'radio'].includes(field.type)) return;
      const label = labelFor(field);
      if (!label || label.querySelector('.readability-required')) return;
      if (!field.id) field.id = `readability-field-${++serial}`;
      if (!label.contains(field)) label.htmlFor = field.id;
      const marker = document.createElement('span');
      marker.className = 'readability-required'; marker.textContent = '(required)';
      if (label.contains(field)) label.insertBefore(marker, field); else label.append(marker);
    });
  };
  const clearError = field => {
    const entry = errors.get(field);
    if (!entry) return;
    entry.node.remove();
    const ids = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(id => id && id !== entry.node.id);
    if (ids.length) field.setAttribute('aria-describedby', ids.join(' ')); else field.removeAttribute('aria-describedby');
    if (entry.previousInvalid === null) field.removeAttribute('aria-invalid'); else field.setAttribute('aria-invalid', entry.previousInvalid);
    delete field.dataset.readabilityInvalid; errors.delete(field);
  };
  document.addEventListener('invalid', event => {
    const field = event.target;
    if (!field.matches(controls) || !field.validationMessage) return;
    let entry = errors.get(field);
    if (!entry) {
      const node = document.createElement('span'); node.id = `readability-error-${++serial}`;
      node.className = 'readability-field-error'; node.setAttribute('aria-live', 'polite');
      entry = {node, previousInvalid: field.getAttribute('aria-invalid')}; errors.set(field, entry);
      field.after(node);
      field.setAttribute('aria-describedby', [field.getAttribute('aria-describedby'), node.id].filter(Boolean).join(' '));
    }
    entry.node.textContent = field.validationMessage;
    field.setAttribute('aria-invalid', 'true'); field.dataset.readabilityInvalid = 'true';
  }, true);
  ['input', 'change'].forEach(type => document.addEventListener(type, event => {
    if (event.target.matches(controls) && event.target.validity.valid) clearError(event.target);
  }));
  document.addEventListener('reset', event => setTimeout(() => event.target.querySelectorAll(controls).forEach(clearError), 0));
  markRequired(document);
  new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => {
    if (node.nodeType === 1 && !node.matches('.readability-required,.readability-field-error')) markRequired(node.parentElement || node);
  }))).observe(document.body, {childList:true, subtree:true});
})();
