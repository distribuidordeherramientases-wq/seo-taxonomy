(function () {
	'use strict';

	function init() {
		var config = window.taxocommerceIeExporter;
		var form = document.getElementById('tc-ie-export-form');
		if (!config || !form) {
			return;
		}

		var type = document.getElementById('tc-ie-export-type');
		var description = document.getElementById('tc-ie-export-type-description');
		var productFilters = document.getElementById('tc-ie-export-product-filters');
		var statuses = document.getElementById('tc-ie-export-statuses');
		var cluster = document.getElementById('tc-ie-export-cluster');
		var primary = document.getElementById('tc-ie-export-primary');
		var secondary = document.getElementById('tc-ie-export-secondary');
		var start = document.getElementById('tc-ie-export-start');
		var panel = document.getElementById('tc-ie-export-progress');
		var message = document.getElementById('tc-ie-export-message');
		var bar = document.getElementById('tc-ie-export-bar');
		var retry = document.getElementById('tc-ie-export-retry');
		var cancel = document.getElementById('tc-ie-export-cancel');
		var download = document.getElementById('tc-ie-export-download');

		var activeJob = '';
		var processing = false;

		function selectedMeta() {
			return config.types && config.types[type.value] ? config.types[type.value] : {};
		}

		function updateTypeUi() {
			var meta = selectedMeta();
			description.textContent = meta.description || '';
			var group = meta.filters || '';
			if (productFilters) {
				productFilters.style.display = (group === 'products' || group === 'categories') ? '' : 'none';
			}
			if (statuses) {
				statuses.style.display = (group === 'products' || group === 'content') ? '' : 'none';
			}
		}

		function filterChildOptions(select, parentId) {
			if (!select) {
				return;
			}
			var current = select.value;
			var visibleCurrent = false;
			Array.prototype.forEach.call(select.options, function (option, index) {
				if (index === 0) {
					option.hidden = false;
					option.disabled = false;
					return;
				}
				var parent = option.getAttribute('data-parent') || '0';
				var visible = !parentId || parent === String(parentId);
				option.hidden = !visible;
				option.disabled = !visible;
				if (visible && option.value === current) {
					visibleCurrent = true;
				}
			});
			if (!visibleCurrent && current !== '0') {
				select.value = '0';
			}
		}

		function updateHierarchy() {
			if (!cluster || !primary || !secondary) {
				return;
			}
			filterChildOptions(primary, cluster.value === '0' ? '' : cluster.value);
			filterChildOptions(secondary, primary.value === '0' ? '' : primary.value);
		}

		function request(action, fields) {
			var body = new URLSearchParams();
			body.append('action', action);
			body.append('nonce', config.nonce);
			if (fields) {
				fields.forEach(function (value, key) {
					body.append(key, value);
				});
			}
			return fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
				body: body.toString()
			}).then(function (response) {
				return response.json().catch(function () {
					throw new Error('Respuesta no válida del servidor (HTTP ' + response.status + ').');
				}).then(function (result) {
					if (!response.ok || !result || !result.success) {
						throw new Error(
							(result && result.data && result.data.message) ||
							('Error HTTP ' + response.status)
						);
					}
					return result.data || {};
				});
			});
		}

		function showPanel() {
			panel.style.display = '';
		}

		function setProgress(data) {
			var processed = Number(data.processed || 0);
			var total = Number(data.total || 0);
			var pct = total > 0 ? Math.min(100, (processed / total) * 100) : (data.complete ? 100 : 0);
			bar.value = pct;
			message.textContent = (data.typeLabel ? data.typeLabel + ' · ' : '') +
				processed + (total ? ' / ' + total : '') + ' registros procesados' +
				(data.complete ? '. CSV terminado.' : '. Generando por bloques…');
		}

		function setBusy(value) {
			processing = value;
			start.disabled = value;
			cancel.style.display = activeJob && !value ? '' : (activeJob ? '' : 'none');
		}

		function showError(error) {
			showPanel();
			message.textContent = 'Exportación interrumpida: ' + (error && error.message ? error.message : error);
			retry.style.display = activeJob ? '' : 'none';
			cancel.style.display = activeJob ? '' : 'none';
		}

		function showComplete(data) {
			setBusy(false);
			retry.style.display = 'none';
			cancel.style.display = 'none';
			download.href = data.downloadUrl || '#';
			download.style.display = data.downloadUrl ? '' : 'none';
			setProgress(data);
			if (data.downloadUrl) {
				download.click();
			}
		}

		function waitBriefly() {
			return new Promise(function (resolve) {
				window.setTimeout(resolve, 120);
			});
		}

		async function runJob() {
			if (!activeJob || processing) {
				return;
			}
			setBusy(true);
			retry.style.display = 'none';
			download.style.display = 'none';
			try {
				for (;;) {
					var fields = new URLSearchParams();
					fields.append('job', activeJob);
					var data = await request('taxocommerce_ie_export_step', fields);
					showPanel();
					setProgress(data);
					if (data.complete) {
						showComplete(data);
						return;
					}
					await waitBriefly();
				}
			} catch (error) {
				setBusy(false);
				showError(error);
			}
		}

		form.addEventListener('submit', async function (event) {
			event.preventDefault();
			if (processing) {
				return;
			}
			showPanel();
			message.textContent = 'Preparando exportación…';
			bar.value = 0;
			retry.style.display = 'none';
			cancel.style.display = 'none';
			download.style.display = 'none';
			activeJob = '';
			setBusy(true);

			try {
				var fields = new FormData(form);
				var params = new URLSearchParams();
				fields.forEach(function (value, key) {
					params.append(key, value);
				});
				var data = await request('taxocommerce_ie_export_start', params);
				activeJob = data.job || '';
				setProgress(data);
				setBusy(false);
				if (!activeJob) {
					throw new Error('El servidor no devolvió un identificador de exportación.');
				}
				cancel.style.display = '';
				await runJob();
			} catch (error) {
				setBusy(false);
				showError(error);
			}
		});

		retry.addEventListener('click', function () {
			runJob();
		});

		cancel.addEventListener('click', async function () {
			if (!activeJob) {
				return;
			}
			cancel.disabled = true;
			try {
				await request('taxocommerce_ie_export_cancel');
				activeJob = '';
				processing = false;
				message.textContent = 'Exportación cancelada. El archivo parcial se ha eliminado.';
				bar.value = 0;
				retry.style.display = 'none';
				cancel.style.display = 'none';
				download.style.display = 'none';
				start.disabled = false;
			} catch (error) {
				showError(error);
			} finally {
				cancel.disabled = false;
			}
		});

		type.addEventListener('change', updateTypeUi);
		if (cluster) {
			cluster.addEventListener('change', function () {
				if (primary) {
					primary.value = '0';
				}
				if (secondary) {
					secondary.value = '0';
				}
				updateHierarchy();
			});
		}
		if (primary) {
			primary.addEventListener('change', function () {
				if (secondary) {
					secondary.value = '0';
				}
				updateHierarchy();
			});
		}

		updateTypeUi();
		updateHierarchy();

		request('taxocommerce_ie_export_status').then(function (data) {
			if (!data.active) {
				return;
			}
			activeJob = data.job || '';
			showPanel();
			setProgress(data);
			if (data.complete) {
				showComplete(data);
				return;
			}
			cancel.style.display = '';
			runJob();
		}).catch(function () {
			// La pantalla de exportación sigue siendo utilizable aunque no se pueda recuperar un job anterior.
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
