/*
 * Call Feedback page (GPL-3.0-or-later).
 *
 * Security rules this file follows:
 * - Every string from the server (survey and route names, sound file names,
 *   caller numbers) is put into the page with textContent, never as HTML.
 * - Writes are AJAX POSTs; the core's TokenManager adds the Bearer token to
 *   jQuery's $.ajax, and the controller refuses writes without it.
 * - Nested values (questions, targets, settings) are sent as JSON strings
 *   and validated on the server; the page only shapes them.
 * - Chart tooltips are built by our own formatters, which escape every value
 *   with echarts.format.encodeHTML. Axis and series labels are drawn on the
 *   canvas, where markup has no effect.
 */

/* global globalRootUrl, globalTranslate, echarts, $ */

const BASE = `${globalRootUrl}module-call-feedback/`;
const t = (key, fallback) => (globalTranslate && globalTranslate[key]) || fallback;

const SCALES = {
  csat5: ['cf_ScaleCsat', 'CSAT: satisfaction, 1–5'],
  nps11: ['cf_ScaleNps', 'NPS: likely to recommend, 0–10'],
  ces5: ['cf_ScaleCes', 'CES: how easy it was, 1–5'],
  yesno: ['cf_ScaleYesNo', 'Yes or no (1 yes, 2 no)'],
};
const HEADLINE_SCALES = ['csat5', 'nps11', 'ces5'];
const LANGUAGES = { 'en-en': ['cf_LanguageEnglish', 'English'], 'ru-ru': ['cf_LanguageRussian', 'Russian'] };
const STATUSES = {
  draft: ['cf_StatusDraft', 'Draft', ''],
  active: ['cf_StatusActive', 'Active', 'green'],
  archived: ['cf_StatusArchived', 'Archived', 'grey'],
};
const PROBLEMS = {
  cf_ProblemNotDraft: 'Only a draft can be activated.',
  cf_ProblemNoQuestions: 'Add at least one question.',
  cf_ProblemMissingPrompt: 'Choose a sound file for the intro, the thank-you and every question.',
  cf_ProblemPromptMissingFile: 'A chosen sound file can no longer be played. Choose it again or pick another.',
  cf_ProblemNoTestCall: 'Place a test call on this version first.',
  cf_ProblemNoTargets: 'Choose at least one route.',
  cf_ProblemTargetTaken: 'Another active survey already runs on one of these routes.',
  cf_ProblemDefaultActionPlayback: 'The PBX\'s default incoming action plays a sound file, so no inbound survey can run. Change it under Incoming routes.',
  cf_ProblemOtherSurveyModule: 'Another post-call survey module is enabled and would survey the same calls. Disable it first.',
};
const PROMPT_FIELDS = [
  ['intro_prompt_id', 'cf_PromptIntro', 'Intro: "Please stay on the line for a short survey"'],
  ['invalid_prompt_id', 'cf_PromptInvalid', 'Invalid key (optional): "Sorry, that key is not valid"'],
  ['thanks_prompt_id', 'cf_PromptThanks', 'Thank you'],
];
const RULE_FIELDS = [
  ['min_talk_seconds', 'cf_MinTalk', 'Minimum talk time (seconds)', 0, 600],
  ['sample_percent', 'cf_Sampling', 'Survey this share of calls (%)', 1, 100],
  ['frequency_cap_days', 'cf_FrequencyCap', 'Don\'t ask the same number again for (days, 0 = always ask)', 0, 365],
];

const state = { options: null, editing: null, testPoll: null, tab: 'overview', versions: [], labels: null, charts: {} };

/** Headline metrics as [label key, fallback, score unit]. */
const METRICS = {
  csat5: ['cf_MetricCsat', 'CSAT', '%'],
  nps11: ['cf_MetricNps', 'NPS', ''],
  ces5: ['cf_MetricCes', 'Easy (CES)', '%'],
};
const WEEKDAYS = [['cf_Mon', 'Mon'], ['cf_Tue', 'Tue'], ['cf_Wed', 'Wed'], ['cf_Thu', 'Thu'], ['cf_Fri', 'Fri'], ['cf_Sat', 'Sat'], ['cf_Sun', 'Sun']];
const SKIP_REASONS = {
  too_short: ['cf_SkipTooShort', 'too short'],
  excluded: ['cf_SkipExcluded', 'excluded number'],
  capped: ['cf_SkipCapped', 'asked recently'],
  sampled_out: ['cf_SkipSampled', 'not sampled'],
  concurrency: ['cf_SkipConcurrency', 'too many surveys at once'],
};
const BLUE = '#2185d0';
const GREY = '#cbd2d9';
const RED = '#cc3b3b';
const AMBER = '#f0a02e';
const GREEN = '#2f9157';

/* ------------------------------------------------------------------ helpers */

const $id = (id) => document.getElementById(id);

function el(tag, attrs = {}, ...children) {
  const node = document.createElement(tag);
  Object.entries(attrs).forEach(([k, v]) => {
    if (v === undefined || v === null || v === false) return;
    if (k === 'class') node.className = v;
    else if (k === 'text') node.textContent = v;
    else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
    else if (v === true) node.setAttribute(k, '');
    else node.setAttribute(k, v);
  });
  children.flat().forEach((c) => {
    if (c !== null && c !== undefined && c !== false) node.append(c instanceof Node ? c : document.createTextNode(String(c)));
  });
  return node;
}

/** Replaces a node's children, skipping null and false (as el() does). */
const fill = (node, ...children) => node.replaceChildren(...children.flat().filter((c) => c !== null && c !== undefined && c !== false));

const label = (pair) => t(pair[0], pair[1]);
const scaleLabel = (s) => (SCALES[s] ? label(SCALES[s]) : s);
const languageLabel = (l) => (LANGUAGES[l] ? label(LANGUAGES[l]) : l);
const problemLabel = (key) => t(key, PROBLEMS[key] || key);
const dateTime = (epoch) => (epoch ? new Date(epoch * 1000).toLocaleString() : '—');

function failText(xhr, fallback) {
  const msg = xhr && xhr.responseJSON && xhr.responseJSON.message;
  if (typeof msg === 'string' && msg !== '') return PROBLEMS[msg] ? problemLabel(msg) : msg;
  return fallback;
}

