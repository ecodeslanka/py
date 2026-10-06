/**
 * assets/js/location-select.js
 * Wires up a Province <select> + City <select> pair as select2 dropdowns,
 * where choosing a Province loads that province's cities via get-cities.php.
 * Requires jQuery + select2 to already be loaded on the page.
 *
 * Each <option> in the Province <select> must carry a data-id="123" attribute
 * with its numeric province id (used only to fetch cities) — the option's
 * own value="" can be whatever the page needs to submit (an id, in
 * profile.php; a plain name, in checkout.php). Each City <option> gets its
 * own data-id (the city's id) and, when withDeliveryCharge is set, a
 * data-charge attribute — read these on the City <select>'s change event
 * for anything that needs the numeric city id or its delivery charge
 * (checkout.php does both).
 *
 * Usage:
 *   initProvinceCitySelect({
 *     provinceSelector:   '#province_id',
 *     citySelector:       '#city',
 *     baseUrl:            BASE_URL,        // site root, no trailing slash
 *     selectedProvinceId: 3,               // optional — numeric id, triggers the initial city fetch
 *     selectedCityName:   'Colombo',       // optional — pre-selects this city once its province's list loads
 *     withDeliveryCharge: true,            // optional — also fetch + attach each city's delivery charge
 *     onCitiesLoaded:     function(){}      // optional — called after the city list (re)populates
 *   });
 */
function initProvinceCitySelect(opts) {
  var $province = $(opts.provinceSelector);
  var $city     = $(opts.citySelector);
  var baseUrl   = opts.baseUrl || '';
  var pendingCityName = opts.selectedCityName || '';
  var withCharge = !!opts.withDeliveryCharge;

  $province.select2({ width: '100%', placeholder: 'Select Province', allowClear: true });
  $city.select2({ width: '100%', placeholder: 'Select Province First', allowClear: true });

  function loadCities(provinceId, cb) {
    if (!provinceId) {
      $city.empty().append('<option value="">Select Province First</option>').prop('disabled', true).trigger('change');
      return;
    }
    $city.prop('disabled', true).empty().append('<option value="">Loading cities…</option>').trigger('change');
    var url = baseUrl + '/get-cities?province_id=' + encodeURIComponent(provinceId) + (withCharge ? '&with_delivery_charge=1' : '');
    fetch(url)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        $city.empty().append('<option value="">Select City</option>');
        (data.cities || []).forEach(function (c) {
          var opt = new Option(c.name, c.name, false, false);
          opt.setAttribute('data-id', c.id);
          if (withCharge) { opt.setAttribute('data-charge', c.delivery_charge); }
          $city.append(opt);
        });
        $city.prop('disabled', false).trigger('change');
        if (opts.onCitiesLoaded) { opts.onCitiesLoaded(); }
        if (cb) cb();
      })
      .catch(function () {
        $city.empty().append('<option value="">Could not load cities — try again</option>').prop('disabled', false).trigger('change');
      });
  }

  $province.on('change', function () {
    var pid = $province.find(':selected').data('id') || $province.val();
    loadCities(pid);
  });

  // Pre-fill on page load (edit / validation-error redisplay / logged-in customer's saved address).
  var initialProvinceId = opts.selectedProvinceId || $province.find(':selected').data('id') || $province.val();
  if (initialProvinceId) {
    loadCities(initialProvinceId, function () {
      if (pendingCityName) { $city.val(pendingCityName).trigger('change'); }
    });
  }
}
