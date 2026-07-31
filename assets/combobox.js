/* GENERATED from packages/connector-ui/combobox.js — edit there and run `node scripts/sync-ui-assets.mjs`. */
/**
 * Dependency-free ARIA combobox shared by the HTML-rendering connectors
 * (WordPress admin, Google Sheets sidebar). Behavioural port of the portal's
 * LocationAutocomplete.vue: 250ms debounce, min 2 chars, full combobox ARIA
 * (aria-expanded / aria-activedescendant / listbox / option), ArrowUp/Down
 * wrap-around, Enter selects, Escape closes, mousedown-before-blur close
 * delay, "No matches" row, chip-with-clear filled state.
 *
 * Differences from the Vue original, both deliberate:
 *   - Staleness is handled with a monotonic request sequence number instead
 *     of AbortController — google.script.run cannot abort, so the factory
 *     only drops stale resolutions. Hosts with real fetch may still abort
 *     inside their own fetchSuggestions.
 *   - Multi mode renders a chip row inside the field (Backspace on an empty
 *     input removes the last chip, duplicates are ignored) and an optional
 *     free-text mode commits raw input on Enter/comma — used for skills and
 *     as graceful degradation when the options endpoint is down.
 *
 * Authored as an ES module for vitest; hosts inline it by stripping the
 * `export ` keywords (see scripts/sync-ui-assets.mjs and sheets/build.mjs),
 * which leaves plain function declarations in script scope. Keep the syntax
 * ES2019-safe (no optional chaining / nullish coalescing) so the stripped
 * copy runs untranspiled in every host.
 */

function comboboxInitialState(options) {
  var opts = options || {};
  return {
    multi: Boolean(opts.multi),
    allowFreeText: Boolean(opts.allowFreeText),
    minChars: typeof opts.minChars === "number" ? opts.minChars : 2,
    query: "",
    /** Sequence of the most recent fetch started; stale results are dropped. */
    pendingSeq: 0,
    items: [],
    open: false,
    /** True once a fetch for the current query has completed. */
    searched: false,
    activeIndex: -1,
    /** Committed selections: [{ label, value }]. Single mode uses values[0]. */
    values: Array.isArray(opts.initialValues) ? opts.initialValues.slice() : [],
  };
}

/**
 * Pure state transition — all keyboard/selection behaviour lives here so
 * tests cover it without flaky DOM assertions. Returns a new state; never
 * mutates. Events:
 *   {type:"input", value}            typing (DOM layer debounces the fetch)
 *   {type:"fetch-started", seq}      a fetch was issued for the current query
 *   {type:"results", seq, items}     fetch resolved (dropped when stale)
 *   {type:"arrow-down"} {type:"arrow-up"} {type:"escape"}
 *   {type:"select-active"}           Enter on an active option
 *   {type:"commit-free-text"}        Enter/comma in free-text mode
 *   {type:"remove-value", value}     chip × clicked
 *   {type:"remove-last"}             Backspace on empty input (multi)
 *   {type:"clear"}                   single-mode chip × clicked
 *   {type:"open-if-results"} {type:"close"}
 */
function comboboxReduce(state, event) {
  switch (event.type) {
    case "input": {
      var query = String(event.value == null ? "" : event.value);
      var next = assign(state, { query: query, activeIndex: -1 });
      if (query.trim().length < state.minChars) {
        return assign(next, { items: [], open: false, searched: false });
      }
      return next;
    }

    case "fetch-started":
      return assign(state, { pendingSeq: event.seq });

    case "results": {
      // A slower earlier request must never overwrite a newer one.
      if (event.seq !== state.pendingSeq) return state;
      var items = Array.isArray(event.items) ? event.items : [];
      return assign(state, {
        items: items,
        open: true,
        searched: true,
        activeIndex: items.length > 0 ? 0 : -1,
      });
    }

    case "set-active": {
      if (event.index < 0 || event.index >= state.items.length) return state;
      return assign(state, { activeIndex: event.index });
    }

    case "arrow-down":
    case "arrow-up": {
      var count = state.items.length;
      var open = state.open || count > 0 || state.searched;
      if (count === 0) return assign(state, { open: open });
      var delta = event.type === "arrow-down" ? 1 : -1;
      var active = (state.activeIndex + delta + count) % count;
      return assign(state, { open: open, activeIndex: active });
    }

    case "escape":
    case "close":
      return assign(state, { open: false, activeIndex: -1 });

    case "open-if-results":
      return assign(state, { open: state.items.length > 0 || state.searched });

    case "select-active": {
      if (!state.open || state.activeIndex < 0) return state;
      var item = state.items[state.activeIndex];
      if (!item) return state;
      return commitValue(state, item);
    }

    case "select-item":
      return commitValue(state, event.item);

    case "commit-free-text": {
      if (!state.allowFreeText) return state;
      var text = state.query.trim().replace(/,+$/, "");
      if (text.length === 0) return state;
      return commitValue(state, { label: text, value: text });
    }

    case "remove-value":
      return assign(state, {
        values: state.values.filter(function (v) {
          return v.value !== event.value;
        }),
      });

    case "remove-last": {
      if (!state.multi || state.query.length > 0 || state.values.length === 0) return state;
      return assign(state, { values: state.values.slice(0, -1) });
    }

    case "clear":
      return assign(state, {
        values: [],
        query: "",
        items: [],
        open: false,
        searched: false,
        activeIndex: -1,
      });

    default:
      return state;
  }
}