function showError(message) {
  const box = $id('cf-error');
  box.textContent = message;
  box.classList.remove('cf-hidden');
}

const clearError = () => $id('cf-error').classList.add('cf-hidden');

function get(action, data = {}) {
  return $.ajax({ url: BASE + action, method: 'GET', data, dataType: 'json' })
    .then((r) => (r && r.success ? r.data : $.Deferred().reject({ responseJSON: r })))
    .fail((xhr) => showError(failText(xhr, t('cf_LoadFailed', 'Call Feedback could not load this data.'))));
}

function post(action, data) {
  clearError();
  return $.ajax({ url: BASE + action, method: 'POST', data, dataType: 'json' })
    .then((r) => (r && r.success ? r.data : $.Deferred().reject({ responseJSON: r })));
}

/** A Semantic UI message inside `box`, replacing any earlier one. */
function message(box, kind, text, items = []) {
  box.replaceChildren(el('div', { class: `ui ${kind} message` }, el('p', { text }),
    items.length ? el('ul', { class: 'list' }, items.map((i) => el('li', { text: i }))) : null));
}

function select(id, options, value, attrs = {}) {
  return el('select', { id, ...attrs }, options.map(([v, text]) => el('option', { value: String(v), selected: String(v) === String(value ?? '') }, text)));
}

function field(labelText, control, hint, width) {
  return el('div', { class: `${width ? `${width} wide ` : ''}field` },
    el('label', { for: control.id, text: labelText }), control, hint ? el('div', { class: 'cf-note', text: hint }) : null);
}

function numberInput(id, value, min, max, disabled) {
  return el('input', { type: 'number', id, value: String(value), min: String(min), max: String(max), step: '1', disabled });
}

function statusLabel(status) {
  const s = STATUSES[status] || [status, status, ''];
  return el('span', { class: `ui ${s[2]} small label`, text: label(s) });
}

function soundOptions(withNone) {
  const files = state.options.soundFiles.map((f) => [f.id, f.name]);
  return withNone ? [['', t('cf_NoSound', '— none —')], ...files] : [['', t('cf_ChooseSound', '— choose a sound file —')], ...files];
}

function routeText(direction, id) {
  const list = state.options.routes[direction] || [];
  const r = list.find((x) => x.id === id);
  if (!r) return `#${id}`;
  const via = r.provider ? `, ${r.provider}` : '';
  if (direction === 'in') {
    const what = r.number || t('cf_AnyNumber', 'any number');
    return r.name ? `${r.name} (${what}${via})` : `${what}${via ? ` (${r.provider})` : ''}`;
  }
  const what = r.prefix ? `${r.prefix}…` : t('cf_AnyNumber', 'any number');
  return r.name ? `${r.name} (${what})` : what;
}

/* ------------------------------------------------------------------ survey list */

function loadSurveys() {
  return get('surveys').then((rows) => {
    const table = $id('cf-surveys');
    const head = el('thead', {}, el('tr', {},
      el('th', { text: t('cf_Name', 'Name') }),
      el('th', { text: t('cf_Version', 'Version') }),
      el('th', { text: t('cf_Status', 'Status') }),
      el('th', { text: t('cf_RunsOn', 'Runs on') }),
      el('th', { class: 'cf-num', text: t('cf_Last7Days', 'Answered, last 7 days') })));
    const body = el('tbody');
    if (rows.length === 0) {
      body.append(el('tr', {}, el('td', { colspan: '5', class: 'cf-muted', text: t('cf_NoSurveys', 'No surveys yet. Create one to start.') })));
    }
    rows.forEach((s) => {
      const runsOn = s.targets.length === 0
        ? el('span', { class: 'cf-muted', text: t('cf_NoRoutes', 'No routes') })
        : s.targets.map((tg) => routeText(tg.direction, tg.route_id)).join('; ');
      body.append(el('tr', { class: 'cf-clickable', onclick: () => openEditor(s.id) },
        el('td', {}, el('a', { href: '#', text: s.name, onclick: (e) => e.preventDefault() })),
        el('td', { text: `v${s.version}` }),
        el('td', {}, statusLabel(s.status)),
        el('td', {}, runsOn),
        el('td', { class: 'cf-num', text: s.offered ? `${s.responded} / ${s.offered}` : '—' })));
    });
    table.replaceChildren(head, body);
  });
}

function showList() {
  state.editing = null;
  clearInterval(state.testPoll);
  $id('cf-editor').classList.add('cf-hidden');
  $id('cf-survey-list').classList.remove('cf-hidden');
  loadSurveys();
}

function createSurvey(e) {
  e.preventDefault();
  post('createSurvey', { name: $id('cf-new-name').value, language: $id('cf-new-language').value })
    .then((r) => {
      $id('cf-new-form').classList.add('cf-hidden');
      $id('cf-new-name').value = '';
      openEditor(r.id);
    }, (xhr) => showError(failText(xhr, t('cf_SaveFailed', 'The change could not be saved.'))));
}

/* ------------------------------------------------------------------ editor */

function openEditor(id) {
  clearError();
  clearInterval(state.testPoll);
  return get('survey', { id }).then((data) => {
    state.editing = data;
    $id('cf-survey-list').classList.add('cf-hidden');
    const box = $id('cf-editor');
    box.classList.remove('cf-hidden');
    box.replaceChildren(renderEditor(data));
  });
}

