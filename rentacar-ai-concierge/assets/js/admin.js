/* レンタカー AIコンシェルジュ - 管理画面 */
(function () {
	'use strict';

	// 空車連携タブ: 選んだ取得方法に関係する設定行だけを表示する
	var provider = document.getElementById('rcac_availability_provider');
	if (provider) {
		var sync = function () {
			document.querySelectorAll('tr.rcac-group').forEach(function (tr) {
				tr.style.display = tr.classList.contains('rcac-group-' + provider.value) ? '' : 'none';
			});
		};
		provider.addEventListener('change', sync);
		sync();
	}

	// 動作テストタブ
	var run = document.getElementById('rcac-test-run');
	if (run && window.RCAC_ADMIN) {
		var out = document.getElementById('rcac-test-out');
		var status = document.getElementById('rcac-test-status');
		run.addEventListener('click', function () {
			var message = document.getElementById('rcac-test-message').value;
			run.disabled = true;
			status.textContent = 'Claude に問い合わせ中…';
			out.textContent = '';
			fetch(window.RCAC_ADMIN.restUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.RCAC_ADMIN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify({ message: message })
			})
				.then(function (r) { return r.json(); })
				.then(function (d) {
					var lines = [d.reply || d.message || '(応答なし)'];
					if (d.actions && d.actions.length) {
						lines.push('', '[画面操作] ' + JSON.stringify(d.actions));
					}
					if (d.usage) {
						lines.push('', '[使用量] 入力 ' + d.usage.input + ' / 出力 ' + d.usage.output + ' / キャッシュ読込 ' + d.usage.cache_read + ' / キャッシュ書込 ' + d.usage.cache_write + ' トークン（推定 $' + Number(d.usage.cost_usd).toFixed(4) + '）');
					}
					if (d.error) {
						lines.push('', '[エラー] ' + d.error);
					}
					out.textContent = lines.join('\n');
					status.textContent = d.seconds ? d.seconds + ' 秒' : '';
				})
				.catch(function (e) {
					out.textContent = '通信エラー: ' + e;
					status.textContent = '';
				})
				.finally(function () {
					run.disabled = false;
				});
		});
	}
})();
