/* global taxocommerceImagesJobs */
(function () {
	'use strict';

	var config = window.taxocommerceImagesJobs || {};
	var optimizer = document.getElementById('tci-images-optimizer-run');
	var webp = document.getElementById('tci-images-webp-run');
	var status = document.getElementById('tci-images-jobs-status');

	if (!optimizer || !webp || !status || !config.ajax) {
		return;
	}

	var busy = false;

	async function run(type) {
		if (busy) {
			return;
		}

		var isWebp = type === 'webp';
		var confirmed = window.confirm(isWebp
			? (config.confirmWebp || 'La conversión WebP puede cambiar URLs y archivos. Comprueba que dispones de una copia de seguridad. ¿Continuar?')
			: (config.confirmOptimize || 'Se recomprimirán las imágenes conservando dimensiones y formatos. ¿Continuar?'));
		if (!confirmed) {
			return;
		}

		busy = true;
		optimizer.disabled = true;
		webp.disabled = true;
		var action = isWebp ? 'tci_webp_batch' : 'tci_optimizer_batch';
		var nonce = isWebp ? config.webpNonce : config.optimizerNonce;
		var afterId = 0;
		var totalProcessed = 0;
		var totalSaved = 0;
		var errors = 0;
		try {
			for (;;) {
				var params = new URLSearchParams();
				params.set('action', action);
				params.set('nonce', nonce);
				params.set('after_id', String(afterId));
				params.set('limit', isWebp ? '1' : '2');
				var response = await window.fetch(config.ajax, {
					method: 'POST',
					credentials: 'same-origin',
					body: params
				});
				var packet = await response.json();
				if (!response.ok || !packet.success || !packet.data) {
					throw new Error(packet.data && packet.data.message ? packet.data.message : 'El servidor ha rechazado el lote.');
				}
				var current = packet.data;
				var nextId = Number(current.next_after_id || 0);
				if (nextId <= afterId && !current.done) {
					throw new Error('El lote no ha avanzado. Operación detenida para evitar repeticiones.');
				}
				afterId = Math.max(afterId, nextId);
				totalProcessed += Number(current.processed || 0);
				totalSaved += Number(current.bytes_saved || 0);
				errors += Array.isArray(current.errors) ? current.errors.length : 0;
				status.textContent = 'Imágenes revisadas: ' + totalProcessed + '. Ahorro estimado: '
					+ (totalSaved / 1048576).toFixed(2) + ' MB. Incidencias: ' + errors + '.';
				if (current.done) {
					status.textContent += ' Proceso finalizado. Actualiza la página para refrescar el inventario.';
					break;
				}
				await new Promise(function (resolve) { window.setTimeout(resolve, 750); });
			}
		} catch (error) {
			status.textContent += ' Proceso detenido: ' + error.message + ' Puedes volver a iniciarlo cuando se resuelva.';
		} finally {
			busy = false;
			optimizer.disabled = false;
			webp.disabled = false;
		}
	}

	optimizer.addEventListener('click', function () { run('optimize'); });
	webp.addEventListener('click', function () { run('webp'); });
}());
