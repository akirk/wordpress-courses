(function () {
	'use strict';

	function markdownToolbarButtons() {
		if (!window.defaultToolbarButtons || !window.defaultToolbarButtons.length) {
			return null;
		}

		var buttons = window.defaultToolbarButtons.filter(function (button) {
			return button && button.name !== 'viewMode';
		});

		while (buttons.length && buttons[buttons.length - 1].name === 'separator') {
			buttons.pop();
		}

		return buttons;
	}

	function looksLikeUrl(text) {
		return /^(https?:\/\/|mailto:|ftp:\/\/|\/|#|\?|\.)\S+$/i.test(text.trim());
	}

	function replaceSelection(ta, replacement, selectionStart, selectionEnd) {
		var start = ta.selectionStart;
		var end = ta.selectionEnd;
		ta.value = ta.value.slice(0, start) + replacement + ta.value.slice(end);
		ta.selectionStart = start + selectionStart;
		ta.selectionEnd = start + selectionEnd;
		ta.dispatchEvent(new Event('input', { bubbles: true }));

		if (ta._wordpressCoursesOvertypeEditor && typeof ta._wordpressCoursesOvertypeEditor.updatePreview === 'function') {
			ta._wordpressCoursesOvertypeEditor.updatePreview();
		}
	}

	function setupPasteLinkWrapping(ta) {
		if (ta._wordpressCoursesPasteLinkWrappingReady) {
			return;
		}

		ta._wordpressCoursesPasteLinkWrappingReady = true;
		ta.addEventListener('paste', function (event) {
			var start = ta.selectionStart;
			var end = ta.selectionEnd;

			if (typeof start !== 'number' || typeof end !== 'number' || start === end) {
				return;
			}

			var pasted = event.clipboardData && event.clipboardData.getData('text/plain');
			if (!pasted || !looksLikeUrl(pasted)) {
				return;
			}

			var selected = ta.value.slice(start, end);
			if (/^\[[^\]]+\]\([^)]+\)$/.test(selected)) {
				return;
			}

			event.preventDefault();
			var replacement = '[' + selected + '](' + pasted.trim() + ')';
			replaceSelection(ta, replacement, 0, replacement.length);
		});
	}

	function setupSelectionWrapping(ta) {
		if (ta._wordpressCoursesSelectionWrappingReady) {
			return;
		}

		ta._wordpressCoursesSelectionWrappingReady = true;
		ta.addEventListener('keydown', function (event) {
			if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
				var form = ta.form || ta.closest('form');
				if (!form) {
					return;
				}
				event.preventDefault();
				if (typeof form.requestSubmit === 'function') {
					form.requestSubmit();
				} else {
					form.submit();
				}
				return;
			}

			if (event.altKey || event.ctrlKey || event.metaKey) {
				return;
			}

			var pairs = {
				'`': ['`', '`'],
				'*': ['**', '**'],
				'_': ['_', '_'],
				'(': ['(', ')'],
				')': ['(', ')'],
				'[': ['[', ']'],
				']': ['[', ']'],
			};
			var pair = pairs[event.key];
			if (!pair) {
				return;
			}

			var start = ta.selectionStart;
			var end = ta.selectionEnd;
			if (typeof start !== 'number' || typeof end !== 'number' || start === end) {
				return;
			}

			event.preventDefault();
			var selected = ta.value.slice(start, end);
			var replacement = pair[0] + selected + pair[1];
			replaceSelection(ta, replacement, pair[0].length, pair[0].length + selected.length);
		});
	}

	function keepToolbarOutOfTabOrder(host) {
		var buttons = host.querySelectorAll('.overtype-toolbar button, .overtype-toolbar [tabindex]');
		Array.prototype.forEach.call(buttons, function (button) {
			button.setAttribute('tabindex', '-1');
		});
	}

	function initNotesEditor() {
		var form = document.querySelector('[data-notes-form]');
		if (!form) {
			return;
		}

		var source = form.querySelector('textarea[data-notes-source]');
		var host = form.querySelector('[data-notes-editor]');
		if (!source || !host) {
			return;
		}

		setupPasteLinkWrapping(source);
		setupSelectionWrapping(source);

		if (!window.OverType || host.dataset.wordpressCoursesOvertypeReady) {
			return;
		}

		var styles = window.getComputedStyle(document.documentElement);
		function cssVar(name, fallback) {
			var value = styles.getPropertyValue(name).trim();
			return value || fallback;
		}

		var theme = {
			name: 'learn-app',
			colors: {
				bgPrimary: cssVar('--wp-app-color-surface-alt', '#ffffff'),
				bgSecondary: cssVar('--wp-app-color-surface', '#f6f7f7'),
				border: cssVar('--wp-app-color-border', '#dcdcde'),
				text: cssVar('--wp-app-color-text', '#1d2327'),
				textPrimary: cssVar('--wp-app-color-text', '#1d2327'),
				textSecondary: cssVar('--wp-app-color-muted', '#646970'),
				primary: cssVar('--wp-app-color-link', '#2271b1'),
				link: cssVar('--wp-app-color-link', '#2271b1'),
				cursor: cssVar('--wp-app-color-link', '#2271b1'),
				selection: 'rgba(34, 113, 177, 0.22)',
				codeBg: cssVar('--wp-app-color-surface', '#f6f7f7'),
				toolbarBg: cssVar('--wp-app-color-surface-alt', '#ffffff'),
				toolbarBorder: cssVar('--wp-app-color-border', '#dcdcde'),
				toolbarHover: cssVar('--wp-app-color-surface', '#f6f7f7'),
				toolbarIcon: cssVar('--wp-app-color-text', '#1d2327'),
				syntaxMarker: cssVar('--wp-app-color-muted', '#646970'),
			},
		};

		var editors = new window.OverType(host, {
			value: source.value.replace(/\s*$/, '\n\n'),
			theme: theme,
			toolbar: true,
			toolbarButtons: markdownToolbarButtons(),
			showStats: true,
			smartLists: true,
			spellcheck: true,
			fontSize: '14px',
			lineHeight: 1.55,
			minHeight: '170px',
			maxHeight: '360px',
			textareaProps: {
				'aria-label': source.getAttribute('aria-label') || 'Course notes markdown',
			},
			onChange: function (value, editor) {
				source.value = value;
				editor.textarea._wordpressCoursesOvertypeEditor = editor;
				setupPasteLinkWrapping(editor.textarea);
				setupSelectionWrapping(editor.textarea);
			},
		});

		var editor = editors && editors[0];
		if (!editor) {
			return;
		}

		host.dataset.wordpressCoursesOvertypeReady = '1';
		host.classList.add('is-ready');
		source.classList.add('notes-source-hidden');
		source.setAttribute('tabindex', '-1');
		source.setAttribute('aria-hidden', 'true');
		editor.textarea._wordpressCoursesOvertypeEditor = editor;
		source._wordpressCoursesOvertypeEditor = editor;
		setupPasteLinkWrapping(editor.textarea);
		setupSelectionWrapping(editor.textarea);
		keepToolbarOutOfTabOrder(host);

		form.addEventListener('submit', function () {
			source.value = editor.getValue();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initNotesEditor);
	} else {
		initNotesEditor();
	}
})();
