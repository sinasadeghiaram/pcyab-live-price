/* قیمت لحظه‌ای — Sina Sadeghiaram */
(function () {
	'use strict';
	var cfg = window.pclpLive;
	if (!cfg || !cfg.pid || !window.fetch || !window.FormData) return;

	var retries = 0;

	function textOf(html) {
		var d = document.createElement('div');
		d.innerHTML = html;
		return (d.textContent || '').replace(/\s+/g, ' ').trim();
	}

	function swap(html) {
		if (+cfg.swap !== 1 || !html) return;
		var sels = String(cfg.selector || '').split(',');
		for (var i = 0; i < sels.length; i++) {
			var s = sels[i].trim(), el = null;
			if (!s) continue;
			try { el = document.querySelector(s); } catch (e) { continue; }
			if (el) {
				if ((el.textContent || '').replace(/\s+/g, ' ').trim() !== textOf(html)) el.innerHTML = html;
				return;
			}
		}
	}

	function ping() {
		var fd = new FormData();
		fd.append('action', cfg.action);
		fd.append('pid', cfg.pid);
		fetch(cfg.ajax, { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.success || !res.data) return;
				if (res.data.status === 'busy' && retries < 3) {
					retries++;
					setTimeout(ping, 7000);
					return;
				}
				retries = 0;
				swap(res.data.price_html);
			})
			.catch(function () {});
	}

	function start() {
		ping();
		if (+cfg.keepalive === 1 && +cfg.every > 0) {
			var n = 0;
			var t = setInterval(function () {
				if (document.visibilityState && document.visibilityState !== 'visible') return;
				n++;
				if (n > +cfg.maxPings) { clearInterval(t); return; }
				ping();
			}, +cfg.every * 1000);
		}
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
	else start();
})();