function questionBlock(q, index, count, draft) {
  const scales = (index === 0 ? HEADLINE_SCALES : Object.keys(SCALES)).map((s) => [s, scaleLabel(s)]);
  const id = (name) => `cf-q${index}-${name}`;
  return el('div', { class: 'cf-question', 'data-index': String(index) },
    el('div', { class: 'cf-question-head' },
      el('span', { text: index === 0 ? t('cf_HeadlineQuestion', 'Question 1: the headline score') : `${t('cf_Question', 'Question')} ${index + 1}` }),
      draft && index > 0 ? el('button', { class: 'ui basic mini button', type: 'button', text: t('cf_Remove', 'Remove'),
        onclick: () => editQuestions((qs) => qs.splice(index, 1)) }) : null),
    el('div', { class: 'fields' },
      field(t('cf_Scale', 'Scale'), select(id('scale'), scales, q.scale, { disabled: !draft }), null, 'six'),
      field(t('cf_QuestionPrompt', 'Question prompt'), select(id('prompt'), soundOptions(false), q.prompt_id, { disabled: !draft }), null, 'six'),
      field(t('cf_Timeout', 'Wait for a key (s)'), numberInput(id('timeout'), q.timeout_seconds, 2, 30, !draft), null, 'two'),
      field(t('cf_Retries', 'Retries'), numberInput(id('retries'), q.retries, 0, 5, !draft), null, 'two')),
    q.scale === 'nps11' ? el('div', { class: 'cf-note', text: t('cf_NpsHint', 'NPS takes 0–10. The prompt must say "press 1 then 0 for 10": a lone 1 counts after 2 seconds.') }) : null);
}

/** The questions as currently shown in the form. */
function readQuestions() {
  return [...document.querySelectorAll('#cf-editor .cf-question')].map((node) => {
    const i = node.dataset.index;
    return {
      scale: $id(`cf-q${i}-scale`).value,
      prompt_id: $id(`cf-q${i}-prompt`).value,
      timeout_seconds: $id(`cf-q${i}-timeout`).value,
      retries: $id(`cf-q${i}-retries`).value,
    };
  });
}

/** Changes the question list in the form (not saved until Save). */
function editQuestions(change) {
  const questions = readQuestions();
  change(questions);
  const list = $id('cf-questions');
  list.replaceChildren(...questions.map((q, i) => questionBlock(q, i, questions.length, true)));
  $id('cf-add-question').disabled = questions.length >= 3;
}

function renderEditor(data) {
  const s = data.survey;
  const draft = s.status === 'draft';
  const active = s.status === 'active';
  const archived = s.status === 'archived';
  const result = el('div', { id: 'cf-editor-result' });
  const targetKeys = new Set(data.targets.map((tg) => `${tg.direction}:${tg.route_id}`));

  const problems = problemsBox(data);
  const lockedNote = active
    ? el('div', { class: 'ui info message', text: t('cf_ActiveLocked', 'This version is live: its questions and prompts are fixed so results stay comparable. Rules and routes can still change. To change the wording, create a new version.') })
    : null;

  const targetList = (direction, heading) => el('div', {},
    el('h5', { class: 'ui header', text: heading }),
    el('div', { class: 'cf-targets' }, (state.options.routes[direction] || []).length === 0
      ? el('p', { class: 'cf-muted', text: t('cf_NoRoutesConfigured', 'No routes configured.') })
      : state.options.routes[direction].map((r) => el('div', { class: 'field' },
        el('div', { class: `ui checkbox${r.usable ? '' : ' disabled'}` },
          el('input', { type: 'checkbox', class: 'cf-target', 'data-direction': direction, 'data-route': String(r.id),
            id: `cf-target-${direction}-${r.id}`, checked: targetKeys.has(`${direction}:${r.id}`), disabled: archived || !r.usable }),
          el('label', { for: `cf-target-${direction}-${r.id}`, text: routeText(direction, r.id) + (r.usable ? '' : ` — ${t('cf_PatternRoute', 'number patterns are not supported')}`) }))))));

  const form = el('form', { class: 'ui form', onsubmit: (e) => { e.preventDefault(); saveEditor(result); } },
    el('h4', { class: 'ui dividing header', text: t('cf_SectionSurvey', 'Survey') }),
    el('div', { class: 'fields' },
      field(t('cf_Name', 'Name'), el('input', { type: 'text', id: 'cf-name', maxlength: '60', value: s.name, disabled: !draft }), null, 'ten'),
      field(t('cf_Language', 'Language'), select('cf-language', Object.keys(LANGUAGES).map((l) => [l, languageLabel(l)]), s.language, { disabled: !draft }),
        t('cf_LanguageHint', 'Record the prompts in this language.'), 'six')),

    el('h4', { class: 'ui dividing header', text: t('cf_SectionPrompts', 'Prompts') }),
    el('p', { class: 'cf-note', text: t('cf_PromptsHint', 'Prompts are sound files from Sound files (Phrase Studio can generate them). Upload them there first.') }),
    el('div', { class: 'three fields' }, PROMPT_FIELDS.map(([name, key, fallback]) =>
      field(t(key, fallback), select(`cf-${name}`, soundOptions(name === 'invalid_prompt_id'), s[name], { disabled: !draft })))),

    el('h4', { class: 'ui dividing header', text: t('cf_SectionQuestions', 'Questions') }),
    el('div', { id: 'cf-questions' }, Object.keys(s.questions).sort().map((pos, i) => questionBlock(s.questions[pos], i, Object.keys(s.questions).length, draft))),
    draft ? el('button', { class: 'ui basic small button', type: 'button', id: 'cf-add-question', text: t('cf_AddQuestion', 'Add a question'),
      disabled: Object.keys(s.questions).length >= 3,
      onclick: () => editQuestions((qs) => qs.push({ scale: 'yesno', prompt_id: '', timeout_seconds: 6, retries: 2 })) }) : null,

    el('h4', { class: 'ui dividing header', text: t('cf_SectionRules', 'Who gets surveyed') }),
    el('div', { class: 'three fields' }, RULE_FIELDS.map(([name, key, fallback, min, max]) =>
      field(t(key, fallback), numberInput(`cf-${name}`, s[name], min, max, archived)))),
    el('p', { class: 'cf-note', text: t('cf_RulesHint', 'Only calls an agent answered are surveyed, and never at the agent\'s choice. Excluded numbers and the limit on surveys running at once are under Settings.') }),

    el('h4', { class: 'ui dividing header', text: t('cf_SectionTargets', 'Runs on') }),
    targetList('in', t('cf_IncomingRoutes', 'Incoming routes: the caller is surveyed when the agent hangs up')),
    targetList('out', t('cf_OutgoingRoutes', 'Outgoing routes: the customer is surveyed when the agent hangs up')),
    el('p', { class: 'cf-note', text: t('cf_TargetsHint', 'Blind-transferred calls and calls the customer ends first are never surveyed.') }),

    el('div', { class: 'cf-editor-actions' },
      archived ? null : el('button', { class: 'ui primary button', type: 'submit', text: t('cf_Save', 'Save') }),
      draft ? el('button', { class: 'ui positive button', type: 'button', id: 'cf-activate', text: t('cf_Activate', 'Activate'), disabled: data.problems.length > 0,
        onclick: () => act('activateSurvey', s.id, result) }) : null,
      active ? el('button', { class: 'ui button', type: 'button', text: t('cf_NewVersion', 'New version to change wording'),
        onclick: () => act('newVersion', s.id, result, true) }) : null,
      el('button', { class: 'ui basic button', type: 'button', text: t('cf_Duplicate', 'Duplicate'), onclick: () => act('duplicateSurvey', s.id, result, true) }),
      archived ? null : el('button', { class: 'ui basic negative button', type: 'button', text: active ? t('cf_Stop', 'Stop (archive)') : t('cf_Archive', 'Archive'),
        onclick: () => { if (window.confirm(t('cf_ConfirmArchive', 'Archive this version? It stops running and can\'t be edited again. Its results are kept.'))) act('archiveSurvey', s.id, result); } })),
    result);

  return el('div', {},
    el('div', { class: 'cf-editor-head' },
      el('a', { href: '#', class: 'ui basic small button', onclick: (e) => { e.preventDefault(); showList(); } }, el('i', { class: 'arrow left icon' }), t('cf_AllSurveys', 'All surveys')),
      el('h3', { class: 'ui header', text: `${s.name} · v${s.version}` }),
      statusLabel(s.status)),
    problems, lockedNote, form, archived ? null : testCallPanel(data));
}