function commitValue(state, item) {
  var exists = state.values.some(function (v) {
    return v.value === item.value;
  });
  var values = state.multi ? (exists ? state.values : state.values.concat([item])) : [item];
  return assign(state, {
    values: values,
    query: "",
    items: [],
    open: false,
    searched: false,
    activeIndex: -1,
  });
}

function assign(state, patch) {
  var next = {};
  var key;
  for (key in state) next[key] = state[key];
  for (key in patch) next[key] = patch[key];
  return next;
}

var joboComboboxUid = 0;

/**
 * Mounts a combobox into `root` (an empty block element).
 *
 * opts:
 *   fetchSuggestions(query) -> Promise<Array<{label, value, count?}>>
 *       Optional — omit for a pure free-text chip input.
 *   onChange(values)   values: [{label, value}] after every commit/removal.
 *   multi              chip row (true) vs single chip-with-clear (false).
 *   allowFreeText      Enter/comma commits raw text as a chip.
 *   minChars (2), debounceMs (250)
 *   placeholder, ariaLabel, noMatchesText ("No matches")
 *   dense              36px field for narrow hosts (Sheets sidebar).
 *   initialValues      [{label, value}]
 *
 * Returns { getValues, setValues, destroy, root }.
 */
function createJoboCombobox(root, opts) {
  var options = opts || {};
  var fetchSuggestions = options.fetchSuggestions || null;
  var onChange = options.onChange || function () {};
  var debounceMs = typeof options.debounceMs === "number" ? options.debounceMs : 250;
  var noMatchesText = options.noMatchesText || "No matches";

  var state = comboboxInitialState(options);
  var seq = 0;
  var debounceTimer = null;
  var blurTimer = null;

  joboComboboxUid += 1;
  var instanceId = "jobo-cb-" + joboComboboxUid;
  var listboxId = instanceId + "-listbox";

  root.classList.add("jobo-cb");
  if (options.dense) root.classList.add("jobo-cb--dense");

  var field = document.createElement("div");
  field.className = "jobo-cb-field";

  var input = document.createElement("input");
  input.type = "text";
  input.className = "jobo-cb-input";
  input.id = instanceId;
  input.setAttribute("role", "combobox");
  input.setAttribute("aria-autocomplete", "list");
  input.setAttribute("aria-expanded", "false");
  input.setAttribute("aria-controls", listboxId);
  input.autocomplete = "off";
  if (options.placeholder) input.placeholder = options.placeholder;
  if (options.ariaLabel) input.setAttribute("aria-label", options.ariaLabel);

  var list = document.createElement("ul");
  list.className = "jobo-cb-list";
  list.id = listboxId;
  list.setAttribute("role", "listbox");
  list.hidden = true;

  field.appendChild(input);
  root.appendChild(field);
  root.appendChild(list);

  function dispatch(event) {
    var previous = state;
    state = comboboxReduce(state, event);
    if (previous.values !== state.values) onChange(state.values.slice());
    render();
  }

  function issueFetch(query) {
    if (!fetchSuggestions) return;
    seq += 1;
    var mySeq = seq;
    dispatch({ type: "fetch-started", seq: mySeq });
    Promise.resolve(fetchSuggestions(query)).then(
      function (items) {
        dispatch({ type: "results", seq: mySeq, items: items });
      },
      function () {
        // Unreachable endpoint: keep prior suggestions; free-text (when
        // enabled) still works, so the field never hard-fails.
      }
    );
  }

  input.addEventListener("input", function () {
    dispatch({ type: "input", value: input.value });
    if (debounceTimer) clearTimeout(debounceTimer);
    var trimmed = input.value.trim();
    if (trimmed.length < state.minChars) return;
    debounceTimer = setTimeout(function () {
      issueFetch(trimmed);
    }, debounceMs);
  });

  input.addEventListener("keydown", function (event) {
    if (event.key === "ArrowDown") {
      event.preventDefault();
      dispatch({ type: "arrow-down" });
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      dispatch({ type: "arrow-up" });
    } else if (event.key === "Enter") {
      if (state.open && state.activeIndex >= 0) {
        event.preventDefault();
        dispatch({ type: "select-active" });
      } else if (state.allowFreeText && state.query.trim().length > 0) {
        event.preventDefault();
        dispatch({ type: "commit-free-text" });
      }
    } else if (event.key === "," && state.allowFreeText) {
      event.preventDefault();
      dispatch({ type: "commit-free-text" });
    } else if (event.key === "Escape") {
      if (state.open) {
        event.preventDefault();
        dispatch({ type: "escape" });
      }
    } else if (event.key === "Backspace" && input.value.length === 0) {
      dispatch({ type: "remove-last" });
    }
  });

  input.addEventListener("focus", function () {
    if (blurTimer) clearTimeout(blurTimer);
    dispatch({ type: "open-if-results" });
  });

  input.addEventListener("blur", function () {
    // Delay so a mousedown on an option registers before the list closes.
    blurTimer = setTimeout(function () {
      dispatch({ type: "close" });
    }, 150);
  });

  field.addEventListener("mousedown", function (event) {
    if (event.target === field) {
      event.preventDefault();
      input.focus();
    }
  });

  function render() {
    input.value = state.query;
    input.setAttribute("aria-expanded", state.open ? "true" : "false");
    if (state.open && state.activeIndex >= 0) {
      input.setAttribute("aria-activedescendant", instanceId + "-option-" + state.activeIndex);
    } else {
      input.removeAttribute("aria-activedescendant");
    }

    renderChips();
    renderList();
  }

  function renderChips() {
    var chips = field.querySelectorAll(".jobo-cb-chip");
    for (var i = 0; i < chips.length; i++) field.removeChild(chips[i]);

    state.values.forEach(function (item) {
      var chip = document.createElement("span");
      chip.className = "jobo-cb-chip";

      var label = document.createElement("span");
      label.className = "jobo-cb-chip-label";
      label.textContent = item.label;
      chip.appendChild(label);

      var remove = document.createElement("button");
      remove.type = "button";
      remove.className = "jobo-cb-chip-x";
      remove.setAttribute("aria-label", "Remove " + item.label);
      remove.textContent = "×";
      remove.addEventListener("click", function () {
        dispatch(state.multi ? { type: "remove-value", value: item.value } : { type: "clear" });
        input.focus();
      });
      chip.appendChild(remove);

      field.insertBefore(chip, input);
    });

    // Single mode with a committed value reads as a filled chip: hide the
    // input until the chip is cleared, mirroring the portal picker.
    var filledSingle = !state.multi && state.values.length > 0;
    input.hidden = filledSingle;
  }

  function renderList() {
    list.hidden = !state.open;
    while (list.firstChild) list.removeChild(list.firstChild);
    if (!state.open) return;

    state.items.forEach(function (item, index) {
      var option = document.createElement("li");
      option.className = "jobo-cb-option";
      option.id = instanceId + "-option-" + index;
      option.setAttribute("role", "option");
      option.setAttribute("aria-selected", state.activeIndex === index ? "true" : "false");

      var label = document.createElement("span");
      label.textContent = item.label;
      option.appendChild(label);

      if (typeof item.count === "number") {
        var count = document.createElement("span");
        count.className = "jobo-cb-option-count";
        count.textContent = String(item.count);
        option.appendChild(count);
      }

      option.addEventListener("mousemove", function () {
        if (state.activeIndex !== index) dispatch({ type: "set-active", index: index });
      });
      option.addEventListener("mousedown", function (event) {
        event.preventDefault();
        dispatch({ type: "select-item", item: item });
      });

      list.appendChild(option);
    });

    if (state.searched && state.items.length === 0) {
      var empty = document.createElement("li");
      empty.className = "jobo-cb-empty";
      empty.setAttribute("role", "option");
      empty.setAttribute("aria-disabled", "true");
      empty.textContent = noMatchesText;
      list.appendChild(empty);
    }
  }

  render();

  return {
    root: root,
    getValues: function () {
      return state.values.slice();
    },
    setValues: function (values) {
      state = assign(state, { values: Array.isArray(values) ? values.slice() : [] });
      render();
    },
    destroy: function () {
      if (debounceTimer) clearTimeout(debounceTimer);
      if (blurTimer) clearTimeout(blurTimer);
      while (root.firstChild) root.removeChild(root.firstChild);
      root.classList.remove("jobo-cb", "jobo-cb--dense");
    },
  };
}
