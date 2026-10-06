/*
 * Dashboard charts (admin / HR).
 *
 * Reads its numbers from the JSON block the view prints (#dashboard-data) so no
 * figure lives in this file. Colours are read from the theme's own CSS classes
 * rather than hard-coded, and every chart is rebuilt when the light/dark toggle
 * flips `data-theme`, so a chart drawn in light mode never sits on a dark page.
 */
(function () {
	'use strict';

	var source = document.getElementById('dashboard-data');
	if (!source || typeof ApexCharts === 'undefined') {
		return;
	}

	var data;
	try {
		data = JSON.parse(source.textContent || '{}');
	} catch (e) {
		return;
	}

	var charts = [];
	var trendRange = '7';

	// A colour as the stylesheet currently paints it: probe an element with the
	// class and read back the computed value.
	function themeColor(className, property) {
		var probe = document.createElement('span');
		probe.className = className;
		probe.style.display = 'none';
		document.body.appendChild(probe);
		var value = getComputedStyle(probe)[property || 'color'];
		document.body.removeChild(probe);
		return value;
	}

	function palette() {
		var dark = document.documentElement.getAttribute('data-theme') === 'dark';
		return {
			dark: dark,
			primary: themeColor('text-primary'),
			success: themeColor('text-success'),
			warning: themeColor('text-warning'),
			info: themeColor('text-info'),
			danger: themeColor('text-danger'),
			text: getComputedStyle(document.body).color,
			grid: getComputedStyle(document.documentElement).getPropertyValue('--border-color').trim() || '#e5e7eb',
			surface: themeColor('card', 'backgroundColor')
		};
	}

	function base(c) {
		return {
			fontFamily: 'inherit',
			foreColor: c.text,
			toolbar: { show: false },
			animations: { enabled: true, speed: 400 }
		};
	}

	function donut(c) {
		var el = document.getElementById('dash-donut');
		if (!el || !data.donut) {
			return null;
		}
		return new ApexCharts(el, {
			chart: Object.assign(base(c), { type: 'donut', height: 240 }),
			series: data.donut.series,
			labels: ['On time', 'Late', 'On leave', 'Absent'],
			colors: [c.success, c.warning, c.info, c.danger],
			stroke: { width: 3, colors: [c.surface] },
			legend: { show: false },
			dataLabels: { enabled: false },
			tooltip: { theme: c.dark ? 'dark' : 'light' },
			plotOptions: {
				pie: {
					startAngle: -90,
					endAngle: 90,
					offsetY: 10,
					donut: {
						size: '72%',
						labels: {
							show: true,
							name: { show: true, offsetY: 18, color: c.text },
							value: { show: true, offsetY: -22, fontSize: '26px', fontWeight: 700, color: c.text },
							total: {
								show: true,
								showAlways: true,
								label: 'Attendance',
								color: c.text,
								formatter: function () {
									return data.donut.rate === null ? '—' : data.donut.rate + '%';
								}
							}
						}
					}
				}
			},
			grid: { padding: { bottom: -80 } }
		});
	}

	function trend(c) {
		var el = document.getElementById('attendance-trend');
		if (!el || !data.trend) {
			return null;
		}
		var days = data.trend[trendRange] || [];
		var labels = days.map(function (d) { return trendRange === '7' ? d.label : d.short; });
		return new ApexCharts(el, {
			chart: Object.assign(base(c), { type: 'bar', height: 290, stacked: true }),
			series: [
				{ name: 'On time', data: days.map(function (d) { return d.ontime; }) },
				{ name: 'Late', data: days.map(function (d) { return d.late; }) }
			],
			colors: [c.primary, c.warning],
			plotOptions: { bar: { columnWidth: trendRange === '7' ? '40%' : '65%', borderRadius: 4, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last' } },
			dataLabels: { enabled: false },
			xaxis: {
				categories: labels,
				axisBorder: { show: false },
				axisTicks: { show: false },
				labels: { rotate: -45, hideOverlappingLabels: true }
			},
			yaxis: { min: 0, forceNiceScale: true, labels: { formatter: function (v) { return Math.round(v); } } },
			grid: { borderColor: c.grid, strokeDashArray: 4 },
			legend: { position: 'top', horizontalAlign: 'right', labels: { colors: c.text } },
			tooltip: {
				theme: c.dark ? 'dark' : 'light',
				shared: true,
				intersect: false,
				x: { formatter: function (v, opts) { var d = days[opts.dataPointIndex]; return d ? d.label + ', ' + d.short : v; } }
			}
		});
	}

	function departments(c) {
		var el = document.getElementById('dash-departments');
		if (!el || !data.departments) {
			return null;
		}
		var rows = data.departments.slice(0, 8);
		var most = Math.max.apply(null, rows.map(function (d) { return d.count; }).concat([1]));
		return new ApexCharts(el, {
			chart: Object.assign(base(c), { type: 'bar', height: Math.max(200, rows.length * 38 + 40) }),
			series: [{ name: 'Employees', data: rows.map(function (d) { return d.count; }) }],
			colors: [c.primary],
			plotOptions: { bar: { horizontal: true, barHeight: '55%', borderRadius: 4, borderRadiusApplication: 'end' } },
			dataLabels: { enabled: true, style: { colors: ['#fff'], fontWeight: 600 } },
			xaxis: {
				categories: rows.map(function (d) { return d.name; }),
				axisBorder: { show: false },
				axisTicks: { show: false },
				tickAmount: Math.min(most, 6),
				min: 0,
				max: most <= 6 ? most : undefined,
				labels: { formatter: function (v) { return Math.round(v); } }
			},
			grid: { borderColor: c.grid, strokeDashArray: 4, xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
			tooltip: { theme: c.dark ? 'dark' : 'light' }
		});
	}

	function renderAll() {
		charts.forEach(function (chart) { chart.destroy(); });
		charts = [];
		var c = palette();
		[donut(c), trend(c), departments(c)].forEach(function (chart) {
			if (chart) {
				chart.render();
				charts.push(chart);
			}
		});
	}

	// The 7 / 30 day switch on the trend card.
	document.querySelectorAll('[data-trend-range]').forEach(function (button) {
		button.addEventListener('click', function () {
			trendRange = button.getAttribute('data-trend-range');
			document.querySelectorAll('[data-trend-range]').forEach(function (b) {
				var active = b === button;
				b.classList.toggle('btn-primary', active);
				b.classList.toggle('btn-white', !active);
				b.classList.toggle('border', !active);
			});
			renderAll();
		});
	});

	// Redraw in the new colours whenever the theme toggle changes data-theme.
	new MutationObserver(function (changes) {
		if (changes.some(function (m) { return m.attributeName === 'data-theme'; })) {
			renderAll();
		}
	}).observe(document.documentElement, { attributes: true });

	renderAll();
})();