/** The activation checklist of a draft (empty when it can go live). */
function problemsBox(data) {
  if (data.survey.status !== 'draft' || data.problems.length === 0) return el('div', { id: 'cf-problems' });
  return el('div', { id: 'cf-problems', class: 'ui warning message cf-problems' },
    el('div', { class: 'header', text: t('cf_BeforeActivating', 'Before this version can go live:') }),
    el('ul', { class: 'list' }, data.problems.map((p) => el('li', { text: problemLabel(p) }))));
}

function testCallList(data) {
  const scales = data.survey.questions;
  return el('table', { class: 'ui very basic compact table cf-table', id: 'cf-test-calls' }, el('tbody', {},
    data.testCalls.length === 0
      ? el('tr', {}, el('td', { class: 'cf-muted', text: t('cf_NoTestCalls', 'No test calls on this version yet.') }))
      : data.testCalls.map((c) => el('tr', {},
        el('td', { text: dateTime(c.ended_at) }),
        el('td', { text: c.caller_num || '' }),
        el('td', { text: Object.keys(c.answers).length === 0 ? t('cf_NoKeys', 'No keys pressed')
          : Object.keys(c.answers).sort().map((pos) => `${t('cf_Q', 'Q')}${pos}: ${answerText(scales[pos], c.answers[pos])}`).join(' · ') })))));
}

function answerText(question, value) {
  if (question && question.scale === 'yesno') return value === 1 ? t('cf_Yes', 'yes') : t('cf_No', 'no');
  return String(value);
}

/** Re-reads the test calls and the checklist, leaving the rest of the form as it is. */
function refreshTests(id) {
  return get('survey', { id }).then((data) => {
    if (!state.editing || state.editing.survey.id !== id) return data;
    state.editing.problems = data.problems;
    state.editing.testCalls = data.testCalls;
    const list = $id('cf-test-calls');
    if (list) list.replaceWith(testCallList(data));
    const problems = $id('cf-problems');
    if (problems) problems.replaceWith(problemsBox(data));
    const activate = $id('cf-activate');
    if (activate) activate.disabled = data.problems.length > 0;
    return data;
  });
}

function testCallPanel(data) {
  const s = data.survey;
  const result = el('div');
  const extensions = state.options.extensions.map((x) => [x.number, x.name ? `${x.name} (${x.number})` : x.number]);
  const ring = () => {
    const before = data.testCalls.length ? data.testCalls[0].id : 0;
    post('testCall', { id: s.id, extension: $id('cf-test-extension').value }).then((r) => {
      message(result, 'info', t('cf_TestRinging', 'Ringing {ext}. Answer, listen, and press keys as a caller would; the call shows below when it ends.').replace('{ext}', r.ringing));
      clearInterval(state.testPoll);
      let tries = 0;
      state.testPoll = setInterval(() => {
        tries += 1;
        refreshTests(s.id).then((fresh) => {
          if ((fresh.testCalls.length && fresh.testCalls[0].id !== before) || tries > 36) {
            clearInterval(state.testPoll);
            if (tries <= 36) message(result, 'positive', t('cf_TestRecorded', 'Test call recorded.'));
          }
        });
      }, 5000);
    }, (xhr) => message(result, 'negative', failText(xhr, t('cf_TestFailed', 'The test call could not be placed.'))));
  };
  return el('div', {},
    el('h4', { class: 'ui dividing header', text: t('cf_SectionTest', 'Test call') }),
    el('p', { class: 'cf-note', text: t('cf_TestHint', 'Rings an extension and plays this version as a caller would hear it, without the rules. Test calls are kept apart from results.') }),
    el('div', { class: 'ui form' }, el('div', { class: 'fields' },
      field(t('cf_Extension', 'Extension'), select('cf-test-extension', extensions, extensions.length ? extensions[0][0] : ''), null, 'six'),
      el('div', { class: 'four wide field cf-form-buttons' },
        el('button', { class: 'ui button', type: 'button', text: t('cf_RingForTest', 'Ring for a test'), disabled: extensions.length === 0, onclick: ring }),
        el('button', { class: 'ui basic icon button', type: 'button', title: t('cf_Refresh', 'Refresh'), onclick: () => refreshTests(s.id) }, el('i', { class: 'sync icon' }))))),
    result,
    testCallList(data));
}

