(function () {
  'use strict';

  var config = window.saltusAiAssistant;
  if (!config || !config.definition || !config.definition.actions) return;

  function fieldValue(selector) {
    var field = document.querySelector(selector);
    return field ? field.value || field.textContent || '' : '';
  }

  function metaField(fieldId) {
    return document.getElementById(fieldId) || document.querySelector('[name="' + fieldId + '"]');
  }

  function payload() {
    var meta = {};
    (config.definition.fields || []).forEach(function (field) {
      var input = metaField(field.field_id);
      if (input) meta[field.path] = input.value || '';
    });
    return {
      title: fieldValue('#title'),
      content: fieldValue('#content'),
      excerpt: fieldValue('#excerpt'),
      meta: meta
    };
  }

  function button(action) {
    var control = document.createElement('button');
    control.type = 'button';
    control.className = 'button saltus-ai-assistant-button';
    control.textContent = action.label;
    control.addEventListener('click', function () {
      control.disabled = true;
      var body = payload();
      fetch(config.endpoint + '/' + encodeURIComponent(action.name), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
        body: JSON.stringify(body)
      }).then(function (response) {
        return response.json().then(function (data) { return { ok: response.ok, data: data }; });
      }).then(function (result) {
        if (!result.ok) throw new Error(result.data.message || 'Assistant request failed.');
        var data = result.data;
        if (typeof data.value === 'string' && data.target) {
          var target = data.target === 'post_title' ? '#title' : data.target === 'post_excerpt' ? '#excerpt' : data.target === 'post_content' ? '#content' : null;
          var field = target ? document.querySelector(target) : null;
          if (field && window.confirm('Apply the assistant suggestion?')) {
            field.value = data.value;
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
          }
        }
        if (Array.isArray(data.suggestions) && data.suggestions.length) window.alert(data.suggestions.join('\n'));
        renderViolations(data.violations || []);
      }).catch(function (error) {
        window.alert(error.message);
      }).then(function () {
        control.disabled = false;
      });
    });
    return control;
  }

  function renderViolations(violations) {
    var existing = document.querySelector('.saltus-ai-assistant-validation');
    if (existing) existing.remove();
    var content = document.querySelector('#content');
    if (!content || !violations.length) return;
    var panel = document.createElement('div');
    panel.className = 'notice notice-warning saltus-ai-assistant-validation';
    var heading = document.createElement('strong');
    heading.textContent = 'Content validation';
    panel.appendChild(heading);
    var list = document.createElement('ul');
    violations.forEach(function (violation) {
      var item = document.createElement('li');
      item.textContent = violation;
      list.appendChild(item);
    });
    panel.appendChild(list);
    content.parentNode.insertBefore(panel, content);
    content.classList.add('saltus-ai-assistant-invalid');
  }

  var toolbar = document.createElement('div');
  toolbar.className = 'saltus-ai-assistant-toolbar';
  config.definition.actions.forEach(function (action) { toolbar.appendChild(button(action)); });
  var title = document.querySelector('#title');
  if (title && title.parentNode) title.parentNode.insertBefore(toolbar, title.nextSibling);

  (config.definition.fields || []).forEach(function (field) {
    var input = metaField(field.field_id);
    if (!input || !input.parentNode) return;
    var fieldToolbar = document.createElement('span');
    fieldToolbar.className = 'saltus-ai-assistant-field-toolbar';
    config.definition.actions.filter(function (action) {
      return action.target === 'post_content' || action.target === 'post_excerpt';
    }).forEach(function (action) { fieldToolbar.appendChild(button(action)); });
    input.parentNode.insertBefore(fieldToolbar, input.nextSibling);
  });
})();
