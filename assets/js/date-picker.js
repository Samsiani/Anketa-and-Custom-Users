/**
 * Date pickers for [club_anketa_form] — locks display format to dd/mm/yyyy
 * across all devices/locales (native <input type="date"> follows the OS
 * locale, which made some staff see mm/dd/yyyy).
 *
 * Replaces flatpickr's default year arrow-stepper with a <select> so the
 * year picker matches the month dropdown UX.
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

	function yearRangeFor(input) {
		var now = new Date().getFullYear();
		if (input.id === 'anketa_dob') {
			// DOB: ~1900 .. current (you can't be born tomorrow)
			return { min: 1900, max: now };
		}
		// Form date / generic: a tighter window around today.
		return { min: now - 10, max: now + 5 };
	}

	function replaceYearStepperWithSelect(instance, range) {
		var container = instance.calendarContainer;
		if (!container) {
			return;
		}
		var currentMonth = container.querySelector('.flatpickr-current-month');
		if (!currentMonth) {
			return;
		}
		var yearWrap = currentMonth.querySelector('.numInputWrapper');
		if (!yearWrap || yearWrap.dataset.acuYearReplaced === '1') {
			return;
		}

		var select = document.createElement('select');
		select.className = 'acu-flatpickr-year';
		for (var y = range.max; y >= range.min; y--) {
			var opt = document.createElement('option');
			opt.value = String(y);
			opt.textContent = String(y);
			if (y === instance.currentYear) {
				opt.selected = true;
			}
			select.appendChild(opt);
		}

		select.addEventListener('change', function () {
			instance.changeYear(parseInt(select.value, 10));
		});

		yearWrap.style.display = 'none';
		yearWrap.dataset.acuYearReplaced = '1';
		currentMonth.insertBefore(select, yearWrap);

		// Keep the select in sync when the user navigates via the << / >> arrows
		// or scroll wheel (months that cross a year boundary trigger these).
		instance.config.onYearChange.push(function () {
			select.value = String(instance.currentYear);
		});
		instance.config.onMonthChange.push(function () {
			if (String(instance.currentYear) !== select.value) {
				select.value = String(instance.currentYear);
			}
		});
	}

	Array.prototype.forEach.call(inputs, function (input) {
		var initial = parseAnyDate(input.value);
		var range = yearRangeFor(input);
		window.flatpickr(input, {
			dateFormat: 'd/m/Y',
			allowInput: true,
			disableMobile: true,
			defaultDate: initial,
			parseDate: function (str) {
				return parseAnyDate(str);
			},
			onReady: function (selectedDates, dateStr, instance) {
				replaceYearStepperWithSelect(instance, range);
			},
		});
	});
})();
