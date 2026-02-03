(function () {
  'use strict';

  var form = document.getElementById('cdf-shipping-calculator');
  if (!form) return;

  var input = form.querySelector('.cdf-postcode-input');
  var button = form.querySelector('.cdf-calculate-btn');
  var resultsContainer = form.querySelector('.cdf-results');

  // Restore saved postcode.
  try {
    var saved = sessionStorage.getItem('cdf_postcode');
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
      showError('Digite um CEP válido com 8 números.');
      return;
    }

    // Save postcode.
    try { sessionStorage.setItem('cdf_postcode', input.value); } catch (e) {}

    // Get quantity from the product page.
    var qtyInput = document.querySelector('input.qty, input[name="quantity"]');
    var quantity = qtyInput ? parseInt(qtyInput.value, 10) || 1 : 1;

    button.disabled = true;
    button.textContent = 'Calculando...';
    resultsContainer.innerHTML = '';

    var data = new FormData();
    data.append('action', 'cdf_calculate_shipping');
    data.append('nonce', cdf_params.nonce);
    data.append('postcode', postcode);
    data.append('product_id', cdf_params.product_id);
    data.append('quantity', quantity);

    fetch(cdf_params.ajax_url, { method: 'POST', body: data })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        button.disabled = false;
        button.textContent = 'Calcular';

        if (!res.success) {
          showError(res.data && res.data.message ? res.data.message : 'Erro ao calcular frete.');
          return;
        }

        // Log debug info if present.
        if (res.data._debug) {
          console.log('[CDF Debug]', res.data._debug);
        }

        renderRates(res.data.rates);
      })
      .catch(function () {
        button.disabled = false;
        button.textContent = 'Calcular';
        showError('Erro de conexão. Tente novamente.');
      });
  }

  function showError(msg) {
    resultsContainer.innerHTML = '<p class="cdf-error">' + escapeHtml(msg) + '</p>';
  }

  function renderRates(rates) {
    if (!rates || rates.length === 0) {
      showError('Nenhuma opção de frete disponível.');
      return;
    }

    var html = '<table class="cdf-rates-table">';
    html += '<thead><tr><th>Transportadora</th><th>Prazo</th><th>Valor</th></tr></thead>';
    html += '<tbody>';

    for (var i = 0; i < rates.length; i++) {
      var r = rates[i];
      var name = r.carrier;
      if (r.service_type) name += ' - ' + r.service_type;

      // Build carrier cell with optional logo.
      var carrierCell = '';
      if (r.logo) {
        carrierCell += '<img src="' + r.logo + '" alt="" class="cdf-carrier-logo" /> ';
      }
      carrierCell += escapeHtml(name);

      html += '<tr>';
      html += '<td class="cdf-carrier-cell">' + carrierCell + '</td>';
      html += '<td>' + escapeHtml(r.delivery_time) + '</td>';
      html += '<td class="cdf-price-cell">R$ ' + escapeHtml(r.price) + '</td>';
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
