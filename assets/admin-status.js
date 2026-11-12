(function () {
	'use strict';

	const config = window.aitfpTranslationStatus;
	if (!config) {
		return;
	}

	const notices = Array.from(document.querySelectorAll('[data-aitfp-status-notice]'));
	if (!notices.length) {
		return;
	}

	const strings = config.strings;
	const format = (template, ...values) => template.replace(/%s|%[1-9]\$s/g, (match) => {
		const index = match === '%s' ? 0 : Number(match.slice(1, -2)) - 1;
		return values[index] ?? '';
	});

	function clearQueuedParam() {
		const url = new URL(window.location.href);
		if (url.searchParams.has('aitfp_queued')) {
			url.searchParams.delete('aitfp_queued');
			window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
		}
	}

	const hasListNotice = notices.some((notice) => notice.dataset.aitfpEditor !== 'true');
	if (hasListNotice && !config.active) {
		clearQueuedParam();
	}

	function addLink(parent, href, label) {
		if (!href) {
			return;
		}

		parent.append(document.createTextNode(' '));
		const link = document.createElement('a');
		link.href = href;
		link.textContent = label;
		parent.append(link);
	}

	function updateNotice(notice, job) {
		const message = notice.querySelector('[data-aitfp-status-message]');
		const isEditor = notice.dataset.aitfpEditor === 'true';
		const language = job.language || '';
		message.replaceChildren();

		notice.classList.remove('notice-info', 'notice-success', 'notice-warning', 'notice-error');

		if (job.status === 'pending') {
			notice.classList.add('notice-info');
			message.append(document.createTextNode(format(strings.pending, language)));
			if (isEditor) {
				message.append(document.createTextNode(` ${strings.editorWarning}`));
			} else {
				message.append(document.createTextNode(` ${strings.autoUpdate}`));
			}
		} else if (job.status === 'processing') {
			notice.classList.add('notice-info');
			message.append(document.createTextNode(format(strings.processing, language)));
			if (isEditor) {
				message.append(document.createTextNode(` ${strings.editorWarning}`));
			}
		} else if (job.status === 'done') {
			notice.classList.add('notice-success');
			if (isEditor) {
				message.append(document.createTextNode(strings.reloadEditor));
				addLink(message, window.location.href, strings.reload);
			} else {
				message.append(document.createTextNode(format(strings.ready, language)));
				addLink(message, job.editUrl, strings.openDraft);
			}
		} else if (job.status === 'failed') {
			notice.classList.add('notice-error');
			message.append(document.createTextNode(format(strings.failedMessage, language, job.error || strings.failed)));
			addLink(message, job.retryUrl, strings.retry);
		}

		// A stale job is still "processing" but can be retried.
		if (job.active && job.retryable) {
			addLink(message, job.retryUrl, strings.retry);
		}
	}

	function updatePostState(job) {
		const state = document.querySelector(`[data-aitfp-post-state][data-aitfp-post-id="${config.postId}"]`);
		if (!state) {
			return;
		}

		state.replaceChildren();
		if (job.status === 'pending' || job.status === 'processing') {
			state.textContent = format(job.status === 'pending' ? strings.pending : strings.processing, job.language || '');
		} else if (job.status === 'done') {
			state.textContent = strings.done;
		} else if (job.status === 'failed') {
			const details = document.createElement('details');
			details.className = 'aitfp-translation-error';
			const summary = document.createElement('summary');
			summary.textContent = strings.failed;
			const error = document.createElement('p');
			error.textContent = job.error || strings.failed;
			details.append(summary, error);
			state.append(details);
		}
	}

	function showPollError(notice, message = strings.pollError) {
		let error = notice.querySelector('[data-aitfp-poll-error]');
		if (!error) {
			error = document.createElement('p');
			error.dataset.aitfpPollError = '';
			error.setAttribute('role', 'alert');
			notice.append(error);
		}
		error.textContent = message;
	}

	function clearPollError(notice) {
		notice.querySelector('[data-aitfp-poll-error]')?.remove();
	}

	async function poll(notice) {
		try {
			const response = await fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: new URLSearchParams({
					action: 'aitfp_translation_status',
					post_id: String(config.postId),
					nonce: config.nonce,
				}),
			});
			if (response.status === 403 || response.status === 404) {
				showPollError(notice, strings.statusError);
				return;
			}

			const payload = await response.json();
			if (!response.ok || !payload.success) {
				throw new Error(payload.data?.message || strings.statusError);
			}

			clearPollError(notice);
			updateNotice(notice, payload.data);
			updatePostState(payload.data);
			if (!payload.data.active) {
				if (hasListNotice) {
					clearQueuedParam();
				}
				return;
			}
		} catch {
			showPollError(notice);
		}

		window.setTimeout(() => poll(notice), config.interval);
	}

	if (config.active) {
		poll(notices[0]);
	}
})();