/** Saves what this version allows: everything on a draft, rules and routes otherwise. */
function saveEditor(result) {
  const s = state.editing.survey;
  const fields = {};
  RULE_FIELDS.forEach(([name]) => { fields[name] = $id(`cf-${name}`).value; });
  if (s.status === 'draft') {
    fields.name = $id('cf-name').value;
    fields.language = $id('cf-language').value;
    PROMPT_FIELDS.forEach(([name]) => { fields[name] = $id(`cf-${name}`).value; });
    fields.questions = readQuestions();
  }
  const targets = [...document.querySelectorAll('#cf-editor .cf-target:checked')]
    .map((box) => ({ direction: box.dataset.direction, route_id: box.dataset.route }));

  post('saveSurvey', { id: s.id, fields: JSON.stringify(fields) })
    .then(() => post('saveTargets', { id: s.id, targets: JSON.stringify(targets) }))
    .then(() => openEditor(s.id))
    .then(() => message($id('cf-editor-result'), 'positive', t('cf_Saved', 'Saved. Calls use the change within a few seconds.')),
      (xhr) => message(result, 'negative', failText(xhr, t('cf_SaveFailed', 'The change could not be saved.'))));
}

/** A survey action (activate, archive, new version, duplicate); `follow` opens the returned version. */
function act(action, id, result, follow = false) {
  post(action, { id })
    .then((r) => openEditor(follow && r && r.id ? r.id : id))
    .then(() => message($id('cf-editor-result'), 'positive', t('cf_Done', 'Done.')),
      (xhr) => message(result, 'negative', failText(xhr, t('cf_SaveFailed', 'The change could not be saved.'))));
}

/* ------------------------------------------------------------------ settings */

const SETTING_FIELDS = [
  ['csat_detractor_max', 'cf_CsatDetractor', 'CSAT scores up to this open a follow-up', 1, 4],
  ['nps_detractor_max', 'cf_NpsDetractor', 'NPS scores up to this open a follow-up', 0, 9],
  ['ces_detractor_max', 'cf_CesDetractor', 'CES scores up to this open a follow-up', 1, 4],
  ['min_sample', 'cf_MinSample', 'Responses a group needs before its score is shown', 1, 1000],
  ['max_concurrent', 'cf_MaxConcurrent', 'Surveys running at once, at most', 1, 500],
];

function loadSettings() {
  return get('settings').then((data) => {
    const result = el('div');
    const exclusionsResult = el('div');
    const s = data.settings;
    const form = el('form', { class: 'ui form', onsubmit: (e) => {
      e.preventDefault();
      const settings = { alert_emails: $id('cf-alert-emails').value };
      SETTING_FIELDS.forEach(([name]) => { settings[name] = $id(`cf-${name}`).value; });
      post('saveSettings', { settings: JSON.stringify(settings) })
        .then(() => message(result, 'positive', t('cf_Saved', 'Saved. Calls use the change within a few seconds.')),
          (xhr) => message(result, 'negative', failText(xhr, t('cf_SaveFailed', 'The change could not be saved.'))));
    } },
    el('h4', { class: 'ui dividing header', text: t('cf_SectionAlerts', 'Detractor alerts') }),
    data.mailEnabled ? null : el('div', { class: 'ui warning message', text: t('cf_MailOff', 'Email notifications are off in the PBX\'s mail settings, so no alerts are sent. Follow-ups are still listed.') }),
    field(t('cf_AlertEmails', 'Send alerts to'), el('textarea', { id: 'cf-alert-emails', rows: '2', text: s.alert_emails.join('\n') }),
      t('cf_AlertEmailsHint', 'One address per line, up to 10. Alerts carry a link to the follow-up, not the caller\'s number.')),
    el('div', { class: 'three fields' }, SETTING_FIELDS.slice(0, 3).map(([name, key, fallback, min, max]) =>
      field(t(key, fallback), numberInput(`cf-${name}`, s[name], min, max, false)))),
    el('h4', { class: 'ui dividing header', text: t('cf_SectionLimits', 'Results and limits') }),
    el('div', { class: 'two fields' }, SETTING_FIELDS.slice(3).map(([name, key, fallback, min, max]) =>
      field(t(key, fallback), numberInput(`cf-${name}`, s[name], min, max, false)))),
    el('button', { class: 'ui primary button', type: 'submit', text: t('cf_Save', 'Save') }),
    result);

    const exclusions = el('div', {},
      el('h4', { class: 'ui dividing header', text: t('cf_SectionExclusions', 'Excluded numbers') }),
      el('p', { class: 'cf-note', text: t('cf_ExclusionsHint', 'These numbers are never surveyed. Only digits are kept.') }),
      el('form', { class: 'ui form', onsubmit: (e) => {
        e.preventDefault();
        post('addExclusions', { numbers: $id('cf-excl-numbers').value, note: $id('cf-excl-note').value })
          .then(() => loadSettings(), (xhr) => message(exclusionsResult, 'negative', failText(xhr, t('cf_SaveFailed', 'The change could not be saved.'))));
      } },
      el('div', { class: 'fields' },
        field(t('cf_Numbers', 'Numbers, one per line'), el('textarea', { id: 'cf-excl-numbers', rows: '2' }), null, 'eight'),
        field(t('cf_Note', 'Note'), el('input', { type: 'text', id: 'cf-excl-note', maxlength: '200' }), null, 'six'),
        el('div', { class: 'two wide field cf-form-buttons' }, el('button', { class: 'ui button', type: 'submit', text: t('cf_Add', 'Add') })))),
      exclusionsResult,
      el('div', { class: 'cf-exclusions' }, el('table', { class: 'ui very basic compact table cf-table' }, el('tbody', {},
        data.exclusions.length === 0
          ? el('tr', {}, el('td', { class: 'cf-muted', text: t('cf_NoExclusions', 'No excluded numbers.') }))
          : data.exclusions.map((x) => el('tr', {},
            el('td', { text: x.number }),
            el('td', { class: 'cf-muted', text: x.note || '' }),
            el('td', { class: 'cf-num' }, el('button', { class: 'ui basic mini button', type: 'button', text: t('cf_Remove', 'Remove'),
              onclick: () => post('removeExclusion', { id: x.id }).then(() => loadSettings(),
                (xhr) => message(exclusionsResult, 'negative', failText(xhr, t('cf_SaveFailed', 'The change could not be saved.')))) }))))))));

    $id('cf-settings').replaceChildren(form, exclusions);
  });
}

/* ------------------------------------------------------------------ results */

