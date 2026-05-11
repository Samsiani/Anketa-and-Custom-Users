/**
 * Date pickers for [club_anketa_form] — locks display format to dd/mm/yyyy
 * across all devices/locales (native <input type="date"> follows the OS
 * locale, which made some staff see mm/dd/yyyy).
 *
 * Backend accepts Y-m-d, d/m/Y and d.m.Y, so submitting d/m/Y is safe.
 */
(function () {
	if (typeof window.flatpickr !== 'function') {
		return;
	}

	var inputs = document.querySelectorAll('.acu-date-picker');
	if (!inputs.length) {
		return;
	}

	function parseAnyDate(s) {
		if (!s) {
			return null;
		}
		var m = s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
		if (m) {
			return new Date(+m[1], +m[2] - 1, +m[3]);
		}
		m = s.match(/^(\d{1,2})[\/\.\-](\d{1,2})[\/\.\-](\d{4})$/);
		if (m) {
			return new Date(+m[3], +m[2] - 1, +m[1]);
		}
		var d = new Date(s);
		return isNaN(d.getTime()) ? null : d;
	}

	Array.prototype.forEach.call(inputs, function (input) {
		var initial = parseAnyDate(input.value);
		window.flatpickr(input, {
			dateFormat: 'd/m/Y',
			allowInput: true,
			disableMobile: true,
			defaultDate: initial,
			parseDate: function (str) {
				return parseAnyDate(str);
			},
		});
	});
})();
