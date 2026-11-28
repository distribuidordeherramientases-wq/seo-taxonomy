(function () {
	'use strict';
	const root = document.getElementById('tcsup-inventory-export');
	if (!root) return;
	const start = document.getElementById('tcsup-export-start');
	const stop = document.getElementById('tcsup-export-stop');
	const status = document.getElementById('tcsup-export-status');
	const linked = document.getElementById('tcsup-export-linked');
	const speed = document.getElementById('tcsup-export-speed');
	const strings = window.tcsupInventoryExport || {};
	let cancelled = false;
	function csvLine(row) {
		return row.map(function (value) {
			let cell = String(value == null ? '' : value);
			if (/^[\s\uFEFF]*[=+\-@]/.test(cell) || /^[\t\r\n]/.test(cell)) cell = "'" + cell;
			return '"' + cell.replace(/"/g, '""') + '"';
		}).join(',') + '\r\n';
	}
	stop.addEventListener('click', function () { cancelled = true; stop.disabled = true; });
	start.addEventListener('click', async function () {
		if (start.disabled) return;
		cancelled = false;
		start.disabled = linked.disabled = speed.disabled = true;
		stop.disabled = false;
		const filters = new URLSearchParams({action:'tcsup_inventory_export', _ajax_nonce:root.dataset.nonce, linked:linked.checked?'1':'0', batch_size:speed.value});
		['supplier','selection','country','s'].forEach(function (key) {
			filters.set(key, root.querySelector('[name="'+key+'"]').value);
		});
		let cursor = 0, count = 0;
		const chunks = ['\uFEFF'];
		status.textContent = '0';
		try {
			while (!cancelled) {
				filters.set('cursor', String(cursor));
				const response = await fetch(root.dataset.ajax, {method:'POST',credentials:'same-origin',cache:'no-store',body:filters});
				const payload = await response.json();
				if (!response.ok || !payload.success) throw new Error(strings.error);
				const result = payload.data;
				if (cancelled) break;
				if (chunks.length === 1) chunks.push(csvLine(result.headers));
				chunks.push(result.rows.map(csvLine).join(''));
				count += result.rows.length;
				status.textContent = String(count);
				if (result.done) {
					const url = URL.createObjectURL(new Blob(chunks,{type:'text/csv;charset=utf-8'}));
					const a = document.createElement('a');
					a.href = url;
					a.download = 'taxocommerce-supplier-inventory-' + new Date().toISOString().slice(0,10) + '.csv';
					document.body.appendChild(a);
					a.click();
					a.remove();
					setTimeout(function(){ URL.revokeObjectURL(url); },2000);
					status.textContent = strings.done + count;
					break;
				}
				if (Number(result.cursor) <= cursor) throw new Error(strings.error);
				cursor = Number(result.cursor);
				await new Promise(function(resolve){setTimeout(resolve,150);});
			}
			if (cancelled) status.textContent = strings.stop;
		} catch (error) {
			status.textContent = strings.error;
		} finally {
			start.disabled = linked.disabled = speed.disabled = false;
			stop.disabled = true;
		}
	});
})();