const esc = (v) => echarts.format.encodeHTML(String(v));
const isoDate = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const fmt = (v, unit = '') => (v === null || v === undefined ? '—' : `${Number(v).toLocaleString()}${unit}`);

function applyPreset() {
  const preset = $id('cf-preset').value;
  if (preset === 'custom') return;
  const to = new Date();
  const from = new Date(to);
  from.setDate(to.getDate() - (Number(preset) - 1));
  $id('cf-from').value = isoDate(from);
  $id('cf-to').value = isoDate(to);
}

function resultsQuery(extra = {}) {
  return { survey: $id('cf-version').value, from: $id('cf-from').value, to: $id('cf-to').value, direction: $id('cf-direction').value, ...extra };
}

function chart(id) {
  if (!state.charts[id]) state.charts[id] = echarts.init($id(id), null, { renderer: 'canvas' });
  return state.charts[id];
}

function nameOf(kind, key) {
  if (key === '' || key === null || key === undefined) return t('cf_Unknown', 'Unknown');
  if (kind === 'weekday') return label(WEEKDAYS[Number(key) - 1] || [key, key]);
  if (kind === 'hour') return `${String(key).padStart(2, '0')}:00`;
  if (kind === 'direction') return key === 'in' ? t('cf_Inbound', 'Inbound') : t('cf_Outbound', 'Outbound');
  const names = kind === 'did' ? state.labels.dids : state.labels.extensions;
  return names && names[key] ? `${names[key]} (${key})` : String(key);
}

function metricName(scale) {
  return METRICS[scale] ? label(METRICS[scale]) : scale;
}

function scoreText(scale, value) {
  if (value === null || value === undefined) return '—';
  const unit = METRICS[scale] ? METRICS[scale][2] : '';
  return scale === 'nps11' ? `${value > 0 ? '+' : ''}${value}` : `${value}${unit}`;
}

function kpi(labelText, value, sub, change) {
  return el('div', { class: 'cf-kpi' },
    el('div', { class: 'cf-kpi-label', text: labelText }),
    el('div', { class: `cf-kpi-value${value === '—' ? ' cf-empty' : ''}`, text: value }),
    sub ? el('div', { class: 'cf-kpi-sub', text: sub }) : null,
    change);
}

function changeLine(cur, prev, unitText) {
  if (cur === null || prev === null || cur === undefined || prev === undefined) {
    return el('div', { class: 'cf-kpi-sub', text: t('cf_NoPrevious', 'No responses in the previous period') });
  }
  const delta = Math.round((cur - prev) * 10) / 10;
  if (delta === 0) return el('div', { class: 'cf-kpi-sub', text: t('cf_NoChange', 'No change vs previous period') });
  return el('div', { class: `cf-kpi-sub cf-change ${delta > 0 ? 'good' : 'bad'}`, text: `${delta > 0 ? '▲' : '▼'} ${Math.abs(delta)}${unitText} ${t('cf_VsPrevious', 'vs previous period')}` });
}

function noVersions() {
  return el('div', { class: 'ui info message', text: t('cf_NoResultsYet', 'Results appear here once a survey has been activated and callers have answered it.') });
}

function loadOverview() {
  const box = $id('cf-overview');
  if (!state.versions.length) {
    box.replaceChildren(noVersions());
    return;
  }
  get('overview', resultsQuery()).then((o) => {
    const scale = o.survey.scale;
    const small = o.score.responses < o.min_sample;
    const scoreSub = small
      ? t('cf_NotEnough', 'Not enough responses yet ({n} of {min})').replace('{n}', o.score.responses).replace('{min}', o.min_sample)
      : `${o.score.responses.toLocaleString()} ${t('cf_Responses', 'responses')}`;
    const tiles = [
      kpi(metricName(scale), small ? '—' : scoreText(scale, o.score.score), scoreSub,
        small ? null : changeLine(o.score.score, o.previous.score.responses >= o.min_sample ? o.previous.score.score : null, scale === 'nps11' ? '' : ` ${t('cf_Points', 'pts')}`)),
      kpi(t('cf_Average', 'Average'), small ? '—' : fmt(o.score.average), `${t('cf_Scale', 'Scale')}: ${scale === 'nps11' ? '0–10' : '1–5'}`),
      kpi(t('cf_ResponseRate', 'Response rate'), fmt(o.response_rate, '%'),
        t('cf_ResponseRateSub', '{started} of {offered} offered pressed a key').replace('{started}', o.started).replace('{offered}', o.offered)),
      kpi(t('cf_CompletionRate', 'Completion rate'), fmt(o.completion_rate, '%'),
        t('cf_CompletionRateSub', '{completed} answered every question').replace('{completed}', o.completed)),
      o.resolution
        ? kpi(t('cf_Resolved', 'Issue resolved'), o.resolution.responses ? fmt(o.resolution.score, '%') : '—',
          `${o.resolution.responses.toLocaleString()} ${t('cf_Responses', 'responses')}`)
        : kpi(t('cf_Excluded', 'Left out'), fmt(o.excluded), t('cf_ExcludedSub', 'calls the CDR shows were under the minimum talk time')),
    ];
    box.replaceChildren(el('div', { class: 'cf-kpis' }, tiles));
    renderTrend(o);
    renderDistribution(o);

    const skipped = Object.entries(o.skipped).filter(([, n]) => n > 0)
      .map(([reason, n]) => `${n.toLocaleString()} ${SKIP_REASONS[reason] ? label(SKIP_REASONS[reason]) : reason}`);
    fill($id('cf-overview-notes'),
      skipped.length ? el('p', { class: 'cf-note', text: `${t('cf_NotAsked', 'Not asked in this period:')} ${skipped.join(', ')}.` }) : null,
      el('p', { class: 'cf-note', text: t('cf_PatientCallers', 'Callers who hang up first and blind-transferred calls are never surveyed, so scores lean towards callers who stayed on the line.') }),
      scale === 'nps11' ? el('p', { class: 'cf-trademark', text: t('cf_NpsTrademark', 'Net Promoter, NPS and Net Promoter Score are registered trademarks of Bain & Company, Inc., Satmetrix Systems, Inc. and Fred Reichheld.') }) : null);
  });
}

