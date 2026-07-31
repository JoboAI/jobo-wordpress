/**
 * Settings-screen behaviour: the locations and sources comboboxes, the
 * connection test, and the client-side key format check.
 *
 * Plain ES2019, no modules and no build step. Depends on combobox.js
 * (createJoboCombobox) and reads its configuration from the global `joboAdmin`
 * object injected by wp_add_inline_script().
 *
 * Progressive enhancement contract: the PHP form is complete without this
 * file. The locations <textarea> and the sources hidden input are the values
 * that actually submit — the comboboxes only decorate them — so disabling
 * JavaScript degrades to the plain fields and Jobo_Settings::sanitize() is
 * none the wiser.
 */

/* global joboAdmin, createJoboCombobox */
(function () {
	"use strict";

	if (typeof joboAdmin === "undefined" || typeof createJoboCombobox !== "function") {
		return;
	}

	var i18n = joboAdmin.i18n || {};

	function t(key, fallback) {
		return typeof i18n[key] === "string" && i18n[key] !== "" ? i18n[key] : fallback;
	}

	/** "12480" -> "12 480" (non-breaking spaces), matching the PHP side. */
	function formatNumber(n) {
		return String(Math.round(Number(n))).replace(/\B(?=(\d{3})+(?!\d))/g, "\u00a0");
	}

	/**
	 * POST to admin-ajax. Resolves with the `data` payload of a success
	 * envelope; rejects with an Error carrying a readable message for both
	 * non-2xx responses and success:false envelopes.
	 */
	function ajaxPost(action, fields, signal) {
		var body = new URLSearchParams();
		body.set("action", action);
		body.set("nonce", joboAdmin.nonce);
		Object.keys(fields || {}).forEach(function (key) {
			body.set(key, fields[key]);
		});

		var init = {
			method: "POST",
			credentials: "same-origin",
			headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
			body: body.toString(),
		};
		if (signal) {
			init.signal = signal;
		}

		return fetch(joboAdmin.ajaxUrl, init).then(function (response) {
			return response
				.json()
				.catch(function () {
					return null;
				})
				.then(function (json) {
					if (!response.ok || !json || json.success !== true) {
						var message =
							json && json.data && typeof json.data.message === "string" && json.data.message !== ""
								? json.data.message
								: t("requestFailed", "The request failed (HTTP " + response.status + ").").replace("%d", String(response.status));
						var error = new Error(message);
						error.status = response.status;
						throw error;
					}
					return json.data;
				});
		});
	}

	/* ── Locations combobox ─────────────────────────────────────────────── */

	function mountLocations() {
		var textarea = document.getElementById("jobo_locations");
		var mount = document.getElementById("jobo-locations-cb");
		if (!textarea || !mount) {
			return;
		}

		// Hidden only once JS has taken over, so the no-JS path keeps the
		// plain textarea. The class keeps it in the DOM and submittable.
		textarea.classList.add("jobo-visually-hidden");
		textarea.setAttribute("aria-hidden", "true");
		textarea.tabIndex = -1;

		var initialLines = textarea.value
			.split(/\r\n|\r|\n/)
			.map(function (line) {
				return line.trim();
			})
			.filter(function (line) {
				return line.length > 0;
			});

		var abortController = null;

		createJoboCombobox(mount, {
			multi: true,
			// Free text keeps the field usable when the suggest endpoint is
			// down — the server accepts any "City, Region, Country" line.
			allowFreeText: true,
			minChars: 2,
			debounceMs: 250,
			placeholder: t("locationsPlaceholder", "Search for a city, region or country"),
			ariaLabel: t("locationsAriaLabel", "Locations"),
			noMatchesText: t("noMatches", "No matches"),
			initialValues: initialLines.map(function (line) {
				return { label: line, value: line };
			}),
			fetchSuggestions: function (query) {
				if (abortController) {
					abortController.abort();
				}
				abortController = typeof AbortController === "function" ? new AbortController() : null;

				return ajaxPost("jobo_suggest_locations", { q: query }, abortController ? abortController.signal : undefined).then(function (data) {
					var suggestions = data && Array.isArray(data.suggestions) ? data.suggestions : [];
					return suggestions
						.map(function (s) {
							var label =
								s.display_name ||
								[s.city, s.region, s.country]
									.filter(function (part) {
										return typeof part === "string" && part !== "";
									})
									.join(", ");
							return { label: label, value: label };
						})
						.filter(function (item) {
							return item.label !== "";
						});
				});
			},
			onChange: function (values) {
				// One "City, Region, Country"-ish line per chip, straight into
				// the real form field so parse_locations() stays untouched.
				textarea.value = values
					.map(function (v) {
						return v.value;
					})
					.join("\n");
			},
		});
	}

	/* ── Sources combobox ───────────────────────────────────────────────── */

	function mountSources() {
		var hidden = document.getElementById("jobo_sources");
		var mount = document.getElementById("jobo-sources-cb");
		if (!hidden || !mount) {
			return;
		}

		// One-shot options load; every keystroke filters locally.
		var sourcesPromise = ajaxPost("jobo_filter_options", {}).then(function (data) {
			return data && Array.isArray(data.sources) ? data.sources : [];
		});
		// Mark handled so a failed load never surfaces as an unhandled
		// rejection — the combobox falls back to free-text chips.
		sourcesPromise.catch(function () {});

		var saved = Array.isArray(joboAdmin.savedSources) ? joboAdmin.savedSources : [];

		createJoboCombobox(mount, {
			multi: true,
			// Free text is the fallback when the options call fails; it also
			// lets power users paste keys the picker has not loaded yet.
			allowFreeText: true,
			minChars: 1,
			debounceMs: 100,
			placeholder: t("sourcesPlaceholder", "Type to filter sources, for example greenhouse"),
			ariaLabel: t("sourcesAriaLabel", "Sources"),
			noMatchesText: t("noMatches", "No matches"),
			initialValues: saved.map(function (key) {
				return { label: key, value: key };
			}),
			fetchSuggestions: function (query) {
				var needle = query.toLowerCase();
				return sourcesPromise
					.then(function (options) {
						return options
							.filter(function (key) {
								return String(key).toLowerCase().indexOf(needle) !== -1;
							})
							.slice(0, 20)
							.map(function (key) {
								return { label: key, value: key };
							});
					})
					.catch(function () {
						// Endpoint down: no suggestions, free text still works.
						return [];
					});
			},
			onChange: function (values) {
				hidden.value = values
					.map(function (v) {
						return v.value;
					})
					.join(",");
			},
		});
	}

	/* ── API key: format check + test connection ────────────────────────── */

	function isMasked(value) {
		return /^\*+$/.test(value);
	}

	function wireConnection() {
		var keyInput = document.getElementById("jobo_api_key");
		var button = document.getElementById("jobo-test-connection");
		var spinner = document.getElementById("jobo-test-spinner");
		var result = document.getElementById("jobo-test-result");
		var help = document.getElementById("jobo-key-format-help");

		var keyRegex = null;
		try {
			keyRegex = new RegExp(joboAdmin.keyPattern);
		} catch (e) {
			keyRegex = null;
		}

		function keyLooksValid() {
			if (!keyInput || !keyRegex) {
				return true;
			}
			var value = keyInput.value.trim();
			// Empty and masked both mean "the saved key" — nothing to flag.
			return value === "" || isMasked(value) || keyRegex.test(value);
		}

		function renderKeyValidity() {
			var ok = keyLooksValid();
			if (keyInput) {
				keyInput.classList.toggle("jobo-input--invalid", !ok);
			}
			if (help) {
				help.hidden = ok;
			}
			return ok;
		}

		if (keyInput) {
			keyInput.addEventListener("blur", renderKeyValidity);
			keyInput.addEventListener("input", function () {
				// Only clear an existing flag while typing; fresh flags wait
				// for blur so the user is not scolded mid-paste.
				if (keyInput.classList.contains("jobo-input--invalid") && keyLooksValid()) {
					renderKeyValidity();
				}
			});
		}

		if (!button || !result) {
			return;
		}

		button.addEventListener("click", function () {
			if (!renderKeyValidity()) {
				result.hidden = true;
				return;
			}

			button.disabled = true;
			if (spinner) {
				spinner.hidden = false;
			}
			result.hidden = true;

			ajaxPost("jobo_test_connection", { key: keyInput ? keyInput.value : "" })
				.then(function (data) {
					result.className = "jobo-test-result jobo-test-result--success";
					if (data && data.credits_balance !== null && data.credits_balance !== undefined) {
						result.textContent = t("connectedCredits", "Connected — %s credits remaining").replace("%s", formatNumber(data.credits_balance));
					} else {
						result.textContent = t("connected", "Connected");
					}
				})
				.catch(function (error) {
					result.className = "jobo-test-result jobo-test-result--critical";
					result.textContent = error && error.message ? error.message : t("connectionFailed", "The connection test failed.");
				})
				.finally(function () {
					button.disabled = false;
					if (spinner) {
						spinner.hidden = true;
					}
					result.hidden = false;
				});
		});
	}

	function init() {
		mountLocations();
		mountSources();
		wireConnection();
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", init);
	} else {
		init();
	}
})();
