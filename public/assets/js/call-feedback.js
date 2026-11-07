/* Call Feedback for MikoPBX | GPL-3.0-or-later | built from public/assets/js/src */
(() => {
  // public/assets/js/src/call-feedback.js
  var BASE = `${globalRootUrl}module-call-feedback/`;
  var t = (key, fallback) => globalTranslate && globalTranslate[key] || fallback;
  var SCALES = {
    csat5: ["cf_ScaleCsat", "CSAT: satisfaction, 1\u20135"],
    nps11: ["cf_ScaleNps", "NPS: likely to recommend, 0\u201310"],
    ces5: ["cf_ScaleCes", "CES: how easy it was, 1\u20135"],
    yesno: ["cf_ScaleYesNo", "Yes or no (1 yes, 2 no)"]
  };
  var HEADLINE_SCALES = ["csat5", "nps11", "ces5"];
  var LANGUAGES = { "en-en": ["cf_LanguageEnglish", "English"], "ru-ru": ["cf_LanguageRussian", "Russian"] };
  var STATUSES = {
    draft: ["cf_StatusDraft", "Draft", ""],
    active: ["cf_StatusActive", "Active", "green"],
    archived: ["cf_StatusArchived", "Archived", "grey"]
  };
  var PROBLEMS = {
    cf_CallBackNoNumber: "This case has no number to call back.",
    cf_CallBackTypeNumber: "Type the caller's number exactly to call it back.",
    cf_CallBackTooSoon: "This case was called back a moment ago. Wait half a minute before trying again.",
    cf_ProblemNotDraft: "Only a draft can be activated.",
    cf_ProblemNoQuestions: "Add at least one question.",
    cf_ProblemMissingPrompt: "Choose a sound file for the intro, the thank-you and every question.",
    cf_ProblemPromptMissingFile: "A chosen sound file can no longer be played. Choose it again or pick another.",
    cf_ProblemNoTestCall: "Place a test call on this version first.",
    cf_ProblemNoTargets: "Choose at least one route.",
    cf_ProblemTargetTaken: "Another active survey already runs on one of these routes.",
    cf_ProblemDefaultActionPlayback: "The PBX's default incoming action plays a sound file, so no inbound survey can run. Change it under Incoming routes.",
    cf_ProblemOtherSurveyModule: "Another post-call survey module is enabled and would survey the same calls. Disable it first."
  };
  var PROMPT_FIELDS = [
    ["intro_prompt_id", "cf_PromptIntro", 'Intro: "Please stay on the line for a short survey"'],
    ["invalid_prompt_id", "cf_PromptInvalid", 'Invalid key (optional): "Sorry, that key is not valid"'],
    ["thanks_prompt_id", "cf_PromptThanks", "Thank you"]
  ];
  var RULE_FIELDS = [
    ["min_talk_seconds", "cf_MinTalk", "Minimum talk time (seconds)", 0, 600],
    ["sample_percent", "cf_Sampling", "Survey this share of calls (%)", 1, 100],
    ["frequency_cap_days", "cf_FrequencyCap", "Don't ask the same number again for (days, 0 = always ask)", 0, 365]
  ];
  var state = { options: null, editing: null, testPoll: null, tab: "overview", versions: [], labels: null, charts: {} };
  var METRICS = {
    csat5: ["cf_MetricCsat", "CSAT", "%"],
    nps11: ["cf_MetricNps", "NPS", ""],
    ces5: ["cf_MetricCes", "Easy (CES)", "%"]
  };
  var WEEKDAYS = [["cf_Mon", "Mon"], ["cf_Tue", "Tue"], ["cf_Wed", "Wed"], ["cf_Thu", "Thu"], ["cf_Fri", "Fri"], ["cf_Sat", "Sat"], ["cf_Sun", "Sun"]];
  var SKIP_REASONS = {
    too_short: ["cf_SkipTooShort", "too short"],
    outside_hours: ["cf_SkipOutsideHours", "outside survey hours"],
    excluded: ["cf_SkipExcluded", "excluded number"],
    capped: ["cf_SkipCapped", "asked recently"],
    sampled_out: ["cf_SkipSampled", "not sampled"],
    concurrency: ["cf_SkipConcurrency", "too many surveys at once"]
  };
  var BLUE = "#2185d0";
  var GREY = "#cbd2d9";
  var RED = "#cc3b3b";
  var AMBER = "#f0a02e";
  var GREEN = "#2f9157";
  var $id = (id) => document.getElementById(id);
  function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => {
      if (v === void 0 || v === null || v === false) return;
      if (k === "class") node.className = v;
      else if (k === "text") node.textContent = v;
      else if (k.startsWith("on")) node.addEventListener(k.slice(2), v);
      else if (v === true) node.setAttribute(k, "");
      else node.setAttribute(k, v);
    });
    children.flat().forEach((c) => {
      if (c !== null && c !== void 0 && c !== false) node.append(c instanceof Node ? c : document.createTextNode(String(c)));
    });
    return node;
  }
  var fill = (node, ...children) => node.replaceChildren(...children.flat().filter((c) => c !== null && c !== void 0 && c !== false));
  var label = (pair) => t(pair[0], pair[1]);
  var scaleLabel = (s) => SCALES[s] ? label(SCALES[s]) : s;
  var languageLabel = (l) => LANGUAGES[l] ? label(LANGUAGES[l]) : l;
  var problemLabel = (key) => t(key, PROBLEMS[key] || key);
  var dateTime = (epoch) => epoch ? new Date(epoch * 1e3).toLocaleString() : "\u2014";
  function failText(xhr, fallback) {
    const msg = xhr && xhr.responseJSON && xhr.responseJSON.message;
    if (typeof msg === "string" && msg !== "") return PROBLEMS[msg] ? problemLabel(msg) : msg;
    return fallback;
  }
  function showError(message2) {
    const box = $id("cf-error");
    box.textContent = message2;
    box.classList.remove("cf-hidden");
  }
  var clearError = () => $id("cf-error").classList.add("cf-hidden");
  function get(action, data = {}) {
    return $.ajax({ url: BASE + action, method: "GET", data, dataType: "json" }).then((r) => r && r.success ? r.data : $.Deferred().reject({ responseJSON: r })).fail((xhr) => showError(failText(xhr, t("cf_LoadFailed", "Call Feedback could not load this data."))));
  }
  function post(action, data) {
    clearError();
    return $.ajax({ url: BASE + action, method: "POST", data, dataType: "json" }).then((r) => r && r.success ? r.data : $.Deferred().reject({ responseJSON: r }));
  }
  function message(box, kind, text, items = []) {
    box.replaceChildren(el(
      "div",
      { class: `ui ${kind} message` },
      el("p", { text }),
      items.length ? el("ul", { class: "list" }, items.map((i) => el("li", { text: i }))) : null
    ));
  }
  function select(id, options, value, attrs = {}) {
    return el("select", { id, ...attrs }, options.map(([v, text]) => el("option", { value: String(v), selected: String(v) === String(value != null ? value : "") }, text)));
  }
  function field(labelText, control, hint, width) {
    return el(
      "div",
      { class: `${width ? `${width} wide ` : ""}field` },
      el("label", { for: control.id, text: labelText }),
      control,
      hint ? el("div", { class: "cf-note", text: hint }) : null
    );
  }
  function numberInput(id, value, min, max, disabled) {
    return el("input", { type: "number", id, value: String(value), min: String(min), max: String(max), step: "1", disabled });
  }
  function statusLabel(status) {
    const s = STATUSES[status] || [status, status, ""];
    return el("span", { class: `ui ${s[2]} small label`, text: label(s) });
  }
  function soundOptions(withNone) {
    const files = state.options.soundFiles.map((f) => [f.id, f.name]);
    return withNone ? [["", t("cf_NoSound", "\u2014 none \u2014")], ...files] : [["", t("cf_ChooseSound", "\u2014 choose a sound file \u2014")], ...files];
  }
  function routeText(direction, id) {
    const list = state.options.routes[direction] || [];
    const r = list.find((x) => x.id === id);
    if (!r) return `#${id}`;
    const via = r.provider ? `, ${r.provider}` : "";
    if (direction === "in") {
      const what2 = r.number || t("cf_AnyNumber", "any number");
      return r.name ? `${r.name} (${what2}${via})` : `${what2}${via ? ` (${r.provider})` : ""}`;
    }
    const what = r.prefix ? `${r.prefix}\u2026` : t("cf_AnyNumber", "any number");
    return r.name ? `${r.name} (${what})` : what;
  }
  function loadSurveys() {
    return get("surveys").then((rows) => {
      const table = $id("cf-surveys");
      const head = el("thead", {}, el(
        "tr",
        {},
        el("th", { text: t("cf_Name", "Name") }),
        el("th", { text: t("cf_Version", "Version") }),
        el("th", { text: t("cf_Status", "Status") }),
        el("th", { text: t("cf_RunsOn", "Runs on") }),
        el("th", { class: "cf-num", text: t("cf_Last7Days", "Answered, last 7 days") })
      ));
      const body = el("tbody");
      if (rows.length === 0) {
        body.append(el("tr", {}, el("td", { colspan: "5", class: "cf-muted", text: t("cf_NoSurveys", "No surveys yet. Create one to start.") })));
      }
      rows.forEach((s) => {
        const runsOn = s.targets.length === 0 ? el("span", { class: "cf-muted", text: t("cf_NoRoutes", "No routes") }) : s.targets.map((tg) => routeText(tg.direction, tg.route_id)).join("; ");
        body.append(el(
          "tr",
          { class: "cf-clickable", onclick: () => openEditor(s.id) },
          el("td", {}, el("a", { href: "#", text: s.name, onclick: (e) => e.preventDefault() })),
          el("td", { text: `v${s.version}` }),
          el("td", {}, statusLabel(s.status)),
          el("td", {}, runsOn),
          el("td", { class: "cf-num", text: s.offered ? `${s.responded} / ${s.offered}` : "\u2014" })
        ));
      });
      table.replaceChildren(head, body);
    });
  }
  function showList() {
    state.editing = null;
    clearInterval(state.testPoll);
    $id("cf-editor").classList.add("cf-hidden");
    $id("cf-survey-list").classList.remove("cf-hidden");
    loadSurveys();
  }
  function createSurvey(e) {
    e.preventDefault();
    post("createSurvey", { name: $id("cf-new-name").value, language: $id("cf-new-language").value }).then((r) => {
      $id("cf-new-form").classList.add("cf-hidden");
      $id("cf-new-name").value = "";
      openEditor(r.id);
    }, (xhr) => showError(failText(xhr, t("cf_SaveFailed", "The change could not be saved."))));
  }
  function openEditor(id) {
    clearError();
    clearInterval(state.testPoll);
    return get("survey", { id }).then((data) => {
      state.editing = data;
      $id("cf-survey-list").classList.add("cf-hidden");
      const box = $id("cf-editor");
      box.classList.remove("cf-hidden");
      box.replaceChildren(renderEditor(data));
    });
  }
  function questionBlock(q, index, count, draft) {
    const scales = (index === 0 ? HEADLINE_SCALES : Object.keys(SCALES)).map((s) => [s, scaleLabel(s)]);
    const id = (name) => `cf-q${index}-${name}`;
    return el(
      "div",
      { class: "cf-question", "data-index": String(index) },
      el(
        "div",
        { class: "cf-question-head" },
        el("span", { text: index === 0 ? t("cf_HeadlineQuestion", "Question 1: the headline score") : `${t("cf_Question", "Question")} ${index + 1}` }),
        draft && index > 0 ? el("button", {
          class: "ui basic mini button",
          type: "button",
          text: t("cf_Remove", "Remove"),
          onclick: () => editQuestions((qs) => qs.splice(index, 1))
        }) : null
      ),
      el(
        "div",
        { class: "fields" },
        field(t("cf_Scale", "Scale"), select(id("scale"), scales, q.scale, { disabled: !draft }), null, "six"),
        field(t("cf_QuestionPrompt", "Question prompt"), select(id("prompt"), soundOptions(false), q.prompt_id, { disabled: !draft }), null, "six"),
        field(t("cf_Timeout", "Wait for a key (s)"), numberInput(id("timeout"), q.timeout_seconds, 2, 30, !draft), null, "two"),
        field(t("cf_Retries", "Retries"), numberInput(id("retries"), q.retries, 0, 5, !draft), null, "two")
      ),
      q.scale === "nps11" ? el("div", { class: "cf-note", text: t("cf_NpsHint", 'NPS takes 0\u201310. The prompt must say "press 1 then 0 for 10": a lone 1 counts after 2 seconds.') }) : null
    );
  }
  function readQuestions() {
    return [...document.querySelectorAll("#cf-editor .cf-question")].map((node) => {
      const i = node.dataset.index;
      return {
        scale: $id(`cf-q${i}-scale`).value,
        prompt_id: $id(`cf-q${i}-prompt`).value,
        timeout_seconds: $id(`cf-q${i}-timeout`).value,
        retries: $id(`cf-q${i}-retries`).value
      };
    });
  }
  function editQuestions(change) {
    const questions = readQuestions();
    change(questions);
    const list = $id("cf-questions");
    list.replaceChildren(...questions.map((q, i) => questionBlock(q, i, questions.length, true)));
    $id("cf-add-question").disabled = questions.length >= 3;
  }
  function renderEditor(data) {
    const s = data.survey;
    const draft = s.status === "draft";
    const active = s.status === "active";
    const archived = s.status === "archived";
    const result = el("div", { id: "cf-editor-result" });
    const targetKeys = new Set(data.targets.map((tg) => `${tg.direction}:${tg.route_id}`));
    const problems = problemsBox(data);
    const lockedNote = active ? el("div", { class: "ui info message", text: t("cf_ActiveLocked", "This version is live: its questions and prompts are fixed so results stay comparable. Rules and routes can still change. To change the wording, create a new version.") }) : null;
    const targetList = (direction, heading) => el(
      "div",
      {},
      el("h5", { class: "ui header", text: heading }),
      el("div", { class: "cf-targets" }, (state.options.routes[direction] || []).length === 0 ? el("p", { class: "cf-muted", text: t("cf_NoRoutesConfigured", "No routes configured.") }) : state.options.routes[direction].map((r) => el(
        "div",
        { class: "field" },
        el(
          "div",
          { class: `ui checkbox${r.usable ? "" : " disabled"}` },
          el("input", {
            type: "checkbox",
            class: "cf-target",
            "data-direction": direction,
            "data-route": String(r.id),
            id: `cf-target-${direction}-${r.id}`,
            checked: targetKeys.has(`${direction}:${r.id}`),
            disabled: archived || !r.usable
          }),
          el("label", { for: `cf-target-${direction}-${r.id}`, text: routeText(direction, r.id) + (r.usable ? "" : ` \u2014 ${t("cf_PatternRoute", "number patterns are not supported")}`) })
        )
      )))
    );
    const form = el(
      "form",
      { class: "ui form", onsubmit: (e) => {
        e.preventDefault();
        saveEditor(result);
      } },
      el("h4", { class: "ui dividing header", text: t("cf_SectionSurvey", "Survey") }),
      el(
        "div",
        { class: "fields" },
        field(t("cf_Name", "Name"), el("input", { type: "text", id: "cf-name", maxlength: "60", value: s.name, disabled: !draft }), null, "ten"),
        field(
          t("cf_Language", "Language"),
          select("cf-language", Object.keys(LANGUAGES).map((l) => [l, languageLabel(l)]), s.language, { disabled: !draft }),
          t("cf_LanguageHint", "Record the prompts in this language."),
          "six"
        )
      ),
      el("h4", { class: "ui dividing header", text: t("cf_SectionPrompts", "Prompts") }),
      el("p", { class: "cf-note", text: t("cf_PromptsHint", "Prompts are sound files from Sound files (Phrase Studio can generate them). Upload them there first.") }),
      el("div", { class: "three fields" }, PROMPT_FIELDS.map(([name, key, fallback]) => field(t(key, fallback), select(`cf-${name}`, soundOptions(name === "invalid_prompt_id"), s[name], { disabled: !draft })))),
      el("h4", { class: "ui dividing header", text: t("cf_SectionQuestions", "Questions") }),
      el("div", { id: "cf-questions" }, Object.keys(s.questions).sort().map((pos, i) => questionBlock(s.questions[pos], i, Object.keys(s.questions).length, draft))),
      draft ? el("button", {
        class: "ui basic small button",
        type: "button",
        id: "cf-add-question",
        text: t("cf_AddQuestion", "Add a question"),
        disabled: Object.keys(s.questions).length >= 3,
        onclick: () => editQuestions((qs) => qs.push({ scale: "yesno", prompt_id: "", timeout_seconds: 6, retries: 2 }))
      }) : null,
      el("h4", { class: "ui dividing header", text: t("cf_SectionRules", "Who gets surveyed") }),
      el("div", { class: "three fields" }, RULE_FIELDS.map(([name, key, fallback, min, max]) => field(t(key, fallback), numberInput(`cf-${name}`, s[name], min, max, archived)))),
      hoursFields(s, archived),
      el("p", { class: "cf-note", text: t("cf_RulesHint", "Only calls an agent answered are surveyed, and never at the agent's choice. Excluded numbers and the limit on surveys running at once are under Settings.") }),
      el("h4", { class: "ui dividing header", text: t("cf_SectionTargets", "Runs on") }),
      targetList("in", t("cf_IncomingRoutes", "Incoming routes: the caller is surveyed when the agent hangs up")),
      targetList("out", t("cf_OutgoingRoutes", "Outgoing routes: the customer is surveyed when the agent hangs up")),
      el("p", { class: "cf-note", text: t("cf_TargetsHint", "Blind-transferred calls and calls the customer ends first are never surveyed.") }),
      el(
        "div",
        { class: "cf-editor-actions" },
        archived ? null : el("button", { class: "ui primary button", type: "submit", text: t("cf_Save", "Save") }),
        draft ? el("button", {
          class: "ui positive button",
          type: "button",
          id: "cf-activate",
          text: t("cf_Activate", "Activate"),
          disabled: data.problems.length > 0,
          onclick: () => act("activateSurvey", s.id, result)
        }) : null,
        active ? el("button", {
          class: "ui button",
          type: "button",
          text: t("cf_NewVersion", "New version to change wording"),
          onclick: () => act("newVersion", s.id, result, true)
        }) : null,
        el("button", { class: "ui basic button", type: "button", text: t("cf_Duplicate", "Duplicate"), onclick: () => act("duplicateSurvey", s.id, result, true) }),
        archived ? null : el("button", {
          class: "ui basic negative button",
          type: "button",
          text: active ? t("cf_Stop", "Stop (archive)") : t("cf_Archive", "Archive"),
          onclick: () => {
            if (window.confirm(t("cf_ConfirmArchive", "Archive this version? It stops running and can't be edited again. Its results are kept."))) act("archiveSurvey", s.id, result);
          }
        })
      ),
      result
    );
    return el(
      "div",
      {},
      el(
        "div",
        { class: "cf-editor-head" },
        el("a", { href: "#", class: "ui basic small button", onclick: (e) => {
          e.preventDefault();
          showList();
        } }, el("i", { class: "arrow left icon" }), t("cf_AllSurveys", "All surveys")),
        el("h3", { class: "ui header", text: `${s.name} \xB7 v${s.version}` }),
        statusLabel(s.status)
      ),
      problems,
      lockedNote,
      form,
      archived ? null : testCallPanel(data)
    );
  }
  function problemsBox(data) {
    if (data.survey.status !== "draft" || data.problems.length === 0) return el("div", { id: "cf-problems" });
    return el(
      "div",
      { id: "cf-problems", class: "ui warning message cf-problems" },
      el("div", { class: "header", text: t("cf_BeforeActivating", "Before this version can go live:") }),
      el("ul", { class: "list" }, data.problems.map((p) => el("li", { text: problemLabel(p) })))
    );
  }
  function testCallList(data) {
    const scales = data.survey.questions;
    return el("table", { class: "ui very basic compact table cf-table", id: "cf-test-calls" }, el(
      "tbody",
      {},
      data.testCalls.length === 0 ? el("tr", {}, el("td", { class: "cf-muted", text: t("cf_NoTestCalls", "No test calls on this version yet.") })) : data.testCalls.map((c) => el(
        "tr",
        {},
        el("td", { text: dateTime(c.ended_at) }),
        el("td", { text: c.caller_num || "" }),
        el("td", { text: Object.keys(c.answers).length === 0 ? t("cf_NoKeys", "No keys pressed") : Object.keys(c.answers).sort().map((pos) => `${t("cf_Q", "Q")}${pos}: ${answerText(scales[pos], c.answers[pos])}`).join(" \xB7 ") })
      ))
    ));
  }
  function answerText(question, value) {
    if (question && question.scale === "yesno") return value === 1 ? t("cf_Yes", "yes") : t("cf_No", "no");
    return String(value);
  }
  function refreshTests(id) {
    return get("survey", { id }).then((data) => {
      if (!state.editing || state.editing.survey.id !== id) return data;
      state.editing.problems = data.problems;
      state.editing.testCalls = data.testCalls;
      const list = $id("cf-test-calls");
      if (list) list.replaceWith(testCallList(data));
      const problems = $id("cf-problems");
      if (problems) problems.replaceWith(problemsBox(data));
      const activate = $id("cf-activate");
      if (activate) activate.disabled = data.problems.length > 0;
      return data;
    });
  }
  function testCallPanel(data) {
    const s = data.survey;
    const result = el("div");
    const extensions = state.options.extensions.map((x) => [x.number, x.name ? `${x.name} (${x.number})` : x.number]);
    const ring = () => {
      const before = data.testCalls.length ? data.testCalls[0].id : 0;
      post("testCall", { id: s.id, extension: $id("cf-test-extension").value }).then((r) => {
        message(result, "info", t("cf_TestRinging", "Ringing {ext}. Answer, listen, and press keys as a caller would; the call shows below when it ends.").replace("{ext}", r.ringing));
        clearInterval(state.testPoll);
        let tries = 0;
        state.testPoll = setInterval(() => {
          tries += 1;
          refreshTests(s.id).then((fresh) => {
            if (fresh.testCalls.length && fresh.testCalls[0].id !== before || tries > 36) {
              clearInterval(state.testPoll);
              if (tries <= 36) message(result, "positive", t("cf_TestRecorded", "Test call recorded."));
            }
          });
        }, 5e3);
      }, (xhr) => message(result, "negative", failText(xhr, t("cf_TestFailed", "The test call could not be placed."))));
    };
    return el(
      "div",
      {},
      el("h4", { class: "ui dividing header", text: t("cf_SectionTest", "Test call") }),
      el("p", { class: "cf-note", text: t("cf_TestHint", "Rings an extension and plays this version as a caller would hear it, without the rules. Test calls are kept apart from results.") }),
      el("div", { class: "ui form" }, el(
        "div",
        { class: "fields" },
        field(t("cf_Extension", "Extension"), select("cf-test-extension", extensions, extensions.length ? extensions[0][0] : ""), null, "six"),
        el(
          "div",
          { class: "four wide field cf-form-buttons" },
          el("button", { class: "ui button", type: "button", text: t("cf_RingForTest", "Ring for a test"), disabled: extensions.length === 0, onclick: ring }),
          el("button", { class: "ui basic icon button", type: "button", title: t("cf_Refresh", "Refresh"), onclick: () => refreshTests(s.id) }, el("i", { class: "sync icon" }))
        )
      )),
      result,
      testCallList(data)
    );
  }
  function hoursFields(s, disabled) {
    const days = WEEKDAYS.map((d, i) => el(
      "div",
      { class: "ui checkbox cf-day" },
      el("input", {
        type: "checkbox",
        class: "cf-hours-day",
        id: `cf-day-${i}`,
        "data-bit": String(1 << i),
        checked: (s.hours_days & 1 << i) !== 0,
        disabled
      }),
      el("label", { for: `cf-day-${i}`, text: label(d) })
    ));
    return el(
      "div",
      { class: "fields" },
      el("div", { class: "eight wide field" }, el("label", { text: t("cf_HoursDays", "Survey on these days") }), el("div", { class: "cf-days" }, days)),
      field(t("cf_HoursStart", "From (empty: all day)"), el("input", { type: "time", id: "cf-hours-start", value: s.hours_start || "", disabled }), null, "four"),
      field(
        t("cf_HoursEnd", "Until"),
        el("input", { type: "time", id: "cf-hours-end", value: s.hours_end || "", disabled }),
        t("cf_HoursHint", "PBX time. An end before the start runs past midnight."),
        "four"
      )
    );
  }
  function saveEditor(result) {
    const s = state.editing.survey;
    const fields = {};
    RULE_FIELDS.forEach(([name]) => {
      fields[name] = $id(`cf-${name}`).value;
    });
    fields.hours_days = String([...document.querySelectorAll("#cf-editor .cf-hours-day:checked")].reduce((mask, box) => mask + Number(box.dataset.bit), 0));
    fields.hours_start = $id("cf-hours-start").value;
    fields.hours_end = $id("cf-hours-end").value;
    if (s.status === "draft") {
      fields.name = $id("cf-name").value;
      fields.language = $id("cf-language").value;
      PROMPT_FIELDS.forEach(([name]) => {
        fields[name] = $id(`cf-${name}`).value;
      });
      fields.questions = readQuestions();
    }
    const targets = [...document.querySelectorAll("#cf-editor .cf-target:checked")].map((box) => ({ direction: box.dataset.direction, route_id: box.dataset.route }));
    post("saveSurvey", { id: s.id, fields: JSON.stringify(fields) }).then(() => post("saveTargets", { id: s.id, targets: JSON.stringify(targets) })).then(() => openEditor(s.id)).then(
      () => message($id("cf-editor-result"), "positive", t("cf_Saved", "Saved. Calls use the change within a few seconds.")),
      (xhr) => message(result, "negative", failText(xhr, t("cf_SaveFailed", "The change could not be saved.")))
    );
  }
  function act(action, id, result, follow = false) {
    post(action, { id }).then((r) => openEditor(follow && r && r.id ? r.id : id)).then(
      () => message($id("cf-editor-result"), "positive", t("cf_Done", "Done.")),
      (xhr) => message(result, "negative", failText(xhr, t("cf_SaveFailed", "The change could not be saved.")))
    );
  }
  var SETTING_FIELDS = [
    ["csat_detractor_max", "cf_CsatDetractor", "CSAT scores up to this open a follow-up", 1, 4],
    ["nps_detractor_max", "cf_NpsDetractor", "NPS scores up to this open a follow-up", 0, 9],
    ["ces_detractor_max", "cf_CesDetractor", "CES scores up to this open a follow-up", 1, 4],
    ["min_sample", "cf_MinSample", "Responses a group needs before its score is shown", 1, 1e3],
    ["max_concurrent", "cf_MaxConcurrent", "Surveys running at once, at most", 1, 500]
  ];
  function loadSettings() {
    return get("settings").then((data) => {
      const result = el("div");
      const exclusionsResult = el("div");
      const s = data.settings;
      const form = el(
        "form",
        { class: "ui form", onsubmit: (e) => {
          e.preventDefault();
          const settings = {
            alert_emails: $id("cf-alert-emails").value,
            callback_prefixes: $id("cf-callback-prefixes").value,
            summary: $id("cf-summary").value
          };
          SETTING_FIELDS.forEach(([name]) => {
            settings[name] = $id(`cf-${name}`).value;
          });
          post("saveSettings", { settings: JSON.stringify(settings) }).then(
            () => message(result, "positive", t("cf_Saved", "Saved. Calls use the change within a few seconds.")),
            (xhr) => message(result, "negative", failText(xhr, t("cf_SaveFailed", "The change could not be saved.")))
          );
        } },
        el("h4", { class: "ui dividing header", text: t("cf_SectionAlerts", "Detractor alerts") }),
        data.mailEnabled ? null : el("div", { class: "ui warning message", text: t("cf_MailOff", "Email notifications are off in the PBX's mail settings, so no alerts are sent. Follow-ups are still listed.") }),
        field(
          t("cf_AlertEmails", "Send alerts to"),
          el("textarea", { id: "cf-alert-emails", rows: "2", text: s.alert_emails.join("\n") }),
          t("cf_AlertEmailsHint", "One address per line, up to 10. Alerts carry a link to the follow-up, not the caller's number.")
        ),
        field(
          t("cf_Summary", "Summary email to the same addresses"),
          select("cf-summary", [
            ["off", t("cf_SummaryOff", "Off")],
            ["daily", t("cf_SummaryDaily", "Daily, for the day before")],
            ["weekly", t("cf_SummaryWeekly", "Weekly on Monday, for the last 7 days")]
          ], s.summary),
          t("cf_SummaryHint", "Sent after 08:00 PBX time: each active survey's score and responses, and the follow-ups still open.")
        ),
        field(
          t("cf_CallBackPrefixes", "Numbers that can be called back in one click start with"),
          el("input", { type: "text", id: "cf-callback-prefixes", value: s.callback_prefixes.join(" ") }),
          t("cf_CallBackPrefixesHint", "Digit prefixes of your own country's ordinary numbers, separated by spaces (for example 7495 8495). Any other number must be typed in before it is called back, so a faked international or premium-rate number can't be called by mistake. Empty: every call-back must be typed.")
        ),
        el("div", { class: "three fields" }, SETTING_FIELDS.slice(0, 3).map(([name, key, fallback, min, max]) => field(t(key, fallback), numberInput(`cf-${name}`, s[name], min, max, false)))),
        el("h4", { class: "ui dividing header", text: t("cf_SectionLimits", "Results and limits") }),
        el("div", { class: "two fields" }, SETTING_FIELDS.slice(3).map(([name, key, fallback, min, max]) => field(t(key, fallback), numberInput(`cf-${name}`, s[name], min, max, false)))),
        el("button", { class: "ui primary button", type: "submit", text: t("cf_Save", "Save") }),
        result
      );
      const exclusions = el(
        "div",
        {},
        el("h4", { class: "ui dividing header", text: t("cf_SectionExclusions", "Excluded numbers") }),
        el("p", { class: "cf-note", text: t("cf_ExclusionsHint", "These numbers are never surveyed. Only digits are kept.") }),
        el(
          "form",
          { class: "ui form", onsubmit: (e) => {
            e.preventDefault();
            post("addExclusions", { numbers: $id("cf-excl-numbers").value, note: $id("cf-excl-note").value }).then(() => loadSettings(), (xhr) => message(exclusionsResult, "negative", failText(xhr, t("cf_SaveFailed", "The change could not be saved."))));
          } },
          el(
            "div",
            { class: "fields" },
            field(t("cf_Numbers", "Numbers, one per line"), el("textarea", { id: "cf-excl-numbers", rows: "2" }), null, "eight"),
            field(t("cf_Note", "Note"), el("input", { type: "text", id: "cf-excl-note", maxlength: "200" }), null, "six"),
            el("div", { class: "two wide field cf-form-buttons" }, el("button", { class: "ui button", type: "submit", text: t("cf_Add", "Add") }))
          )
        ),
        exclusionsResult,
        el("div", { class: "cf-exclusions" }, el("table", { class: "ui very basic compact table cf-table" }, el(
          "tbody",
          {},
          data.exclusions.length === 0 ? el("tr", {}, el("td", { class: "cf-muted", text: t("cf_NoExclusions", "No excluded numbers.") })) : data.exclusions.map((x) => el(
            "tr",
            {},
            el("td", { text: x.number }),
            el("td", { class: "cf-muted", text: x.note || "" }),
            el("td", { class: "cf-num" }, el("button", {
              class: "ui basic mini button",
              type: "button",
              text: t("cf_Remove", "Remove"),
              onclick: () => post("removeExclusion", { id: x.id }).then(
                () => loadSettings(),
                (xhr) => message(exclusionsResult, "negative", failText(xhr, t("cf_SaveFailed", "The change could not be saved.")))
              )
            }))
          ))
        )))
      );
      const forgetResult = el("div");
      const privacy = el(
        "div",
        {},
        el("h4", { class: "ui dividing header", text: t("cf_SectionPrivacy", "Privacy") }),
        el("p", { class: "cf-note", text: t("cf_RetentionHint", `Callers' numbers are kept as long as the PBX keeps its call history, then removed; scores stay. The same limit applies to the "don't ask again" memory.`) }),
        el(
          "form",
          { class: "ui form", onsubmit: (e) => {
            e.preventDefault();
            const number = $id("cf-forget-number").value.trim();
            if (!number || !window.confirm(t("cf_ConfirmForget", "Delete every survey answer and follow-up for {number}? This can't be undone.").replace("{number}", number))) return;
            post("forgetNumber", { number }).then(
              (r) => {
                $id("cf-forget-number").value = "";
                message(forgetResult, "positive", t("cf_Forgotten", "Deleted {n} survey session(s) for that number.").replace("{n}", r.sessions));
              },
              (xhr) => message(forgetResult, "negative", failText(xhr, t("cf_SaveFailed", "The change could not be saved.")))
            );
          } },
          el(
            "div",
            { class: "fields" },
            field(t("cf_ForgetNumber", "Delete all data for a number"), el("input", { type: "text", id: "cf-forget-number", maxlength: "40" }), null, "eight"),
            el("div", { class: "four wide field cf-form-buttons" }, el("button", { class: "ui basic negative button", type: "submit", text: t("cf_Delete", "Delete") }))
          )
        ),
        forgetResult
      );
      $id("cf-settings").replaceChildren(form, exclusions, privacy);
    });
  }
  var esc = (v) => echarts.format.encodeHTML(String(v));
  var isoDate = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  var fmt = (v, unit = "") => v === null || v === void 0 ? "\u2014" : `${Number(v).toLocaleString()}${unit}`;
  function applyPreset() {
    const preset = $id("cf-preset").value;
    if (preset === "custom") return;
    const to = /* @__PURE__ */ new Date();
    const from = new Date(to);
    from.setDate(to.getDate() - (Number(preset) - 1));
    $id("cf-from").value = isoDate(from);
    $id("cf-to").value = isoDate(to);
  }
  function resultsQuery(extra = {}) {
    return { survey: $id("cf-version").value, from: $id("cf-from").value, to: $id("cf-to").value, direction: $id("cf-direction").value, ...extra };
  }
  function chart(id) {
    if (!state.charts[id]) state.charts[id] = echarts.init($id(id), null, { renderer: "canvas" });
    return state.charts[id];
  }
  function nameOf(kind, key) {
    if (key === "" || key === null || key === void 0) return t("cf_Unknown", "Unknown");
    if (kind === "weekday") return label(WEEKDAYS[Number(key) - 1] || [key, key]);
    if (kind === "hour") return `${String(key).padStart(2, "0")}:00`;
    if (kind === "wait" || kind === "talk") return bucketLabel(kind, Number(key));
    if (kind === "direction") return key === "in" ? t("cf_Inbound", "Inbound") : t("cf_Outbound", "Outbound");
    const names = kind === "did" ? state.labels.dids : state.labels.extensions;
    return names && names[key] ? `${names[key]} (${key})` : String(key);
  }
  var BUCKETS = { wait: [0, 15, 30, 60, 120, 300], talk: [0, 60, 180, 300, 600] };
  function bucketLabel(kind, from) {
    const bounds = BUCKETS[kind];
    const next = bounds[bounds.indexOf(from) + 1];
    const span = (sec) => sec < 60 ? `${sec} s` : `${sec / 60} min`;
    if (next === void 0) return t("cf_OrMore", "{from} or more").replace("{from}", span(from));
    const sameUnit = from < 60 === next < 60;
    return sameUnit ? `${span(from).split(" ")[0]}\u2013${span(next)}` : `${span(from)}\u2013${span(next)}`;
  }
  function metricName(scale) {
    return METRICS[scale] ? label(METRICS[scale]) : scale;
  }
  function scoreText(scale, value) {
    if (value === null || value === void 0) return "\u2014";
    const unit = METRICS[scale] ? METRICS[scale][2] : "";
    return scale === "nps11" ? `${value > 0 ? "+" : ""}${value}` : `${value}${unit}`;
  }
  function kpi(labelText, value, sub, change) {
    return el(
      "div",
      { class: "cf-kpi" },
      el("div", { class: "cf-kpi-label", text: labelText }),
      el("div", { class: `cf-kpi-value${value === "\u2014" ? " cf-empty" : ""}`, text: value }),
      sub ? el("div", { class: "cf-kpi-sub", text: sub }) : null,
      change
    );
  }
  function changeLine(cur, prev, unitText) {
    if (cur === null || prev === null || cur === void 0 || prev === void 0) {
      return el("div", { class: "cf-kpi-sub", text: t("cf_NoPrevious", "No responses in the previous period") });
    }
    const delta = Math.round((cur - prev) * 10) / 10;
    if (delta === 0) return el("div", { class: "cf-kpi-sub", text: t("cf_NoChange", "No change vs previous period") });
    return el("div", { class: `cf-kpi-sub cf-change ${delta > 0 ? "good" : "bad"}`, text: `${delta > 0 ? "\u25B2" : "\u25BC"} ${Math.abs(delta)}${unitText} ${t("cf_VsPrevious", "vs previous period")}` });
  }
  function noVersions() {
    return el("div", { class: "ui info message", text: t("cf_NoResultsYet", "Results appear here once a survey has been activated and callers have answered it.") });
  }
  function loadOverview() {
    const box = $id("cf-overview");
    if (!state.versions.length) {
      box.replaceChildren(noVersions());
      return;
    }
    get("overview", resultsQuery()).then((o) => {
      const scale = o.survey.scale;
      const small = o.score.responses < o.min_sample;
      const scoreSub = small ? t("cf_NotEnough", "Not enough responses yet ({n} of {min})").replace("{n}", o.score.responses).replace("{min}", o.min_sample) : `${o.score.responses.toLocaleString()} ${t("cf_Responses", "responses")}`;
      const tiles = [
        kpi(
          metricName(scale),
          small ? "\u2014" : scoreText(scale, o.score.score),
          scoreSub,
          small ? null : changeLine(o.score.score, o.previous.score.responses >= o.min_sample ? o.previous.score.score : null, scale === "nps11" ? "" : ` ${t("cf_Points", "pts")}`)
        ),
        kpi(t("cf_Average", "Average"), small ? "\u2014" : fmt(o.score.average), `${t("cf_Scale", "Scale")}: ${scale === "nps11" ? "0\u201310" : "1\u20135"}`),
        kpi(
          t("cf_ResponseRate", "Response rate"),
          fmt(o.response_rate, "%"),
          t("cf_ResponseRateSub", "{started} of {offered} offered pressed a key").replace("{started}", o.started).replace("{offered}", o.offered)
        ),
        kpi(
          t("cf_CompletionRate", "Completion rate"),
          fmt(o.completion_rate, "%"),
          t("cf_CompletionRateSub", "{completed} answered every question").replace("{completed}", o.completed)
        ),
        o.resolution ? kpi(
          t("cf_Resolved", "Issue resolved"),
          o.resolution.responses ? fmt(o.resolution.score, "%") : "\u2014",
          `${o.resolution.responses.toLocaleString()} ${t("cf_Responses", "responses")}`
        ) : kpi(t("cf_Excluded", "Left out"), fmt(o.excluded), t("cf_ExcludedSub", "calls the CDR shows were under the minimum talk time"))
      ];
      box.replaceChildren(el("div", { class: "cf-kpis" }, tiles));
      renderTrend(o);
      renderDistribution(o);
      const skipped = Object.entries(o.skipped).filter(([, n]) => n > 0).map(([reason, n]) => `${n.toLocaleString()} ${SKIP_REASONS[reason] ? label(SKIP_REASONS[reason]) : reason}`);
      fill(
        $id("cf-overview-notes"),
        skipped.length ? el("p", { class: "cf-note", text: `${t("cf_NotAsked", "Not asked in this period:")} ${skipped.join(", ")}.` }) : null,
        el("p", { class: "cf-note", text: t("cf_PatientCallers", "Callers who hang up first and blind-transferred calls are never surveyed, so scores lean towards callers who stayed on the line.") }),
        scale === "nps11" ? el("p", { class: "cf-trademark", text: t("cf_NpsTrademark", "Net Promoter, NPS and Net Promoter Score are registered trademarks of Bain & Company, Inc., Satmetrix Systems, Inc. and Fred Reichheld.") }) : null
      );
    });
  }
  function renderTrend(o) {
    const points = o.trend.points;
    const scale = o.survey.scale;
    chart("cf-chart-trend").setOption({
      grid: { left: 48, right: 48, top: 30, bottom: 30 },
      legend: { data: [metricName(scale), t("cf_Responses", "responses")], top: 0 },
      tooltip: {
        trigger: "axis",
        formatter: (params) => {
          const p = points[params[0].dataIndex];
          const score = p.low_sample ? t("cf_TooFew", "too few responses") : scoreText(scale, p.score);
          return `${esc(p.period)}<br>${esc(metricName(scale))}: ${esc(score)}<br>${esc(t("cf_Responses", "responses"))}: ${esc(p.responses)}`;
        }
      },
      xAxis: { type: "category", data: points.map((p) => p.period) },
      yAxis: [
        { type: "value", name: metricName(scale), min: scale === "nps11" ? -100 : 0, max: 100 },
        { type: "value", name: t("cf_Responses", "responses"), minInterval: 1, splitLine: { show: false } }
      ],
      series: [
        { name: t("cf_Responses", "responses"), type: "bar", yAxisIndex: 1, data: points.map((p) => p.responses), itemStyle: { color: "#e4e7eb" } },
        {
          name: metricName(scale),
          type: "line",
          connectNulls: false,
          symbolSize: 7,
          lineStyle: { color: BLUE },
          itemStyle: { color: BLUE },
          data: points.map((p) => ({ value: p.score, itemStyle: { color: p.low_sample ? GREY : BLUE } }))
        }
      ]
    }, true);
  }
  function renderDistribution(o) {
    const scale = o.survey.scale;
    const keys = Object.keys(o.score.distribution).map(Number).sort((a, b) => a - b);
    const color = (v) => {
      if (scale === "nps11") return v <= 6 ? RED : v <= 8 ? AMBER : GREEN;
      return v <= 2 ? RED : v === 3 ? AMBER : GREEN;
    };
    chart("cf-chart-distribution").setOption({
      grid: { left: 48, right: 20, top: 10, bottom: 30 },
      tooltip: { trigger: "axis", formatter: (p) => `${esc(p[0].name)}: ${esc(p[0].value)}` },
      xAxis: { type: "category", data: keys.map(String) },
      yAxis: { type: "value", minInterval: 1 },
      series: [{ type: "bar", data: keys.map((k) => ({ value: o.score.distribution[k], itemStyle: { color: color(k) } })) }]
    }, true);
  }
  function loadBreakdown() {
    const table = $id("cf-breakdown-table");
    if (!state.versions.length) {
      table.replaceChildren();
      $id("cf-breakdown-notes").replaceChildren(noVersions());
      return;
    }
    const kind = $id("cf-breakdown").value;
    get("breakdown", resultsQuery({ breakdown: kind })).then((b) => {
      const max = Math.max(1, ...b.rows.map((r) => r.responses));
      const head = el("thead", {}, el(
        "tr",
        {},
        el("th", { text: $id("cf-breakdown").selectedOptions[0].textContent }),
        el("th", { class: "cf-num", text: metricName(b.scale) }),
        el("th", { class: "cf-num", text: t("cf_Average", "Average") }),
        el("th", { class: "cf-num", text: t("cf_Responses", "responses") }),
        el("th", { class: "cf-bar-cell" })
      ));
      const body = el("tbody", {}, b.rows.length === 0 ? el("tr", {}, el("td", { colspan: "5", class: "cf-muted", text: t("cf_NoResponses", "No responses in this period.") })) : b.rows.map((r) => {
        const bar = el("div", { class: "cf-bar" });
        bar.style.width = `${Math.round(100 * r.responses / max)}%`;
        return el(
          "tr",
          { class: r.low_sample ? "cf-low-sample" : "", title: r.low_sample ? t("cf_TooFew", "too few responses") : "" },
          el("td", { text: nameOf(kind, r.key) }),
          el("td", { class: "cf-num", text: r.low_sample ? "\u2014" : scoreText(b.scale, r.score) }),
          el("td", { class: "cf-num", text: r.low_sample ? "\u2014" : fmt(r.average) }),
          el("td", { class: "cf-num", text: r.responses.toLocaleString() }),
          el("td", { class: "cf-bar-cell" }, bar)
        );
      }));
      table.replaceChildren(head, body);
      $id("cf-breakdown-notes").replaceChildren(el("p", {
        class: "cf-note",
        text: t("cf_LowSampleNote", "Groups with fewer than {min} responses are greyed out: their score would swing too much to mean anything.").replace("{min}", b.min_sample)
      }));
    });
  }
  var HEALTH_STEPS = { offered: ["cf_StepOffered", "Offered the survey"], completed: ["cf_StepCompleted", "Answered every question"] };
  function loadHealth() {
    const table = $id("cf-health-table");
    if (!state.versions.length) {
      table.replaceChildren();
      $id("cf-health-notes").replaceChildren(noVersions());
      return;
    }
    get("health", resultsQuery()).then((h) => {
      const top = Math.max(1, h.steps[0].count);
      const head = el("thead", {}, el(
        "tr",
        {},
        el("th", { text: t("cf_Step", "Step") }),
        el("th", { class: "cf-num", text: t("cf_Callers", "Callers") }),
        el("th", { class: "cf-num", text: t("cf_OfOffered", "Of offered") }),
        el("th", { class: "cf-num", text: t("cf_DroppedHere", "Dropped here") }),
        el("th", { class: "cf-bar-cell" })
      ));
      const body = el("tbody", {}, h.steps.map((step) => {
        const bar = el("div", { class: "cf-bar" });
        bar.style.width = `${Math.round(100 * step.count / top)}%`;
        const name = HEALTH_STEPS[step.step] ? label(HEALTH_STEPS[step.step]) : `${t("cf_AnsweredQuestion", "Answered question")} ${step.step.slice(1)} (${scaleLabel(step.scale)})`;
        return el(
          "tr",
          {},
          el("td", { text: name }),
          el("td", { class: "cf-num", text: step.count.toLocaleString() }),
          el("td", { class: "cf-num", text: fmt(step.share, "%") }),
          el("td", { class: "cf-num", text: step.drop === null ? "" : step.drop.toLocaleString() }),
          el("td", { class: "cf-bar-cell" }, bar)
        );
      }));
      table.replaceChildren(head, body);
      const skipped = Object.entries(h.skipped).map(([reason, n]) => el(
        "tr",
        {},
        el("td", { text: SKIP_REASONS[reason] ? label(SKIP_REASONS[reason]) : reason }),
        el("td", { class: "cf-num", text: n.toLocaleString() })
      ));
      fill(
        $id("cf-health-notes"),
        el("h4", { class: "ui header", text: t("cf_NotAskedHeader", "Calls not asked, by rule") }),
        el("table", { class: "ui very basic compact table cf-table cf-narrow" }, el(
          "tbody",
          {},
          skipped,
          el(
            "tr",
            {},
            el("td", { text: t("cf_LeftOutShort", "asked, but left out: the call record shows less talk than the minimum") }),
            el("td", { class: "cf-num", text: h.excluded.toLocaleString() })
          )
        )),
        el("p", { class: "cf-note", text: t("cf_HealthHint", "A big drop at one question usually means its prompt is too long or unclear. Calls not asked are counted for all surveys together.") })
      );
    });
  }
  function loadVersions() {
    return get("versions").then((data) => {
      state.versions = data.versions;
      state.labels = data.labels;
      const current = $id("cf-version").value;
      $id("cf-version").replaceChildren(...data.versions.map((v) => el(
        "option",
        { value: String(v.id), selected: String(v.id) === current },
        `${v.name} \xB7 v${v.version} (${metricName(v.scale)})${v.status === "archived" ? ` \xB7 ${label(STATUSES.archived)}` : ""}`
      )));
    });
  }
  function refreshResults() {
    if (state.tab === "overview") loadOverview();
    if (state.tab === "breakdowns") loadBreakdown();
    if (state.tab === "health") loadHealth();
  }
  var FOLLOW_STATUSES = {
    open: ["cf_FollowOpen", "Open"],
    contacted: ["cf_FollowContacted", "Contacted"],
    resolved: ["cf_FollowResolved", "Resolved"]
  };
  function hours(seconds) {
    if (seconds === null || seconds === void 0) return "\u2014";
    if (seconds < 3600) return `${Math.max(1, Math.round(seconds / 60))} ${t("cf_Minutes", "min")}`;
    if (seconds < 172800) return `${Math.round(seconds / 3600)} ${t("cf_Hours", "h")}`;
    return `${Math.round(seconds / 86400)} ${t("cf_Days", "days")}`;
  }
  function callBackPanel(f, page) {
    if (f.callback === "none") return el("div", { class: "cf-muted", text: t("cf_CallBackNoNumberShort", "No number to call back") });
    const result = el("div");
    const extensions = state.options.extensions.map((x) => [x.number, x.name ? `${x.name} (${x.number})` : x.number]);
    const ext = select(`cf-cb-ext-${f.id}`, extensions, extensions.length ? extensions[0][0] : "");
    const typed = f.callback === "typed" ? el("input", { type: "text", id: `cf-cb-typed-${f.id}`, maxlength: "40", placeholder: t("cf_TypeNumber", "Type the number to call") }) : null;
    const go = () => {
      const extLabel = ext.selectedOptions[0] ? ext.selectedOptions[0].textContent : ext.value;
      const question = t("cf_ConfirmCallBack", "Ring {ext}, then call {number} through the outgoing routes?").replace("{ext}", extLabel).replace("{number}", f.caller_num);
      if (!window.confirm(question)) return;
      post("callBack", { id: f.id, extension: ext.value, typed: typed ? typed.value : "" }).then(
        () => {
          message(result, "info", t("cf_CallBackRinging", "Ringing {ext}. The call goes out when it answers.").replace("{ext}", extLabel));
        },
        (xhr) => message(result, "negative", failText(xhr, t("cf_CallBackFailed", "The call-back could not be placed.")))
      );
    };
    return el(
      "div",
      { class: "cf-callback" },
      el("div", { class: "ui form" }, el(
        "div",
        { class: "fields" },
        el("div", { class: "seven wide field" }, ext),
        typed ? el("div", { class: "six wide field" }, typed) : null,
        el("div", { class: "three wide field" }, el("button", {
          class: "ui small button",
          type: "button",
          disabled: extensions.length === 0,
          onclick: go
        }, el("i", { class: "phone icon" }), t("cf_CallBack", "Call back")))
      )),
      f.callback === "typed" ? el("div", { class: "cf-note", text: t("cf_CallBackTypeHint", "This number isn't on the list of safe prefixes (Settings), so type it in to confirm. Callers can fake their number to make the PBX call an expensive line.") }) : null,
      f.callbacks.length ? el("div", { class: "cf-muted", text: f.callbacks.map((c) => `${t("cf_CalledBack", "Called back")} ${dateTime(c.requested_at)} (${c.extension}${c.requested_by ? `, ${c.requested_by}` : ""})`).join(" \xB7 ") }) : null,
      result
    );
  }
  function loadFollowUps(page = 1) {
    const status = $id("cf-followup-status").value;
    get("followups", { status, page }).then((data) => {
      $id("cf-followup-summary").textContent = t("cf_FollowSummary", "{open} open, {contacted} contacted, {resolved} resolved \xB7 time to resolve: {time}").replace("{open}", data.counts.open).replace("{contacted}", data.counts.contacted).replace("{resolved}", data.counts.resolved).replace("{time}", hours(data.avg_close_seconds));
      const box = $id("cf-followups");
      if (data.rows.length === 0) {
        box.replaceChildren(el("p", { class: "cf-muted", text: t("cf_NoFollowUps", "No follow-ups here. Low scores open one automatically.") }));
        return;
      }
      const rows = data.rows.map((f) => {
        const result = el("div");
        const statusSelect = select(`cf-fu-status-${f.id}`, Object.keys(FOLLOW_STATUSES).map((k) => [k, label(FOLLOW_STATUSES[k])]), f.status);
        const note = el("textarea", { id: `cf-fu-note-${f.id}`, rows: "2", maxlength: "1000", text: f.note || "" });
        const answers = Object.keys(f.answers).sort().map((pos) => pos === "1" ? `${metricName(f.scale)}: ${f.answers[pos]}` : `${t("cf_Q", "Q")}${pos}: ${f.answers[pos]}`).join(" \xB7 ");
        return el(
          "tr",
          {},
          el("td", {}, el("div", { text: dateTime(f.ended_at) }), el("div", { class: "cf-muted", text: `${f.survey_name} \xB7 v${f.survey_version}` })),
          el("td", {}, el("div", { text: answers || "\u2014" })),
          el(
            "td",
            {},
            el("div", { text: f.caller_num || t("cf_Withheld", "withheld") }),
            el("div", { class: "cf-muted", text: [f.agent ? `${t("cf_B_agent", "Agent")}: ${nameOf("agent", f.agent)}` : "", f.queue ? `${t("cf_B_queue", "Queue")}: ${nameOf("queue", f.queue)}` : ""].filter(Boolean).join(" \xB7 ") }),
            callBackPanel(f, page)
          ),
          el(
            "td",
            { class: "cf-followup-edit" },
            el(
              "div",
              { class: "ui form" },
              statusSelect,
              note,
              el("button", { class: "ui small button", type: "button", text: t("cf_Save", "Save"), onclick: () => {
                post("saveFollowUp", { id: f.id, status: statusSelect.value, note: note.value }).then(() => loadFollowUps(page), (xhr) => message(result, "negative", failText(xhr, t("cf_SaveFailed", "The change could not be saved."))));
              } }),
              f.closed_by ? el("div", { class: "cf-muted", text: `${t("cf_ResolvedBy", "Resolved by")} ${f.closed_by}, ${dateTime(Number(f.closed_at))}` }) : null
            ),
            result
          )
        );
      });
      const pages = Math.ceil(data.total / data.page_size);
      fill(
        box,
        el(
          "table",
          { class: "ui very basic table cf-table" },
          el("thead", {}, el(
            "tr",
            {},
            el("th", { text: t("cf_When", "When") }),
            el("th", { text: t("cf_Answers", "Answers") }),
            el("th", { text: t("cf_Caller", "Caller") }),
            el("th", { text: t("cf_Status", "Status") })
          )),
          el("tbody", {}, rows)
        ),
        pages > 1 ? el(
          "div",
          { class: "cf-editor-actions" },
          el("button", { class: "ui basic small button", type: "button", disabled: page <= 1, text: t("cf_Previous", "Previous"), onclick: () => loadFollowUps(page - 1) }),
          el("span", { class: "cf-muted", text: `${page} / ${pages}` }),
          el("button", { class: "ui basic small button", type: "button", disabled: page >= pages, text: t("cf_Next", "Next"), onclick: () => loadFollowUps(page + 1) })
        ) : null,
        el("p", { class: "cf-note", text: t("cf_FollowUpsHint", "A follow-up opens when a call's headline score is at or below the threshold under Settings, once the call is confirmed in the call history.") })
      );
    });
  }
  function selectTab(tab) {
    state.tab = tab;
    document.querySelectorAll("#call-feedback [data-tab]").forEach((n) => n.classList.toggle("active", n.dataset.tab === tab));
    $id("cf-toolbar").classList.toggle("cf-hidden", !["overview", "breakdowns", "health"].includes(tab));
    clearError();
    if (["overview", "breakdowns", "health"].includes(tab)) {
      loadVersions().then(() => {
        refreshResults();
        Object.values(state.charts).forEach((c) => c.resize());
      });
    }
    if (tab === "surveys") {
      if (state.editing) openEditor(state.editing.survey.id);
      else loadSurveys();
    }
    if (tab === "settings") loadSettings();
    if (tab === "followups") loadVersions().then(() => loadFollowUps());
  }
  function init() {
    document.querySelectorAll("#cf-tabs .item").forEach((a) => a.addEventListener("click", () => selectTab(a.dataset.tab)));
    $id("cf-new-language").replaceChildren(...Object.keys(LANGUAGES).map((l) => el("option", { value: l }, languageLabel(l))));
    $id("cf-new-survey").addEventListener("click", () => {
      $id("cf-new-form").classList.remove("cf-hidden");
      $id("cf-new-name").focus();
    });
    $id("cf-new-cancel").addEventListener("click", () => $id("cf-new-form").classList.add("cf-hidden"));
    $id("cf-new-form").addEventListener("submit", createSurvey);
    applyPreset();
    $id("cf-preset").addEventListener("change", () => {
      applyPreset();
      refreshResults();
    });
    ["cf-from", "cf-to"].forEach((id) => $id(id).addEventListener("change", () => {
      $id("cf-preset").value = "custom";
      refreshResults();
    }));
    ["cf-version", "cf-direction"].forEach((id) => $id(id).addEventListener("change", refreshResults));
    $id("cf-breakdown").addEventListener("change", loadBreakdown);
    $id("cf-followup-status").addEventListener("change", () => loadFollowUps());
    window.addEventListener("resize", () => Object.values(state.charts).forEach((c) => c.resize()));
    get("options").then((options) => {
      state.options = options;
      return loadVersions();
    }).then(() => {
      const asked = new URLSearchParams(window.location.search).get("tab");
      const tabs = ["overview", "breakdowns", "health", "followups", "surveys", "settings"];
      selectTab(tabs.includes(asked) ? asked : state.versions.length ? "overview" : "surveys");
    });
  }
  $(document).ready(init);
})();