function renderTrend(o) {
  const points = o.trend.points;
  const scale = o.survey.scale;
  chart('cf-chart-trend').setOption({
    grid: { left: 48, right: 48, top: 30, bottom: 30 },
    legend: { data: [metricName(scale), t('cf_Responses', 'responses')], top: 0 },
    tooltip: {
      trigger: 'axis',
      formatter: (params) => {
        const p = points[params[0].dataIndex];
        const score = p.low_sample ? t('cf_TooFew', 'too few responses') : scoreText(scale, p.score);
        return `${esc(p.period)}<br>${esc(metricName(scale))}: ${esc(score)}<br>${esc(t('cf_Responses', 'responses'))}: ${esc(p.responses)}`;
      },
    },
    xAxis: { type: 'category', data: points.map((p) => p.period) },
    yAxis: [
      { type: 'value', name: metricName(scale), min: scale === 'nps11' ? -100 : 0, max: 100 },
      { type: 'value', name: t('cf_Responses', 'responses'), minInterval: 1, splitLine: { show: false } },
    ],
    series: [
      { name: t('cf_Responses', 'responses'), type: 'bar', yAxisIndex: 1, data: points.map((p) => p.responses), itemStyle: { color: '#e4e7eb' } },
      { name: metricName(scale), type: 'line', connectNulls: false, symbolSize: 7, lineStyle: { color: BLUE }, itemStyle: { color: BLUE },
        data: points.map((p) => ({ value: p.score, itemStyle: { color: p.low_sample ? GREY : BLUE } })) },
    ],
  }, true);
}

function renderDistribution(o) {
  const scale = o.survey.scale;
  const keys = Object.keys(o.score.distribution).map(Number).sort((a, b) => a - b);
  const color = (v) => {
    if (scale === 'nps11') return v <= 6 ? RED : (v <= 8 ? AMBER : GREEN);
    return v <= 2 ? RED : (v === 3 ? AMBER : GREEN);
  };
  chart('cf-chart-distribution').setOption({
    grid: { left: 48, right: 20, top: 10, bottom: 30 },
    tooltip: { trigger: 'axis', formatter: (p) => `${esc(p[0].name)}: ${esc(p[0].value)}` },
    xAxis: { type: 'category', data: keys.map(String) },
    yAxis: { type: 'value', minInterval: 1 },
    series: [{ type: 'bar', data: keys.map((k) => ({ value: o.score.distribution[k], itemStyle: { color: color(k) } })) }],
  }, true);
}

function loadBreakdown() {
  const table = $id('cf-breakdown-table');
  if (!state.versions.length) {
    table.replaceChildren();
    $id('cf-breakdown-notes').replaceChildren(noVersions());
    return;
  }
  const kind = $id('cf-breakdown').value;
  get('breakdown', resultsQuery({ breakdown: kind })).then((b) => {
    const max = Math.max(1, ...b.rows.map((r) => r.responses));
    const head = el('thead', {}, el('tr', {},
      el('th', { text: $id('cf-breakdown').selectedOptions[0].textContent }),
      el('th', { class: 'cf-num', text: metricName(b.scale) }),
      el('th', { class: 'cf-num', text: t('cf_Average', 'Average') }),
      el('th', { class: 'cf-num', text: t('cf_Responses', 'responses') }),
      el('th', { class: 'cf-bar-cell' })));
    const body = el('tbody', {}, b.rows.length === 0
      ? el('tr', {}, el('td', { colspan: '5', class: 'cf-muted', text: t('cf_NoResponses', 'No responses in this period.') }))
      : b.rows.map((r) => {
        const bar = el('div', { class: 'cf-bar' });
        bar.style.width = `${Math.round((100 * r.responses) / max)}%`;
        return el('tr', { class: r.low_sample ? 'cf-low-sample' : '', title: r.low_sample ? t('cf_TooFew', 'too few responses') : '' },
          el('td', { text: nameOf(kind, r.key) }),
          el('td', { class: 'cf-num', text: r.low_sample ? '—' : scoreText(b.scale, r.score) }),
          el('td', { class: 'cf-num', text: r.low_sample ? '—' : fmt(r.average) }),
          el('td', { class: 'cf-num', text: r.responses.toLocaleString() }),
          el('td', { class: 'cf-bar-cell' }, bar));
      }));
    table.replaceChildren(head, body);
    $id('cf-breakdown-notes').replaceChildren(el('p', { class: 'cf-note',
      text: t('cf_LowSampleNote', 'Groups with fewer than {min} responses are greyed out: their score would swing too much to mean anything.').replace('{min}', b.min_sample) }));
  });
}

function loadVersions() {
  return get('versions').then((data) => {
    state.versions = data.versions;
    state.labels = data.labels;
    const current = $id('cf-version').value;
    $id('cf-version').replaceChildren(...data.versions.map((v) => el('option', { value: String(v.id), selected: String(v.id) === current },
      `${v.name} · v${v.version} (${metricName(v.scale)})${v.status === 'archived' ? ` · ${label(STATUSES.archived)}` : ''}`)));
  });
}

function refreshResults() {
  if (state.tab === 'overview') loadOverview();
  if (state.tab === 'breakdowns') loadBreakdown();
}

/* ------------------------------------------------------------------ follow-ups */

const FOLLOW_STATUSES = {
  open: ['cf_FollowOpen', 'Open'],
  contacted: ['cf_FollowContacted', 'Contacted'],
  resolved: ['cf_FollowResolved', 'Resolved'],
};

function hours(seconds) {
  if (seconds === null || seconds === undefined) return '—';
  if (seconds < 3600) return `${Math.max(1, Math.round(seconds / 60))} ${t('cf_Minutes', 'min')}`;
  if (seconds < 172800) return `${Math.round(seconds / 3600)} ${t('cf_Hours', 'h')}`;
  return `${Math.round(seconds / 86400)} ${t('cf_Days', 'days')}`;
}

