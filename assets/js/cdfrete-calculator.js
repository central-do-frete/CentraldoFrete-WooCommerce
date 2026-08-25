(function () {
  'use strict';

  var form = document.getElementById('cdfrete-shipping-calculator');
  if (!form) return;

  // Every shopper-facing string comes from PHP so it goes through the plugin's translations.
  var i18n = (window.cdfrete_params && cdfrete_params.i18n) || {};

  function t(key, fallback) {
    return i18n[key] || fallback;
  }

  var input = form.querySelector('.cdfrete-postcode-input');
  var button = form.querySelector('.cdfrete-calculate-btn');
  var resultsContainer = form.querySelector('.cdfrete-results');

  // Restore saved postcode.
  try {
    var saved = sessionStorage.getItem('cdfrete_postcode');
    if (saved && input) input.value = saved;
  } catch (e) {}

  // CEP mask: 00000-000.
  if (input) {
    input.addEventListener('input', function () {
      var v = this.value.replace(/\D/g, '').substring(0, 8);
      if (v.length > 5) {
        v = v.substring(0, 5) + '-' + v.substring(5);
      }
      this.value = v;
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    calculate();
  });

  function calculate() {
    var postcode = input.value.replace(/\D/g, '');
    if (postcode.length !== 8) {
      showError(t('invalidPostcode', 'Digite um CEP válido com 8 números.'));
      return;
    }

    // Save postcode.
    try { sessionStorage.setItem('cdfrete_postcode', input.value); } catch (e) {}

    // Get quantity from the product page.
    var qtyInput = document.querySelector('input.qty, input[name="quantity"]');
    var quantity = qtyInput ? parseInt(qtyInput.value, 10) || 1 : 1;

    button.disabled = true;
    button.textContent = t('calculating', 'Calculando...');
    resultsContainer.innerHTML = '';

    var data = new FormData();
    data.append('action', 'cdfrete_calculate_shipping');
    data.append('nonce', cdfrete_params.nonce);
    data.append('postcode', postcode);
    data.append('product_id', cdfrete_params.product_id);
    data.append('quantity', quantity);

    fetch(cdfrete_params.ajax_url, { method: 'POST', body: data })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        button.disabled = false;
        button.textContent = t('calculate', 'Calcular');

        if (!res.success) {
          showError(res.data && res.data.message ? res.data.message : t('requestFailed', 'Erro ao calcular frete.'));
          return;
        }

        // Log debug info if present.
        if (res.data._debug) {
          console.log('[Central do Frete]', res.data._debug);
        }

        renderRates(res.data.rates);
      })
      .catch(function () {
        button.disabled = false;
        button.textContent = t('calculate', 'Calcular');
        showError(t('connectionError', 'Erro de conexão. Tente novamente.'));
      });
  }

  function showError(msg) {
    resultsContainer.innerHTML = '<p class="cdfrete-error">' + escapeHtml(msg) + '</p>';
  }

  function renderRates(rates) {
    if (!rates || rates.length === 0) {
      showError(t('noRates', 'Nenhuma opção de frete disponível.'));
      return;
    }

    var html = '<table class="cdfrete-rates-table">';
    html += '<thead><tr><th>' + escapeHtml(t('carrierColumn', 'Transportadora')) +
      '</th><th>' + escapeHtml(t('timeColumn', 'Prazo')) +
      '</th><th>' + escapeHtml(t('priceColumn', 'Valor')) + '</th></tr></thead>';
    html += '<tbody>';

    for (var i = 0; i < rates.length; i++) {
      var r = rates[i];
      var name = r.carrier;
      if (r.service_type) name += ' - ' + r.service_type;

      // Build carrier cell with optional logo.
      var carrierCell = '';
      if (r.logo) {
        carrierCell += '<img src="' + r.logo + '" alt="" class="cdfrete-carrier-logo" /> ';
      }
      carrierCell += escapeHtml(name);

      html += '<tr>';
      html += '<td class="cdfrete-carrier-cell">' + carrierCell + '</td>';
      html += '<td>' + escapeHtml(r.delivery_time) + '</td>';
      html += '<td class="cdfrete-price-cell">R$ ' + escapeHtml(r.price) + '</td>';
      html += '</tr>';
    }

    html += '</tbody></table>';
    resultsContainer.innerHTML = html;
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }
})();