function loadFollowUps(page = 1) {
  const status = $id('cf-followup-status').value;
  get('followups', { status, page }).then((data) => {
    $id('cf-followup-summary').textContent = t('cf_FollowSummary', '{open} open, {contacted} contacted, {resolved} resolved · time to resolve: {time}')
      .replace('{open}', data.counts.open).replace('{contacted}', data.counts.contacted)
      .replace('{resolved}', data.counts.resolved).replace('{time}', hours(data.avg_close_seconds));
    const box = $id('cf-followups');
    if (data.rows.length === 0) {
      box.replaceChildren(el('p', { class: 'cf-muted', text: t('cf_NoFollowUps', 'No follow-ups here. Low scores open one automatically.') }));
      return;
    }
    const rows = data.rows.map((f) => {
      const result = el('div');
      const statusSelect = select(`cf-fu-status-${f.id}`, Object.keys(FOLLOW_STATUSES).map((k) => [k, label(FOLLOW_STATUSES[k])]), f.status);
      const note = el('textarea', { id: `cf-fu-note-${f.id}`, rows: '2', maxlength: '1000', text: f.note || '' });
      const answers = Object.keys(f.answers).sort().map((pos) => (pos === '1'
        ? `${metricName(f.scale)}: ${f.answers[pos]}` : `${t('cf_Q', 'Q')}${pos}: ${f.answers[pos]}`)).join(' · ');
      return el('tr', {},
        el('td', {}, el('div', { text: dateTime(f.ended_at) }), el('div', { class: 'cf-muted', text: `${f.survey_name} · v${f.survey_version}` })),
        el('td', {}, el('div', { text: answers || '—' })),
        el('td', {}, el('div', { text: f.caller_num || t('cf_Withheld', 'withheld') }),
          el('div', { class: 'cf-muted', text: [f.agent ? `${t('cf_B_agent', 'Agent')}: ${nameOf('agent', f.agent)}` : '', f.queue ? `${t('cf_B_queue', 'Queue')}: ${nameOf('queue', f.queue)}` : '']
            .filter(Boolean).join(' · ') })),
        el('td', { class: 'cf-followup-edit' }, el('div', { class: 'ui form' }, statusSelect, note,
          el('button', { class: 'ui small button', type: 'button', text: t('cf_Save', 'Save'), onclick: () => {
            post('saveFollowUp', { id: f.id, status: statusSelect.value, note: note.value })
              .then(() => loadFollowUps(page), (xhr) => message(result, 'negative', failText(xhr, t('cf_SaveFailed', 'The change could not be saved.'))));
          } }),
          f.closed_by ? el('div', { class: 'cf-muted', text: `${t('cf_ResolvedBy', 'Resolved by')} ${f.closed_by}, ${dateTime(Number(f.closed_at))}` }) : null),
        result));
    });
    const pages = Math.ceil(data.total / data.page_size);
    fill(box,
      el('table', { class: 'ui very basic table cf-table' },
        el('thead', {}, el('tr', {},
          el('th', { text: t('cf_When', 'When') }), el('th', { text: t('cf_Answers', 'Answers') }),
          el('th', { text: t('cf_Caller', 'Caller') }), el('th', { text: t('cf_Status', 'Status') }))),
        el('tbody', {}, rows)),
      pages > 1 ? el('div', { class: 'cf-editor-actions' },
        el('button', { class: 'ui basic small button', type: 'button', disabled: page <= 1, text: t('cf_Previous', 'Previous'), onclick: () => loadFollowUps(page - 1) }),
        el('span', { class: 'cf-muted', text: `${page} / ${pages}` }),
        el('button', { class: 'ui basic small button', type: 'button', disabled: page >= pages, text: t('cf_Next', 'Next'), onclick: () => loadFollowUps(page + 1) })) : null,
      el('p', { class: 'cf-note', text: t('cf_FollowUpsHint', 'A follow-up opens when a call\'s headline score is at or below the threshold under Settings, once the call is confirmed in the call history.') }));
  });
}

/* ------------------------------------------------------------------ tabs */

function selectTab(tab) {
  state.tab = tab;
  document.querySelectorAll('#call-feedback [data-tab]').forEach((n) => n.classList.toggle('active', n.dataset.tab === tab));
  $id('cf-toolbar').classList.toggle('cf-hidden', !['overview', 'breakdowns'].includes(tab));
  clearError();
  if (tab === 'overview' || tab === 'breakdowns') {
    loadVersions().then(() => { refreshResults(); Object.values(state.charts).forEach((c) => c.resize()); });
  }
  if (tab === 'surveys') {
    if (state.editing) openEditor(state.editing.survey.id); else loadSurveys();
  }
  if (tab === 'settings') loadSettings();
  if (tab === 'followups') loadVersions().then(() => loadFollowUps());
}

function init() {
  document.querySelectorAll('#cf-tabs .item').forEach((a) => a.addEventListener('click', () => selectTab(a.dataset.tab)));
  $id('cf-new-language').replaceChildren(...Object.keys(LANGUAGES).map((l) => el('option', { value: l }, languageLabel(l))));
  $id('cf-new-survey').addEventListener('click', () => { $id('cf-new-form').classList.remove('cf-hidden'); $id('cf-new-name').focus(); });
  $id('cf-new-cancel').addEventListener('click', () => $id('cf-new-form').classList.add('cf-hidden'));
  $id('cf-new-form').addEventListener('submit', createSurvey);
  applyPreset();
  $id('cf-preset').addEventListener('change', () => { applyPreset(); refreshResults(); });
  ['cf-from', 'cf-to'].forEach((id) => $id(id).addEventListener('change', () => { $id('cf-preset').value = 'custom'; refreshResults(); }));
  ['cf-version', 'cf-direction'].forEach((id) => $id(id).addEventListener('change', refreshResults));
  $id('cf-breakdown').addEventListener('change', loadBreakdown);
  $id('cf-followup-status').addEventListener('change', () => loadFollowUps());
  window.addEventListener('resize', () => Object.values(state.charts).forEach((c) => c.resize()));
  get('options').then((options) => {
    state.options = options;
    return loadVersions();
  }).then(() => {
    // The alert email links to ?tab=followups.
    const asked = new URLSearchParams(window.location.search).get('tab');
    const tabs = ['overview', 'breakdowns', 'followups', 'surveys', 'settings'];
    selectTab(tabs.includes(asked) ? asked : (state.versions.length ? 'overview' : 'surveys'));
  });
}

$(document).ready(init);
