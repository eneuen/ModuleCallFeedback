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

/* ------------------------------------------------------------------ colours */

/**
 * Colour shades from lightest (0) to darkest (9), the same sets as the Call
 * Insights module: cool greys for text, axes and anything secondary, and
 * one set per hue used for meaning. The stylesheet repeats the ones it needs.
 */
const SHADES = {
  grey: ['#f5f7fa', '#e4e7eb', '#cbd2d9', '#9aa5b1', '#7b8794', '#616e7c', '#52606d', '#3e4c59', '#323f4b', '#1f2933'],
  blue: ['#ebf4fc', '#cfe5f7', '#a5cdef', '#6eafe3', '#3d93d8', '#2185d0', '#1a6fb0', '#155a8f', '#10456e', '#0b3250'],
  green: ['#e6f6ec', '#c1e8cf', '#93d6ac', '#5fc086', '#38a866', '#2f9157', '#247548', '#1b5c39', '#13432a', '#0b2b1b'],
  red: ['#fdecec', '#f8cfcf', '#f1a6a6', '#e77777', '#dc5353', '#cc3b3b', '#ab2f2f', '#8a2525', '#691c1c', '#481313'],
  amber: ['#fff6e5', '#fde8bf', '#fbd38d', '#f6b956', '#f0a02e', '#de8a15', '#b8700f', '#90560c', '#6a3f08', '#452905'],
};
const G = SHADES.grey;

/** Scores and volumes are the one blue; red, amber and green only mark how good an answer is. */
const PALETTE = {
  primary: SHADES.blue[5],
  primaryDark: SHADES.blue[7],
  lowSample: G[3],
  volume: G[1],
  bad: SHADES.red[4],
  neutral: SHADES.amber[4],
  good: SHADES.green[4],
};

/** One ECharts theme for every chart: text, axes, legend and tooltip in the shared greys. */
echarts.registerTheme('call-feedback', {
  color: [PALETTE.primary, G[3]],
  backgroundColor: 'transparent',
  textStyle: { color: G[7] },
  title: { textStyle: { color: G[6], fontSize: 13, fontWeight: 'normal' } },
  legend: { textStyle: { color: G[7] }, inactiveColor: G[2] },
  tooltip: { backgroundColor: '#fff', borderColor: G[2], borderWidth: 1, textStyle: { color: G[8] }, extraCssText: 'box-shadow: 0 2px 8px rgba(31, 41, 51, .12);' },
  categoryAxis: { axisLine: { lineStyle: { color: G[2] } }, axisTick: { show: false }, axisLabel: { color: G[6] }, splitLine: { lineStyle: { color: G[1] } } },
  valueAxis: { axisLine: { show: false }, axisLabel: { color: G[6] }, nameTextStyle: { color: G[6] }, splitLine: { lineStyle: { color: G[1] } } },
});

/* ------------------------------------------------------------------ fixed lists */

const SCALES = {
  csat5: ['cf_ScaleCsat', 'CSAT: Satisfaction, 1–5'],
  nps11: ['cf_ScaleNps', 'NPS: Likely to Recommend, 0–10'],
  ces5: ['cf_ScaleCes', 'CES: How Easy It Was, 1–5'],
  yesno: ['cf_ScaleYesNo', 'Yes or No (1 Yes, 2 No)'],
};
const HEADLINE_SCALES = ['csat5', 'nps11', 'ces5'];
const LANGUAGES = { 'en-en': ['cf_LanguageEnglish', 'English'], 'ru-ru': ['cf_LanguageRussian', 'Russian'] };
const STATUSES = {
  draft: ['cf_StatusDraft', 'Draft', ''],
  active: ['cf_StatusActive', 'Active', 'green'],
  archived: ['cf_StatusArchived', 'Archived', 'grey'],
};
const PROBLEMS = {
  cf_CallBackNoNumber: 'This case has no number to call back.',
  cf_CallBackTypeNumber: 'Type the caller\'s number exactly to call it back.',
  cf_CallBackTooSoon: 'This case was called back a moment ago. Wait half a minute before trying again.',
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
/** Prompts as [field, label key, fallback, hint key, hint fallback]. */
const PROMPT_FIELDS = [
  ['intro_prompt_id', 'cf_PromptIntro', 'Intro', 'cf_PromptIntroHint', 'For example: "Please stay on the line for a short survey."'],
  ['thanks_prompt_id', 'cf_PromptThanks', 'Thank You', 'cf_PromptThanksHint', 'Played after the last question.'],
  ['invalid_prompt_id', 'cf_PromptInvalid', 'Invalid Key (Optional)', 'cf_PromptInvalidHint', 'For example: "Sorry, that key is not valid."'],
];
const RULE_FIELDS = [
  ['min_talk_seconds', 'cf_MinTalk', 'Minimum Talk Time (Seconds)', 0, 600, 'cf_MinTalkHint', 'Shorter calls are not surveyed.'],
  ['sample_percent', 'cf_Sampling', 'Share of Calls to Survey (%)', 1, 100, null, null],
  ['frequency_cap_days', 'cf_FrequencyCap', 'Ask a Number Again After (Days)', 0, 365, 'cf_FrequencyCapHint', '0: every call is asked.'],
];

const state = {
  options: null, editing: null, testPoll: null, tab: 'overview', versions: [], labels: null, charts: {},
  followStatus: 'open', followUp: null,
};

/** Headline metrics as [label key, fallback, score unit]. */
const METRICS = {
  csat5: ['cf_MetricCsat', 'CSAT', '%'],
  nps11: ['cf_MetricNps', 'NPS', ''],
  ces5: ['cf_MetricCes', 'Easy (CES)', '%'],
};
const WEEKDAYS = [['cf_Mon', 'Mon'], ['cf_Tue', 'Tue'], ['cf_Wed', 'Wed'], ['cf_Thu', 'Thu'], ['cf_Fri', 'Fri'], ['cf_Sat', 'Sat'], ['cf_Sun', 'Sun']];
const SKIP_REASONS = {
  too_short: ['cf_SkipTooShort', 'Too short'],
  outside_hours: ['cf_SkipOutsideHours', 'Outside survey hours'],
  excluded: ['cf_SkipExcluded', 'Excluded number'],
  capped: ['cf_SkipCapped', 'Asked recently'],
  sampled_out: ['cf_SkipSampled', 'Not sampled'],
  concurrency: ['cf_SkipConcurrency', 'Too many surveys at once'],
};

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
/** "6 Nov, 14:05": short enough for a table column. */
const shortDateTime = (epoch) => (epoch ? new Date(epoch * 1000).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—');
const shortDate = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
const count = (n) => Number(n).toLocaleString();
const sub = (text, values) => Object.entries(values).reduce((s, [k, v]) => s.split(`{${k}}`).join(String(v)), text);

function failText(xhr, fallback) {
  const msg = xhr && xhr.responseJSON && xhr.responseJSON.message;
  if (typeof msg === 'string' && msg !== '') return PROBLEMS[msg] ? problemLabel(msg) : msg;
  return fallback;
}

function showError(text) {
  const box = $id('cf-error');
  box.textContent = text;
  box.classList.remove('cf-hidden');
}

const clearError = () => $id('cf-error').classList.add('cf-hidden');
const saveFailed = (xhr) => failText(xhr, t('cf_SaveFailed', 'The change could not be saved.'));

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
  return withNone ? [['', t('cf_NoSound', '— None —')], ...files] : [['', t('cf_ChooseSound', '— Choose a Sound File —')], ...files];
}

const extensionOptions = () => state.options.extensions.map((x) => [x.number, x.name ? `${x.name} (${x.number})` : x.number]);

/** A route in full, for the editor's checkboxes: "Support (74951234567, Carrier)". */
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

/** A route in short, for the survey list: its name, or else its number or prefix. */
function routeShort(direction, id) {
  const r = (state.options.routes[direction] || []).find((x) => x.id === id);
  if (!r) return `#${id}`;
  if (r.name) return r.name;
  if (direction === 'in') return r.number || t('cf_AnyNumber', 'any number');
  return r.prefix ? `${r.prefix}…` : t('cf_AnyNumber', 'any number');
}

/* ------------------------------------------------------------------ export */

/** One CSV cell: formula-like text gets an apostrophe, then RFC 4180 quoting. */
function csvCell(value) {
  let s = value === null || value === undefined ? '' : String(value);
  if (/^[=+\-@\t\r]/.test(s)) s = `'${s}`;
  return /[",\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

function download(filename, blob) {
  const a = el('a', { href: URL.createObjectURL(blob), download: filename });
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

const rangeSuffix = () => `${$id('cf-from').value}-${$id('cf-to').value}`;

/** The table as shown, without the bar column. The byte-order mark makes spreadsheets read UTF-8. */
function downloadTable(table, name) {
  const rows = [...table.rows].filter((tr) => !tr.querySelector('.cf-empty-cell'))
    .map((tr) => [...tr.cells].filter((c) => !c.classList.contains('cf-bar-cell')).map((c) => c.textContent.trim()));
  const csv = rows.map((r) => r.map(csvCell).join(',')).join('\r\n');
  download(`${name}-${rangeSuffix()}.csv`, new Blob(['﻿', csv], { type: 'text/csv;charset=utf-8' }));
}

function downloadChart(id, name) {
  const c = state.charts[id];
  if (!c) return;
  // Decoded here: the core's CSP (connect-src) blocks fetch() of a data: URL.
  const bytes = atob(c.getDataURL({ type: 'png', pixelRatio: 2, backgroundColor: '#fff' }).split(',')[1]);
  download(`${name}-${rangeSuffix()}.png`, new Blob([Uint8Array.from(bytes, (ch) => ch.charCodeAt(0))], { type: 'image/png' }));
}

/** The actions box of the section heading right before `node`. */
function sectionActions(node) {
  for (let n = node.previousElementSibling; n; n = n.previousElementSibling) {
    if (n.classList.contains('cf-section-head')) return n.querySelector('.cf-section-actions');
  }
  return null;
}

/** A PNG button for each chart and a CSV button for each results table, in its section heading. */
function addExportButtons() {
  const button = (text, icon, title, onClick) => el('button', { class: 'ui mini basic compact button', type: 'button', title, onclick: onClick },
    el('i', { class: `${icon} icon` }), text);
  [['cf-chart-trend', 'score-over-time'], ['cf-chart-distribution', 'answers'], ['cf-chart-progress', 'where-callers-leave']].forEach(([id, name]) => {
    sectionActions($id(id)).append(button(t('cf_ExportPng', 'PNG'), 'image', t('cf_ExportPngHint', 'Download this chart as an image'), () => downloadChart(id, name)));
  });
  [['cf-breakdown-table', () => `score-by-${$id('cf-breakdown').value}`], ['cf-health-table', () => 'where-callers-leave'], ['cf-skipped-table', () => 'calls-not-surveyed']]
    .forEach(([id, name]) => {
      sectionActions($id(id)).append(button(t('cf_ExportCsv', 'CSV'), 'download', t('cf_ExportCsvHint', 'Download this table as CSV'), () => downloadTable($id(id), name())));
    });
}

/* ------------------------------------------------------------------ survey list */

function loadSurveys() {
  return get('surveys').then((rows) => {
    const table = $id('cf-surveys');
    const head = el('thead', {}, el('tr', {},
      el('th', { text: t('cf_Name', 'Name') }),
      el('th', { text: t('cf_Status', 'Status') }),
      el('th', { text: t('cf_RunsOn', 'Runs On') }),
      el('th', { class: 'cf-num', text: t('cf_Last7Days', 'Last 7 Days') })));
    const body = el('tbody');
    if (rows.length === 0) {
      body.append(el('tr', {}, el('td', { colspan: '4', class: 'cf-empty-cell', text: t('cf_NoSurveys', 'No surveys yet. Create one to start.') })));
    }
    rows.forEach((s) => {
      const runsOn = s.targets.length === 0
        ? el('span', { class: 'cf-muted', text: t('cf_NoRoutes', 'No routes') })
        : el('div', { class: 'cf-route-labels' }, s.targets.map((tg) => el('span', {
          class: 'ui small basic label', title: routeText(tg.direction, tg.route_id),
        }, el('i', { class: `${tg.direction === 'in' ? 'sign in alternate' : 'sign out alternate'} icon` }), routeShort(tg.direction, tg.route_id))));
      body.append(el('tr', { class: 'cf-clickable', onclick: () => openEditor(s.id) },
        el('td', {}, el('a', { href: '#', text: s.name, onclick: (e) => e.preventDefault() }),
          el('div', { class: 'cf-sub-line', text: `${t('cf_Version', 'Version')} ${s.version} · ${languageLabel(s.language)}` })),
        el('td', {}, statusLabel(s.status)),
        el('td', {}, runsOn),
        el('td', { class: 'cf-num', text: s.offered ? sub(t('cf_AnsweredOf', '{n} of {offered} answered'), { n: count(s.responded), offered: count(s.offered) }) : '—' })));
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
    }, (xhr) => showError(saveFailed(xhr)));
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

function questionBlock(q, index, draft) {
  const scales = (index === 0 ? HEADLINE_SCALES : Object.keys(SCALES)).map((s) => [s, scaleLabel(s)]);
  const id = (name) => `cf-q${index}-${name}`;
  return el('div', { class: 'cf-question', 'data-index': String(index) },
    el('div', { class: 'cf-question-head' },
      el('span', { text: index === 0 ? t('cf_HeadlineQuestion', 'Question 1: the Headline Score') : `${t('cf_Question', 'Question')} ${index + 1}` }),
      draft && index > 0 ? el('button', { class: 'ui basic mini button', type: 'button', text: t('cf_Remove', 'Remove'),
        onclick: () => editQuestions((qs) => qs.splice(index, 1)) }) : null),
    el('div', { class: 'fields' },
      field(t('cf_Scale', 'Scale'), select(id('scale'), scales, q.scale, { disabled: !draft }), null, 'six'),
      field(t('cf_QuestionPrompt', 'Prompt'), select(id('prompt'), soundOptions(false), q.prompt_id, { disabled: !draft }), null, 'six'),
      field(t('cf_Timeout', 'Wait (Seconds)'), numberInput(id('timeout'), q.timeout_seconds, 2, 30, !draft), null, 'two'),
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
  $id('cf-questions').replaceChildren(...questions.map((q, i) => questionBlock(q, i, true)));
  $id('cf-add-question').disabled = questions.length >= 3;
}

function renderEditor(data) {
  const s = data.survey;
  const draft = s.status === 'draft';
  const active = s.status === 'active';
  const archived = s.status === 'archived';
  const result = el('div', { id: 'cf-editor-result' });
  const targetKeys = new Set(data.targets.map((tg) => `${tg.direction}:${tg.route_id}`));
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

  const form = el('form', { class: 'ui form cf-editor', id: 'cf-editor-form', onsubmit: (e) => { e.preventDefault(); saveEditor(result); } },
    el('h4', { class: 'ui dividing header', text: t('cf_SectionSurvey', 'Survey') }),
    el('div', { class: 'fields' },
      field(t('cf_Name', 'Name'), el('input', { type: 'text', id: 'cf-name', maxlength: '60', value: s.name, disabled: !draft }), null, 'ten'),
      field(t('cf_Language', 'Language'), select('cf-language', Object.keys(LANGUAGES).map((l) => [l, languageLabel(l)]), s.language, { disabled: !draft }),
        t('cf_LanguageHint', 'Record the prompts in this language.'), 'six')),

    el('h4', { class: 'ui dividing header', text: t('cf_SectionPrompts', 'Prompts') }),
    el('p', { class: 'cf-note', text: t('cf_PromptsHint', 'Prompts are sound files from Sound files (Phrase Studio can generate them). Upload them there first.') }),
    el('div', { class: 'three fields' }, PROMPT_FIELDS.map(([name, key, fallback, hintKey, hint]) =>
      field(t(key, fallback), select(`cf-${name}`, soundOptions(name === 'invalid_prompt_id'), s[name], { disabled: !draft }), t(hintKey, hint)))),

    el('h4', { class: 'ui dividing header', text: t('cf_SectionQuestions', 'Questions') }),
    el('div', { id: 'cf-questions' }, Object.keys(s.questions).sort().map((pos, i) => questionBlock(s.questions[pos], i, draft))),
    draft ? el('button', { class: 'ui basic small button', type: 'button', id: 'cf-add-question', disabled: Object.keys(s.questions).length >= 3,
      onclick: () => editQuestions((qs) => qs.push({ scale: 'yesno', prompt_id: '', timeout_seconds: 6, retries: 2 })) },
    el('i', { class: 'plus icon' }), t('cf_AddQuestion', 'Add Question')) : null,

    el('h4', { class: 'ui dividing header', text: t('cf_SectionRules', 'Who Gets Surveyed') }),
    el('div', { class: 'three fields' }, RULE_FIELDS.map(([name, key, fallback, min, max, hintKey, hint]) =>
      field(t(key, fallback), numberInput(`cf-${name}`, s[name], min, max, archived), hintKey ? t(hintKey, hint) : null))),
    hoursFields(s, archived),
    el('p', { class: 'cf-note', text: t('cf_RulesHint', 'Only calls an agent answered are surveyed, and never at the agent\'s choice. Excluded numbers and the limit on surveys running at once are under Settings.') }),

    el('h4', { class: 'ui dividing header', text: t('cf_SectionTargets', 'Runs On') }),
    targetList('in', t('cf_IncomingRoutes', 'Incoming routes: the caller is surveyed when the agent hangs up')),
    targetList('out', t('cf_OutgoingRoutes', 'Outgoing routes: the customer is surveyed when the agent hangs up')),
    el('p', { class: 'cf-note', text: t('cf_TargetsHint', 'Blind-transferred calls and calls the customer ends first are never surveyed.') }));

  const headActions = el('div', { class: 'cf-head-actions' },
    archived ? null : el('button', { class: 'ui primary small button', type: 'submit', form: 'cf-editor-form', text: t('cf_Save', 'Save') }),
    active ? el('button', { class: 'ui basic small button', type: 'button', title: t('cf_NewVersionHint', 'A new draft with the same settings, to change the questions or prompts'),
      onclick: () => act('newVersion', s.id, result, true) }, el('i', { class: 'code branch icon' }), t('cf_NewVersion', 'New Version')) : null,
    el('button', { class: 'ui basic small button', type: 'button', onclick: () => act('duplicateSurvey', s.id, result, true) },
      el('i', { class: 'copy outline icon' }), t('cf_Duplicate', 'Duplicate')),
    archived ? null : el('button', { class: 'ui basic small negative button', type: 'button',
      onclick: () => { if (window.confirm(t('cf_ConfirmArchive', 'Archive this version? It stops running and can\'t be edited again. Its results are kept.'))) act('archiveSurvey', s.id, result); } },
    el('i', { class: 'archive icon' }), active ? t('cf_Stop', 'Stop and Archive') : t('cf_Archive', 'Archive')));

  // Save again at the end, with Activate after the test call that activation needs.
  const actions = archived ? null : el('div', { class: 'cf-editor-actions' },
    el('button', { class: 'ui primary button', type: 'submit', form: 'cf-editor-form', text: t('cf_Save', 'Save') }),
    draft ? el('button', { class: 'ui positive button', type: 'button', id: 'cf-activate', disabled: data.problems.length > 0,
      onclick: () => act('activateSurvey', s.id, result) }, el('i', { class: 'play icon' }), t('cf_Activate', 'Activate')) : null,
    draft ? el('span', { class: 'cf-actions-hint', id: 'cf-activate-hint', text: activateHint(data) }) : null);

  return el('div', {},
    el('div', { class: 'cf-editor-head' },
      el('a', { href: '#', class: 'ui basic small icon button', title: t('cf_AllSurveys', 'All Surveys'), 'aria-label': t('cf_AllSurveys', 'All Surveys'),
        onclick: (e) => { e.preventDefault(); showList(); } }, el('i', { class: 'arrow left icon' })),
      el('h3', { class: 'ui header', text: `${s.name} · ${t('cf_VersionShort', 'v')}${s.version}` }),
      statusLabel(s.status),
      headActions),
    problemsBox(data), lockedNote, form, archived ? null : testCallPanel(data), result, actions);
}

const activateHint = (data) => (data.problems.length
  ? sub(t('cf_ActivateBlocked', '{n} thing(s) to fix before activating: see the list at the top.'), { n: data.problems.length })
  : t('cf_ActivateReady', 'Ready to go live.'));

/** The activation checklist of a draft (empty when it can go live). */
function problemsBox(data) {
  if (data.survey.status !== 'draft' || data.problems.length === 0) return el('div', { id: 'cf-problems' });
  return el('div', { id: 'cf-problems', class: 'ui warning message cf-problems' },
    el('div', { class: 'header', text: t('cf_BeforeActivating', 'Before this version can go live:') }),
    el('ul', { class: 'list' }, data.problems.map((p) => el('li', { text: problemLabel(p) }))));
}

function testCallList(data) {
  const questions = data.survey.questions;
  return el('table', { class: 'ui very basic compact table cf-table', id: 'cf-test-calls' }, el('tbody', {},
    data.testCalls.length === 0
      ? el('tr', {}, el('td', { class: 'cf-muted', text: t('cf_NoTestCalls', 'No test calls on this version yet.') }))
      : data.testCalls.map((c) => el('tr', {},
        el('td', { text: shortDateTime(c.ended_at) }),
        el('td', { text: c.caller_num || '' }),
        el('td', { text: Object.keys(c.answers).length === 0 ? t('cf_NoKeys', 'No keys pressed')
          : Object.keys(c.answers).sort().map((pos) => `${t('cf_Q', 'Q')}${pos}: ${answerText(questions[pos], c.answers[pos])}`).join(' · ') })))));
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
    const hint = $id('cf-activate-hint');
    if (hint) hint.textContent = activateHint(data);
    return data;
  });
}

function testCallPanel(data) {
  const s = data.survey;
  const result = el('div');
  const extensions = extensionOptions();
  const ring = () => {
    const before = data.testCalls.length ? data.testCalls[0].id : 0;
    post('testCall', { id: s.id, extension: $id('cf-test-extension').value }).then((r) => {
      message(result, 'info', sub(t('cf_TestRinging', 'Ringing {ext}. Answer, listen, and press keys as a caller would; the call shows below when it ends.'), { ext: r.ringing }));
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
  return el('div', { class: 'cf-editor' },
    el('h4', { class: 'ui dividing header', text: t('cf_SectionTest', 'Test Call') }),
    el('p', { class: 'cf-note', text: t('cf_TestHint', 'Rings an extension and plays this version as a caller would hear it, without the rules. Test calls are kept apart from results.') }),
    el('div', { class: 'ui form' }, el('div', { class: 'fields' },
      field(t('cf_Extension', 'Extension'), select('cf-test-extension', extensions, extensions.length ? extensions[0][0] : ''), null, 'six'),
      el('div', { class: 'six wide field cf-form-buttons' },
        el('button', { class: 'ui button', type: 'button', disabled: extensions.length === 0, onclick: ring }, el('i', { class: 'phone icon' }), t('cf_RingForTest', 'Place Test Call')),
        el('button', { class: 'ui basic icon button', type: 'button', title: t('cf_Refresh', 'Refresh'), 'aria-label': t('cf_Refresh', 'Refresh'),
          onclick: () => refreshTests(s.id) }, el('i', { class: 'sync icon' }))))),
    result,
    testCallList(data));
}

/** Days (bit mask, Monday 1 ... Sunday 64) and times the survey runs. */
function hoursFields(s, disabled) {
  const days = WEEKDAYS.map((d, i) => el('div', { class: 'ui checkbox cf-day' },
    el('input', { type: 'checkbox', class: 'cf-hours-day', id: `cf-day-${i}`, 'data-bit': String(1 << i),
      checked: (s.hours_days & (1 << i)) !== 0, disabled }),
    el('label', { for: `cf-day-${i}`, text: label(d) })));
  return el('div', { class: 'fields' },
    el('div', { class: 'eight wide field' }, el('label', { text: t('cf_HoursDays', 'Days') }), el('div', { class: 'cf-days' }, days)),
    field(t('cf_HoursStart', 'From'), el('input', { type: 'time', id: 'cf-hours-start', value: s.hours_start || '', disabled }),
      t('cf_HoursStartHint', 'Empty: all day.'), 'four'),
    field(t('cf_HoursEnd', 'Until'), el('input', { type: 'time', id: 'cf-hours-end', value: s.hours_end || '', disabled }),
      t('cf_HoursHint', 'PBX time. An end before the start runs past midnight.'), 'four'));
}

/** Saves what this version allows: everything on a draft, rules and routes otherwise. */
function saveEditor(result) {
  const s = state.editing.survey;
  const fields = {};
  RULE_FIELDS.forEach(([name]) => { fields[name] = $id(`cf-${name}`).value; });
  fields.hours_days = String([...document.querySelectorAll('#cf-editor .cf-hours-day:checked')]
    .reduce((mask, box) => mask + Number(box.dataset.bit), 0));
  fields.hours_start = $id('cf-hours-start').value;
  fields.hours_end = $id('cf-hours-end').value;
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
      (xhr) => message(result, 'negative', saveFailed(xhr)));
}

/** A survey action (activate, archive, new version, duplicate); `follow` opens the returned version. */
function act(action, id, result, follow = false) {
  post(action, { id })
    .then((r) => openEditor(follow && r && r.id ? r.id : id))
    .then(() => message($id('cf-editor-result'), 'positive', t('cf_Done', 'Done.')),
      (xhr) => message(result, 'negative', saveFailed(xhr)));
}

/* ------------------------------------------------------------------ settings */

/** Detractor thresholds as [setting, label key, fallback, lowest score, highest threshold]. */
const THRESHOLDS = [
  ['csat_detractor_max', 'cf_CsatDetractor', 'CSAT (1–5)', 1, 4],
  ['nps_detractor_max', 'cf_NpsDetractor', 'NPS (0–10)', 0, 9],
  ['ces_detractor_max', 'cf_CesDetractor', 'CES (1–5)', 1, 4],
];
const LIMIT_FIELDS = [
  ['min_sample', 'cf_MinSample', 'Minimum Responses for a Score', 1, 1000, 'cf_MinSampleHint', 'Scores of smaller groups are shown as —, since they swing too much to mean anything.'],
  ['max_concurrent', 'cf_MaxConcurrent', 'Surveys at Once (Max)', 1, 500, 'cf_MaxConcurrentHint', 'Calls over the limit aren\'t surveyed, so surveys can\'t tie up trunk channels.'],
];

/** "about 12 months" or "90 days" for a retention period. */
function retentionText(days) {
  if (days % 30 === 0 && days >= 60) return sub(t('cf_Months', '{n} months'), { n: Math.round(days / 30) });
  return sub(t('cf_DaysN', '{n} days'), { n: days });
}

/** A settings section: a heading, an optional hint, then its content. */
const settingsSection = (title, hint, ...content) => el('div', { class: 'cf-settings-section' },
  el('h4', { class: 'ui header', text: title }), hint ? el('p', { class: 'cf-note', text: hint }) : null, content);

function loadSettings() {
  return get('settings').then((data) => {
    const s = data.settings;
    const result = el('div');
    const save = el('button', { class: 'ui primary button', type: 'submit', disabled: true, text: t('cf_Save', 'Save') });
    const thresholds = THRESHOLDS.map(([name, key, fallback, low, high]) => field(t(key, fallback),
      select(`cf-${name}`, Array.from({ length: high - low + 1 }, (_, i) => low + i).map((v) => [v, v === low ? String(v) : `${low}–${v}`]), s[name], { class: 'ui dropdown' }),
      null, 'four'));
    const mailLink = el('a', { href: `${globalRootUrl}mail-settings/modify`, text: t('cf_MailSettingsLink', 'Mail settings') });

    const form = el('form', { class: 'ui form', id: 'cf-settings-form', onsubmit: (e) => {
      e.preventDefault();
      const settings = { alert_emails: $id('cf-alert-emails').value, callback_prefixes: $id('cf-callback-prefixes').value,
        summary: $id('cf-summary').value };
      [...THRESHOLDS, ...LIMIT_FIELDS].forEach(([name]) => { settings[name] = $id(`cf-${name}`).value; });
      save.disabled = true;
      post('saveSettings', { settings: JSON.stringify(settings) })
        .then(() => message(result, 'positive', t('cf_Saved', 'Saved. Calls use the change within a few seconds.')),
          (xhr) => { save.disabled = false; message(result, 'negative', saveFailed(xhr)); });
    },
    // Save stays off until something changes, so it's clear whether there is anything to save.
    oninput: () => { save.disabled = false; result.replaceChildren(); },
    onchange: () => { save.disabled = false; result.replaceChildren(); } },

    settingsSection(t('cf_SectionFollowUps', 'Follow-Ups'),
      t('cf_ThresholdsHint', 'A headline score in these ranges opens a follow-up and sends an alert. Defaults: CSAT 1–2, NPS 0–6, CES 1–2.'),
      el('div', { class: 'fields' }, thresholds)),

    settingsSection(t('cf_SectionEmails', 'Emails'), null,
      data.mailEnabled ? null : el('div', { class: 'ui warning message' },
        t('cf_MailOff', 'Email notifications are off in the PBX\'s mail settings, so no emails are sent. Follow-ups are still listed.'), ' ', mailLink),
      el('div', { class: 'fields' },
        field(t('cf_AlertEmails', 'Alert Recipients'), el('textarea', { id: 'cf-alert-emails', rows: '3', text: s.alert_emails.join('\n') }),
          t('cf_AlertEmailsHint', 'One address per line, up to 10. Alerts link to the follow-up and never include the caller\'s number.'), 'eight'),
        field(t('cf_Summary', 'Summary Email'),
          select('cf-summary', [['off', t('cf_SummaryOff', 'Off')], ['daily', t('cf_SummaryDaily', 'Daily, for the Day Before')],
            ['weekly', t('cf_SummaryWeekly', 'Weekly on Monday, for the Last 7 Days')]], s.summary, { class: 'ui dropdown' }),
          t('cf_SummaryHint', 'To the alert recipients, after 08:00 PBX time: each active survey\'s score and responses, and the follow-ups still open.'), 'eight'))),

    settingsSection(t('cf_SectionCallBacks', 'Call-Backs'), null,
      el('div', { class: 'fields' }, field(t('cf_CallBackPrefixes', 'One-Click Prefixes'), el('input', { type: 'text', id: 'cf-callback-prefixes', value: s.callback_prefixes.join(' '), autocomplete: 'off' }),
        t('cf_CallBackPrefixesHint', 'Numbers starting with these digits are called back in one click; separate prefixes with spaces, for example 7495 8495. Any other number must be typed in first, so a faked international or premium-rate number can\'t be called by mistake. Empty: every number must be typed.'), 'eight'))),

    settingsSection(t('cf_SectionLimits', 'Results and Limits'), null,
      el('div', { class: 'fields' }, LIMIT_FIELDS.map(([name, key, fallback, min, max, hintKey, hint]) =>
        field(t(key, fallback), numberInput(`cf-${name}`, s[name], min, max, false), t(hintKey, hint), 'eight')))),

    el('div', { class: 'cf-settings-save' }, save, result));

    const exclusionsResult = el('div');
    const exclusions = settingsSection(
      data.exclusions.length ? sub(t('cf_SectionExclusionsN', 'Excluded Numbers ({n})'), { n: count(data.exclusions.length) }) : t('cf_SectionExclusions', 'Excluded Numbers'),
      t('cf_ExclusionsHint', 'Calls from these numbers are never surveyed. Only digits are kept. Changes apply at once.'),
      el('form', { class: 'ui form', onsubmit: (e) => {
        e.preventDefault();
        post('addExclusions', { numbers: $id('cf-excl-numbers').value, note: $id('cf-excl-note').value })
          .then(() => loadSettings(), (xhr) => message(exclusionsResult, 'negative', saveFailed(xhr)));
      } },
      el('div', { class: 'fields' },
        field(t('cf_Numbers', 'Numbers (One per Line)'), el('textarea', { id: 'cf-excl-numbers', rows: '2' }), null, 'seven'),
        field(t('cf_Note', 'Note'), el('input', { type: 'text', id: 'cf-excl-note', maxlength: '200', placeholder: t('cf_NoteOptional', 'Optional') }), null, 'six'),
        el('div', { class: 'three wide field cf-form-buttons' }, el('button', { class: 'ui button', type: 'submit' }, el('i', { class: 'plus icon' }), t('cf_Add', 'Add'))))),
      exclusionsResult,
      data.exclusions.length === 0
        ? el('p', { class: 'cf-muted', text: t('cf_NoExclusions', 'No excluded numbers.') })
        : el('div', { class: 'cf-exclusions' }, el('table', { class: 'ui very basic compact table cf-table' },
          el('thead', {}, el('tr', {}, el('th', { text: t('cf_Number', 'Number') }), el('th', { text: t('cf_Note', 'Note') }),
            el('th', { text: t('cf_Added', 'Added') }), el('th'))),
          el('tbody', {}, data.exclusions.map((x) => el('tr', {},
            el('td', { text: x.number }),
            el('td', { class: 'cf-muted', text: x.note || '' }),
            el('td', { class: 'cf-muted cf-nowrap', text: x.created_at ? new Date(x.created_at * 1000).toLocaleDateString() : '' }),
            el('td', { class: 'cf-num' }, el('button', { class: 'ui basic mini button', type: 'button', text: t('cf_Remove', 'Remove'),
              onclick: () => post('removeExclusion', { id: x.id }).then(() => loadSettings(),
                (xhr) => message(exclusionsResult, 'negative', saveFailed(xhr))) }))))))));

    const forgetResult = el('div');
    const unlimited = data.retentionDays < data.retentionUnlimitedBelow;
    const storageLink = el('a', { href: `${globalRootUrl}storage/index`, text: t('cf_StorageLink', 'Storage') });
    const privacy = settingsSection(t('cf_SectionPrivacy', 'Privacy'), null,
      el('p', { class: 'cf-note' },
        unlimited
          ? t('cf_RetentionUnlimited', 'The PBX keeps its call history without a time limit, so callers\' numbers are kept too.')
          : sub(t('cf_RetentionHint', 'The PBX keeps its call history for {period}. Callers\' numbers are removed from survey results after that, and so is the "don\'t ask again" memory; scores stay.'), { period: retentionText(data.retentionDays) }),
        ' ', t('cf_RetentionWhere', 'The period is set under'), ' ', storageLink, '.'),
      el('form', { class: 'ui form', onsubmit: (e) => {
        e.preventDefault();
        const number = $id('cf-forget-number').value.trim();
        if (!number || !window.confirm(sub(t('cf_ConfirmForget', 'Delete every survey answer and follow-up for {number}? This can\'t be undone.'), { number }))) return;
        post('forgetNumber', { number })
          .then((r) => { $id('cf-forget-number').value = ''; message(forgetResult, 'positive', sub(t('cf_Forgotten', 'Deleted {n} survey session(s) for that number.'), { n: r.sessions })); },
            (xhr) => message(forgetResult, 'negative', saveFailed(xhr)));
      } },
      el('div', { class: 'fields' },
        field(t('cf_ForgetNumber', 'Delete All Data for a Number'), el('input', { type: 'text', id: 'cf-forget-number', maxlength: '40', autocomplete: 'off' }), null, 'eight'),
        el('div', { class: 'four wide field cf-form-buttons' }, el('button', { class: 'ui basic negative button', type: 'submit' },
          el('i', { class: 'trash alternate outline icon' }), t('cf_Delete', 'Delete')))),
      el('p', { class: 'cf-note', text: t('cf_ForgetHint', 'Removes the number\'s survey answers and follow-ups at once, for example when a caller asks.') })),
      forgetResult);

    $id('cf-settings').replaceChildren(form, el('div', { class: 'ui divider cf-settings-divider' }), exclusions, privacy);
    $('#cf-settings .ui.dropdown').dropdown();
  });
}

/* ------------------------------------------------------------------ results */

const esc = (v) => echarts.format.encodeHTML(String(v));
const isoDate = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
/** Percentages and scores as whole numbers, averages with one decimal, everywhere on the page. */
const pct = (v) => (v === null || v === undefined ? '—' : `${Math.round(v)}%`);
const avg = (v) => (v === null || v === undefined ? '—' : Number(v).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }));

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
  if (!state.charts[id]) state.charts[id] = echarts.init($id(id), 'call-feedback', { renderer: 'canvas' });
  return state.charts[id];
}

/** Replaces a chart's content with a centred message (no axes). */
function showChartMessage(c, text) {
  c.setOption({ title: { text, left: 'center', top: 'middle' }, series: [], xAxis: [], yAxis: [], legend: [] }, true);
}

function nameOf(kind, key) {
  if (key === '' || key === null || key === undefined) return t('cf_Unknown', 'Unknown');
  if (kind === 'weekday') return label(WEEKDAYS[Number(key) - 1] || [key, key]);
  if (kind === 'hour') return `${String(key).padStart(2, '0')}:00`;
  if (kind === 'wait' || kind === 'talk') return bucketLabel(kind, Number(key));
  if (kind === 'direction') return key === 'in' ? t('cf_Inbound', 'Inbound') : t('cf_Outbound', 'Outbound');
  const names = kind === 'did' ? state.labels.dids : state.labels.extensions;
  return names && names[key] ? `${names[key]} (${key})` : String(key);
}

const BUCKETS = { wait: [0, 15, 30, 60, 120, 300], talk: [0, 60, 180, 300, 600] };

/** "15–30 s", "1–2 min", "5 min or more" for a bucket's lower bound. */
function bucketLabel(kind, from) {
  const bounds = BUCKETS[kind];
  const next = bounds[bounds.indexOf(from) + 1];
  const span = (sec) => (sec < 60 ? `${sec} s` : `${sec / 60} min`);
  if (next === undefined) return sub(t('cf_OrMore', '{from} or more'), { from: span(from) });
  // "15–30 s" and "1–2 min" when both ends share a unit, "30 s–1 min" when not.
  const sameUnit = (from < 60) === (next < 60);
  return sameUnit ? `${span(from).split(' ')[0]}–${span(next)}` : `${span(from)}–${span(next)}`;
}

const metricName = (scale) => (METRICS[scale] ? label(METRICS[scale]) : scale);

function scoreText(scale, value) {
  if (value === null || value === undefined) return '—';
  const n = Math.round(value);
  return scale === 'nps11' ? `${n > 0 ? '+' : ''}${n}` : `${n}${METRICS[scale] ? METRICS[scale][2] : ''}`;
}

/** Where a score sits on its scale, 0–100, for bars: NPS runs from -100. */
const scorePosition = (scale, value) => (scale === 'nps11' ? (value + 100) / 2 : value);

function kpi(labelText, value, subText, change, onClick) {
  return el('div', { class: `cf-kpi${onClick ? ' cf-clickable' : ''}`, onclick: onClick, role: onClick ? 'button' : null, tabindex: onClick ? '0' : null,
    onkeydown: onClick ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onClick(); } } : null },
  el('div', { class: 'cf-kpi-label', text: labelText }),
  el('div', { class: `cf-kpi-value${value === '—' ? ' cf-empty' : ''}`, text: value }),
  subText ? el('div', { class: 'cf-kpi-sub', text: subText }) : null,
  change);
}

/** "▲ 5 pts vs previous period", coloured by direction; higher is better on every scale. */
function changeLine(cur, prev, unitText) {
  if (cur === null || prev === null || cur === undefined || prev === undefined) {
    return el('div', { class: 'cf-change', text: t('cf_NoPrevious', 'No score for the previous period') });
  }
  const delta = Math.round(cur) - Math.round(prev);
  if (delta === 0) return el('div', { class: 'cf-change', text: t('cf_NoChange', 'No change vs previous period') });
  return el('div', { class: `cf-change ${delta > 0 ? 'good' : 'bad'}` },
    el('span', { class: 'cf-change-value', text: `${delta > 0 ? '▲' : '▼'} ${Math.abs(delta)}${unitText}` }),
    ` ${t('cf_VsPrevious', 'vs previous period')}`);
}

function noVersions() {
  return el('div', { class: 'ui info message', text: t('cf_NoResultsYet', 'Results appear here once a survey has been activated and callers have answered it.') });
}

/** Shows the results boxes, or only the "no results yet" message. */
function toggleResults(boxId, notesId, has) {
  $id(boxId).classList.toggle('cf-hidden', !has);
  if (!has) $id(notesId).replaceChildren(noVersions());
  return has;
}

function loadOverview() {
  const box = $id('cf-overview');
  if (!toggleResults('cf-overview-charts', 'cf-overview-notes', state.versions.length > 0)) {
    box.replaceChildren();
    return;
  }
  get('overview', resultsQuery()).then((o) => {
    const scale = o.survey.scale;
    const small = o.score.responses < o.min_sample;
    const scoreSub = small
      ? sub(t('cf_NotEnough', 'Not enough responses yet ({n} of {min})'), { n: o.score.responses, min: o.min_sample })
      : sub(t('cf_ScoreSub', '{n} responses · average {avg}'), { n: count(o.score.responses), avg: avg(o.score.average) });
    const previous = o.previous.score.responses >= o.min_sample ? o.previous.score.score : null;
    const followUps = o.followups.open + o.followups.contacted + o.followups.resolved;
    const tiles = [
      kpi(metricName(scale), small ? '—' : scoreText(scale, o.score.score), scoreSub,
        small ? null : changeLine(o.score.score, previous, ` ${t('cf_Points', 'pts')}`)),
      kpi(t('cf_ResponseRate', 'Response Rate'), pct(o.response_rate),
        sub(t('cf_ResponseRateSub', '{started} of {offered} offered pressed a key'), { started: count(o.started), offered: count(o.offered) })),
      o.resolution
        ? kpi(t('cf_Resolved', 'Issue Resolved'), o.resolution.responses >= o.min_sample ? pct(o.resolution.score) : '—',
          sub(t('cf_ResolvedSub', '{n} answered the yes/no question'), { n: count(o.resolution.responses) }))
        : kpi(t('cf_CompletionRate', 'Completion Rate'), pct(o.completion_rate),
          sub(t('cf_CompletionRateSub', '{completed} answered every question'), { completed: count(o.completed) })),
      kpi(t('cf_OpenFollowUps', 'Open Follow-Ups'), count(o.followups.open),
        followUps ? sub(t('cf_OpenFollowUpsSub', '{n} opened in this period'), { n: count(followUps) }) : t('cf_NoFollowUpsShort', 'None in this period'),
        null, () => { state.followStatus = 'open'; selectTab('followups'); }),
    ];
    box.replaceChildren(el('div', { class: 'cf-kpis' }, tiles));
    renderTrend(o);
    renderDistribution(o);

    const skipped = Object.entries(o.skipped).filter(([, n]) => n > 0)
      .map(([reason, n]) => `${(SKIP_REASONS[reason] ? label(SKIP_REASONS[reason]) : reason).toLowerCase()} ${count(n)}`);
    fill($id('cf-overview-notes'),
      o.resolution ? el('p', { class: 'cf-note', text: sub(t('cf_CompletionNote', 'Completion rate: {rate} of callers who pressed a key answered every question.'), { rate: pct(o.completion_rate) }) }) : null,
      skipped.length ? el('p', { class: 'cf-note', text: `${t('cf_NotAsked', 'Not asked in this period:')} ${skipped.join(', ')}.` }) : null,
      el('p', { class: 'cf-note', text: t('cf_PatientCallers', 'Callers who hang up first and blind-transferred calls are never surveyed, so scores lean towards callers who stayed on the line.') }),
      scale === 'nps11' ? el('p', { class: 'cf-trademark', text: t('cf_NpsTrademark', 'Net Promoter, NPS and Net Promoter Score are registered trademarks of Bain & Company, Inc., Satmetrix Systems, Inc. and Fred Reichheld.') }) : null);
  });
}

function renderTrend(o) {
  const points = o.trend.points;
  const scale = o.survey.scale;
  const weekly = o.trend.interval === 'week';
  $id('cf-trend-interval').textContent = weekly ? t('cf_ByWeek', 'by week') : t('cf_ByDay', 'by day');
  const c = chart('cf-chart-trend');
  if (!points.some((p) => p.responses > 0)) {
    showChartMessage(c, t('cf_NoResponses', 'No responses in this period.'));
    return;
  }
  const period = (p) => (weekly ? sub(t('cf_WeekOf', 'Week of {date}'), { date: shortDate(p.period) }) : shortDate(p.period));
  const responses = t('cf_ResponsesTitle', 'Responses');
  c.setOption({
    grid: { left: 48, right: 48, top: 36, bottom: 28 },
    legend: { data: [metricName(scale), responses], top: 0, right: 0, icon: 'roundRect', itemWidth: 12, itemHeight: 8 },
    tooltip: {
      trigger: 'axis',
      formatter: (params) => {
        const p = points[params[0].dataIndex];
        const score = p.low_sample ? t('cf_TooFew', 'too few responses') : scoreText(scale, p.score);
        return `${esc(period(p))}<br>${esc(metricName(scale))}: <b>${esc(score)}</b><br>${esc(responses)}: ${esc(count(p.responses))}`;
      },
    },
    xAxis: { type: 'category', data: points.map((p) => shortDate(p.period)) },
    yAxis: [
      { type: 'value', min: scale === 'nps11' ? -100 : 0, max: 100, axisLabel: { formatter: scale === 'nps11' ? '{value}' : '{value}%' } },
      { type: 'value', minInterval: 1, splitLine: { show: false } },
    ],
    series: [
      { name: responses, type: 'bar', yAxisIndex: 1, barMaxWidth: 28, data: points.map((p) => p.responses),
        itemStyle: { color: PALETTE.volume, borderRadius: [2, 2, 0, 0] } },
      { name: metricName(scale), type: 'line', connectNulls: true, symbol: 'circle', symbolSize: 7, z: 3,
        lineStyle: { color: PALETTE.primary, width: 2 }, itemStyle: { color: PALETTE.primary },
        // Points from too few responses are hollow grey: shown, but not to be trusted.
        data: points.map((p) => ({ value: p.responses ? p.score : null,
          symbol: p.low_sample ? 'emptyCircle' : 'circle', itemStyle: { color: p.low_sample ? PALETTE.lowSample : PALETTE.primary } })) },
    ],
  }, true);
}

function renderDistribution(o) {
  const scale = o.survey.scale;
  const c = chart('cf-chart-distribution');
  const values = scale === 'nps11' ? [...Array(11).keys()] : [1, 2, 3, 4, 5];
  if (!o.score.responses) {
    showChartMessage(c, t('cf_NoResponses', 'No responses in this period.'));
    return;
  }
  const color = (v) => {
    if (scale === 'nps11') return v <= 6 ? PALETTE.bad : (v <= 8 ? PALETTE.neutral : PALETTE.good);
    return v <= 2 ? PALETTE.bad : (v === 3 ? PALETTE.neutral : PALETTE.good);
  };
  const total = o.score.responses;
  const share = (n) => `${Math.round((100 * n) / total)}%`;
  c.setOption({
    grid: { left: 40, right: 16, top: 24, bottom: 28 },
    tooltip: { trigger: 'axis', formatter: (p) => `${esc(t('cf_Answer', 'Answer'))} ${esc(p[0].name)}: <b>${esc(count(p[0].value))}</b> (${esc(share(p[0].value))})` },
    xAxis: { type: 'category', data: values.map(String) },
    yAxis: { type: 'value', minInterval: 1 },
    series: [{ type: 'bar', barMaxWidth: 48,
      label: { show: true, position: 'top', color: G[6], formatter: (p) => (p.value ? share(p.value) : '') },
      data: values.map((k) => ({ value: o.score.distribution[k] || 0, itemStyle: { color: color(k), borderRadius: [2, 2, 0, 0] } })) }],
  }, true);
}

/** A bar in a table cell, `percent` wide. */
function barCell(percent, color) {
  const bar = el('span', { class: 'cf-bar' });
  bar.style.width = `${Math.max(0, Math.min(100, percent))}%`;
  if (color) bar.style.background = color;
  return el('td', { class: 'cf-bar-cell' }, bar);
}

function loadBreakdown() {
  const table = $id('cf-breakdown-table');
  if (!toggleResults('cf-breakdown-box', 'cf-breakdown-notes', state.versions.length > 0)) return;
  const kind = $id('cf-breakdown').value;
  const group = $id('cf-breakdown').selectedOptions[0].textContent;
  $id('cf-breakdown-title').textContent = sub(t('cf_ScoreBy', 'Score by {group}'), { group });
  get('breakdown', resultsQuery({ breakdown: kind })).then((b) => {
    const head = el('thead', {}, el('tr', {},
      el('th', { text: group }),
      el('th', { class: 'cf-num', text: metricName(b.scale) }),
      el('th', { class: 'cf-num', text: t('cf_Average', 'Average') }),
      el('th', { class: 'cf-num', text: t('cf_ResponsesTitle', 'Responses') }),
      el('th', { class: 'cf-bar-cell' })));
    const body = el('tbody', {}, b.rows.length === 0
      ? el('tr', {}, el('td', { colspan: '5', class: 'cf-empty-cell', text: t('cf_NoResponses', 'No responses in this period.') }))
      : b.rows.map((r) => el('tr', { class: r.low_sample ? 'cf-low-sample' : '', title: r.low_sample ? t('cf_TooFew', 'too few responses') : null },
        el('td', { text: nameOf(kind, r.key) }),
        el('td', { class: 'cf-num', text: r.low_sample ? '—' : scoreText(b.scale, r.score) }),
        el('td', { class: 'cf-num', text: r.low_sample ? '—' : avg(r.average) }),
        el('td', { class: 'cf-num', text: count(r.responses) }),
        r.low_sample ? el('td', { class: 'cf-bar-cell' }) : barCell(scorePosition(b.scale, r.score)))));
    table.replaceChildren(head, body);
    $id('cf-breakdown-notes').replaceChildren(el('p', { class: 'cf-note',
      text: sub(t('cf_LowSampleNote', 'Groups with fewer than {min} responses are greyed out: their score would swing too much to mean anything.'), { min: b.min_sample }) }));
  });
}

/** "Question 2 (NPS)". */
const questionName = (position, scale) => sub(t('cf_QuestionNScale', 'Question {n} ({scale})'),
  { n: position, scale: scale === 'yesno' ? t('cf_MetricYesNo', 'yes/no') : metricName(scale) });

/** Where surveyed callers ended up, as one stacked bar: left at each question, or reached the end. */
function renderProgress(h, worst) {
  const c = chart('cf-chart-progress');
  if (!h.surveyed) {
    showChartMessage(c, t('cf_NoResponses', 'No responses in this period.'));
    return;
  }
  const share = (n) => Math.round((100 * n) / h.surveyed);
  const parts = h.questions.map((q, i) => ({
    name: sub(t('cf_LeftAt', 'Left at question {n}'), { n: q.position }), value: q.left,
    // The question most callers leave at is red; the others alternate greys so neighbours stay apart.
    color: q === worst ? PALETTE.bad : [G[3], G[2]][i % 2],
  })).concat([{ name: t('cf_ReachedEnd', 'Reached the end'), value: h.finished, color: PALETTE.good }]);
  c.setOption({
    grid: { left: 0, right: 0, top: 32, bottom: 0, containLabel: false },
    legend: { top: 0, left: 0, icon: 'roundRect', itemWidth: 12, itemHeight: 8 },
    tooltip: { trigger: 'item', formatter: (p) => `${esc(p.seriesName)}: <b>${esc(count(p.value))}</b> (${esc(share(p.value))}%)` },
    xAxis: { type: 'value', max: h.surveyed, show: false },
    yAxis: { type: 'category', data: [''], show: false },
    series: parts.map((p) => ({
      name: p.name, type: 'bar', stack: 'callers', barWidth: 28, data: [p.value], itemStyle: { color: p.color },
      label: { show: true, position: 'inside', color: p.color === PALETTE.bad || p.color === PALETTE.good ? '#fff' : G[8],
        formatter: (v) => (share(v.value) >= 6 ? `${share(v.value)}%` : '') },
    })),
  }, true);
}

function loadHealth() {
  if (!toggleResults('cf-health-box', 'cf-health-notes', state.versions.length > 0)) return;
  get('health', resultsQuery()).then((h) => {
    // The question with the highest share leaving, of those who reached it.
    const worst = h.questions.reduce((best, q) => (q.left && (!best || q.left_rate > best.left_rate) ? q : best), null);
    fill($id('cf-health-kpis'), el('div', { class: 'cf-kpis' },
      kpi(t('cf_HealthEligible', 'Eligible Calls'), count(h.eligible), t('cf_HealthEligibleSub', 'Answered by an agent on this survey\'s routes')),
      kpi(t('cf_HealthSurveyed', 'Surveyed'), h.eligible ? pct((100 * h.surveyed) / h.eligible) : '—',
        sub(t('cf_HealthSurveyedSub', '{n} of {eligible} eligible calls'), { n: count(h.surveyed), eligible: count(h.eligible) })),
      kpi(t('cf_HealthFinished', 'Reached the End'), h.surveyed ? pct((100 * h.finished) / h.surveyed) : '—',
        sub(t('cf_HealthFinishedSub', '{n} of {surveyed} surveyed callers'), { n: count(h.finished), surveyed: count(h.surveyed) })),
      kpi(t('cf_HealthWorst', 'Most Callers Leave At'), worst ? sub(t('cf_QuestionN', 'Question {n}'), { n: worst.position }) : '—',
        worst ? sub(t('cf_HealthWorstSub', '{rate} of those who reached it'), { rate: pct(worst.left_rate) }) : t('cf_HealthNobodyLeft', 'Nobody left early'))));
    renderProgress(h, worst);

    $id('cf-health-table').replaceChildren(
      el('thead', {}, el('tr', {},
        el('th', { text: t('cf_Question', 'Question') }),
        el('th', { class: 'cf-num', text: t('cf_Reached', 'Reached') }),
        el('th', { class: 'cf-num', text: t('cf_Answered', 'Answered') }),
        el('th', { class: 'cf-num', text: t('cf_LeftHere', 'Left Here') }),
        el('th', { class: 'cf-num', text: t('cf_LeftRate', '% Who Left') }))),
      el('tbody', {}, h.questions.map((q) => el('tr', {},
        el('td', { text: questionName(q.position, q.scale) }),
        el('td', { class: 'cf-num', text: count(q.reached) }),
        el('td', { class: 'cf-num', text: count(q.answered) }),
        el('td', { class: 'cf-num', text: count(q.left) }),
        el('td', { class: `cf-num${q === worst ? ' cf-biggest-drop' : ''}`, text: q.reached ? pct(q.left_rate) : '—' })))));

    // "Too short" is one rule checked twice: by the dialplan's estimate before the survey, and in the call history after it.
    const rows = Object.entries(h.skipped).map(([reason, n]) => ({ reason, n: reason === 'too_short' ? n + h.excluded : n, before: n }))
      .filter((r) => r.n > 0).sort((a, b) => b.n - a.n);
    const notSurveyed = h.eligible - h.surveyed;
    $id('cf-skipped-total').textContent = h.eligible
      ? sub(t('cf_NotSurveyedSub', '{n} of {eligible} eligible calls'), { n: count(notSurveyed), eligible: count(h.eligible) }) : '';
    $id('cf-skipped-table').replaceChildren(
      el('thead', {}, el('tr', {},
        el('th', { text: t('cf_Rule', 'Rule') }),
        el('th', { class: 'cf-num', text: t('cf_Calls', 'Calls') }),
        el('th', { class: 'cf-num', text: t('cf_OfEligible', 'Of Eligible') }))),
      el('tbody', {}, rows.length === 0
        ? el('tr', {}, el('td', { colspan: '3', class: 'cf-empty-cell', text: t('cf_NoneSkipped', 'Every eligible call was surveyed.') }))
        : rows.map((r) => el('tr', {},
          el('td', {}, SKIP_REASONS[r.reason] ? label(SKIP_REASONS[r.reason]) : r.reason,
            r.reason === 'too_short' && h.excluded ? el('div', { class: 'cf-sub-line',
              text: sub(t('cf_TooShortSplit', '{before} before the survey, {after} found in the call history afterwards'), { before: count(r.before), after: count(h.excluded) }) }) : null),
          el('td', { class: 'cf-num', text: count(r.n) }),
          el('td', { class: 'cf-num', text: pct((100 * r.n) / h.eligible) })))));

    fill($id('cf-health-notes'),
      el('p', { class: 'cf-note', text: t('cf_EligibleNote', 'Eligible calls are calls an agent answered on this survey\'s routes. Callers who hang up before the agent and blind-transferred calls are never surveyed and aren\'t counted.') }),
      worst ? el('p', { class: 'cf-note', text: t('cf_HealthHint', 'The question most callers leave at is shown in red. If many leave at one question, its prompt is often too long or unclear.') }) : null,
      h.questions.length > 1 ? el('p', { class: 'cf-note', text: t('cf_AnsweredNote', 'A missed question after the first moves on to the next one, so Answered can be lower than Reached without anyone leaving.') }) : null);
  });
}

function loadVersions() {
  return get('versions').then((data) => {
    state.versions = data.versions;
    state.labels = data.labels;
    const sel = $id('cf-version');
    const current = sel.value || (data.versions.length ? String(data.versions[0].id) : '');
    sel.replaceChildren(...data.versions.map((v) => el('option', { value: String(v.id), selected: String(v.id) === current },
      `${v.name} · ${t('cf_VersionShort', 'v')}${v.version} · ${metricName(v.scale)}${v.status === 'archived' ? ` (${label(STATUSES.archived)})` : ''}`)));
    $(sel).dropdown('refresh');
    $(sel).dropdown('set selected', sel.value);
  });
}

function refreshResults() {
  if (state.tab === 'overview') loadOverview();
  if (state.tab === 'breakdowns') loadBreakdown();
  if (state.tab === 'health') loadHealth();
}

/* ------------------------------------------------------------------ follow-ups */

const FOLLOW_STATUSES = {
  open: ['cf_FollowOpen', 'Open', 'blue'],
  contacted: ['cf_FollowContacted', 'Contacted', 'yellow'],
  resolved: ['cf_FollowResolved', 'Resolved', 'green'],
};

function hours(seconds) {
  if (seconds === null || seconds === undefined) return '—';
  if (seconds < 3600) return `${Math.max(1, Math.round(seconds / 60))} ${t('cf_Minutes', 'min')}`;
  if (seconds < 172800) return `${Math.round(seconds / 3600)} ${t('cf_Hours', 'h')}`;
  return `${Math.round(seconds / 86400)} ${t('cf_Days', 'days')}`;
}

function setOpenCount(n) {
  const badge = $id('cf-open-count');
  badge.textContent = count(n);
  badge.classList.toggle('cf-hidden', !n);
}

/** "Open 12 · Contacted 3 · Resolved 40 · All 55" as one button group. */
function renderFilter(counts) {
  const all = counts.open + counts.contacted + counts.resolved;
  const button = (status, text, n) => el('button', {
    class: `ui button${state.followStatus === status ? ' active' : ''}`, type: 'button', 'aria-pressed': String(state.followStatus === status),
    onclick: () => { state.followStatus = status; loadFollowUps(); },
  }, text, el('span', { class: 'cf-count', text: count(n) }));
  fill($id('cf-followup-filter'),
    Object.keys(FOLLOW_STATUSES).map((k) => button(k, label(FOLLOW_STATUSES[k]), counts[k])),
    button('', t('cf_FollowAll', 'All'), all));
}

function followStatusLabel(status) {
  const s = FOLLOW_STATUSES[status] || [status, status, ''];
  return el('span', { class: `ui small basic ${s[2]} label`, text: label(s) });
}

function loadFollowUps(page = 1) {
  return get('followups', { status: state.followStatus, page }).then((data) => {
    renderFilter(data.counts);
    setOpenCount(data.counts.open);
    $id('cf-followup-summary').textContent = data.avg_close_seconds === null ? ''
      : sub(t('cf_TimeToResolve', 'Average time to resolve: {time}'), { time: hours(data.avg_close_seconds) });
    const box = $id('cf-followups');
    if (data.rows.length === 0) {
      fill(box, el('p', { class: 'cf-muted', text: t('cf_NoFollowUps', 'No follow-ups here. Low scores open one automatically.') }),
        el('p', { class: 'cf-note', text: t('cf_FollowUpsHint', 'A follow-up opens when a call\'s headline score is at or below the threshold under Settings, once the call is confirmed in the call history.') }));
      return;
    }
    const rows = data.rows.map((f) => {
      const others = Object.keys(f.answers).filter((pos) => pos !== '1').sort().map((pos) => `${t('cf_Q', 'Q')}${pos}: ${f.answers[pos]}`).join(' · ');
      const who = [f.agent ? nameOf('agent', f.agent) : '', f.queue ? nameOf('queue', f.queue) : ''].filter(Boolean).join(' · ');
      return el('tr', {},
        el('td', {}, el('div', { class: 'cf-nowrap', text: shortDateTime(f.ended_at) }), el('div', { class: 'cf-sub-line', text: `${f.survey_name} · ${t('cf_VersionShort', 'v')}${f.survey_version}` })),
        el('td', {}, el('span', { class: 'cf-score', title: metricName(f.scale), text: f.answers['1'] ?? '—' }),
          others ? el('div', { class: 'cf-sub-line', text: others }) : null),
        el('td', {}, el('div', { text: f.caller_num || t('cf_Withheld', 'Withheld') }), who ? el('div', { class: 'cf-sub-line', text: who }) : null),
        el('td', {}, followStatusLabel(f.status), f.note ? el('div', { class: 'cf-sub-line', text: f.note.length > 60 ? `${f.note.slice(0, 60)}…` : f.note }) : null),
        el('td', { class: 'cf-actions-cell' },
          f.callback === 'none' ? null : el('button', { class: 'ui basic small button', type: 'button', onclick: () => openFollowUp(f, page, true) },
            el('i', { class: 'phone icon' }), t('cf_CallBack', 'Call Back')),
          el('button', { class: 'ui basic small button', type: 'button', onclick: () => openFollowUp(f, page, false) }, t('cf_Update', 'Update'))));
    });
    const pages = Math.ceil(data.total / data.page_size);
    fill(box,
      el('table', { class: 'ui very basic table cf-table' },
        el('thead', {}, el('tr', {},
          el('th', { text: t('cf_When', 'When') }), el('th', { text: t('cf_Score', 'Score') }),
          el('th', { text: t('cf_Caller', 'Caller') }), el('th', { text: t('cf_Status', 'Status') }), el('th'))),
        el('tbody', {}, rows)),
      pages > 1 ? el('div', { class: 'cf-pager' },
        el('button', { class: 'ui basic small button', type: 'button', disabled: page <= 1, text: t('cf_Previous', 'Previous'), onclick: () => loadFollowUps(page - 1) }),
        el('span', { class: 'cf-muted', text: sub(t('cf_PageOf', 'Page {page} of {pages}'), { page, pages }) }),
        el('button', { class: 'ui basic small button', type: 'button', disabled: page >= pages, text: t('cf_Next', 'Next'), onclick: () => loadFollowUps(page + 1) })) : null,
      el('p', { class: 'cf-note', text: t('cf_FollowUpsHint', 'A follow-up opens when a call\'s headline score is at or below the threshold under Settings, once the call is confirmed in the call history.') }));
  });
}

/** The call-back controls of one case: one click for a listed prefix, otherwise the number typed in. */
function callBackSection(f) {
  if (f.callback === 'none') return el('p', { class: 'cf-muted', text: t('cf_CallBackNoNumberShort', 'No number to call back.') });
  const result = el('div');
  const extensions = extensionOptions();
  const ext = select('cf-cb-ext', extensions, extensions.length ? extensions[0][0] : '');
  const typed = f.callback === 'typed' ? el('input', { type: 'text', id: 'cf-cb-typed', maxlength: '40', autocomplete: 'off' }) : null;
  const go = () => {
    const extLabel = ext.selectedOptions[0] ? ext.selectedOptions[0].textContent : ext.value;
    if (!window.confirm(sub(t('cf_ConfirmCallBack', 'Ring {ext}, then call {number} through the outgoing routes?'), { ext: extLabel, number: f.caller_num }))) return;
    post('callBack', { id: f.id, extension: ext.value, typed: typed ? typed.value : '' })
      .then(() => message(result, 'info', sub(t('cf_CallBackRinging', 'Ringing {ext}. The call goes out when it answers.'), { ext: extLabel })),
        (xhr) => message(result, 'negative', failText(xhr, t('cf_CallBackFailed', 'The call-back could not be placed.'))));
  };
  return el('div', { class: 'ui form' },
    el('div', { class: 'fields' },
      field(t('cf_RingFirst', 'Ring Extension First'), ext, null, typed ? 'six' : 'ten'),
      typed ? field(t('cf_TypeNumber', 'Caller\'s Number (to Confirm)'), typed, null, 'six') : null,
      el('div', { class: 'four wide field cf-form-buttons' }, el('button', { class: 'ui button', type: 'button', disabled: extensions.length === 0, onclick: go },
        el('i', { class: 'phone icon' }), t('cf_CallBack', 'Call Back')))),
    typed ? el('div', { class: 'cf-note', text: t('cf_CallBackTypeHint', 'This number isn\'t on the list of safe prefixes (Settings), so type it in to confirm. Callers can fake their number to make the PBX call an expensive line.') }) : null,
    result);
}

/** The case in a dialog: what happened, a call-back, and its status and note. */
function openFollowUp(f, page, focusCallBack) {
  state.followUp = { f, page };
  const fact = (term, value) => (value ? [el('dt', { text: term }), el('dd', { text: value })] : null);
  const answers = Object.keys(f.answers).sort().map((pos) => (pos === '1'
    ? `${metricName(f.scale)}: ${f.answers[pos]}` : `${t('cf_Q', 'Q')}${pos}: ${f.answers[pos]}`)).join(' · ');
  const statusResult = el('div', { id: 'cf-modal-result' });
  $id('cf-modal-title').textContent = sub(t('cf_FollowUpTitle', 'Follow-Up: {caller}'), { caller: f.caller_num || t('cf_Withheld', 'Withheld') });
  fill($id('cf-modal-content'),
    el('div', { class: 'cf-modal-section' }, el('dl', { class: 'cf-facts' },
      fact(t('cf_When', 'When'), dateTime(f.ended_at)),
      fact(t('cf_Survey', 'Survey'), `${f.survey_name} · ${t('cf_VersionShort', 'v')}${f.survey_version}`),
      fact(t('cf_Answers', 'Answers'), answers || '—'),
      fact(t('cf_B_agent', 'Agent'), f.agent ? nameOf('agent', f.agent) : ''),
      fact(t('cf_B_queue', 'Queue'), f.queue ? nameOf('queue', f.queue) : ''),
      fact(t('cf_CalledBack', 'Called back'), f.callbacks.map((c) => `${shortDateTime(c.requested_at)} (${c.extension}${c.requested_by ? `, ${c.requested_by}` : ''})`).join('; ')),
      fact(t('cf_ResolvedBy', 'Resolved by'), f.closed_by ? `${f.closed_by}, ${shortDateTime(Number(f.closed_at))}` : ''))),
    el('div', { class: 'cf-modal-section' }, el('h4', { class: 'ui header', text: t('cf_CallBackHeader', 'Call Back') }), callBackSection(f)),
    el('div', { class: 'cf-modal-section' }, el('h4', { class: 'ui header', text: t('cf_StatusHeader', 'Status and Note') }),
      el('div', { class: 'ui form' },
        field(t('cf_Status', 'Status'), select('cf-fu-status', Object.keys(FOLLOW_STATUSES).map((k) => [k, label(FOLLOW_STATUSES[k])]), f.status)),
        field(t('cf_Note', 'Note'), el('textarea', { id: 'cf-fu-note', rows: '3', maxlength: '1000', text: f.note || '' }))),
      statusResult));
  $('#cf-followup-modal').modal({ autofocus: false, onHidden: () => { state.followUp = null; } }).modal('show');
  const focus = focusCallBack ? ($id('cf-cb-typed') || $id('cf-cb-ext')) : $id('cf-fu-status');
  if (focus) setTimeout(() => focus.focus(), 350);
}

function saveFollowUp() {
  if (!state.followUp) return;
  const { f, page } = state.followUp;
  post('saveFollowUp', { id: f.id, status: $id('cf-fu-status').value, note: $id('cf-fu-note').value })
    .then(() => { $('#cf-followup-modal').modal('hide'); loadFollowUps(page); },
      (xhr) => message($id('cf-modal-result'), 'negative', saveFailed(xhr)));
}

/* ------------------------------------------------------------------ tabs */

/** Fades the tab bar's right edge while more tabs are scrolled out of view. */
function updateTabsOverflow() {
  const tabs = $id('cf-tabs');
  const overflow = tabs.scrollWidth > tabs.clientWidth + 1;
  tabs.classList.toggle('cf-overflow', overflow);
  tabs.classList.toggle('cf-at-end', overflow && tabs.scrollLeft + tabs.clientWidth >= tabs.scrollWidth - 1);
}

const RESULT_TABS = ['overview', 'breakdowns', 'health'];

function selectTab(tab) {
  state.tab = tab;
  document.querySelectorAll('#call-feedback [data-tab]').forEach((n) => n.classList.toggle('active', n.dataset.tab === tab));
  $id('cf-toolbar').classList.toggle('cf-hidden', !RESULT_TABS.includes(tab) || state.versions.length === 0);
  clearError();
  if (RESULT_TABS.includes(tab)) {
    loadVersions().then(() => {
      $id('cf-toolbar').classList.toggle('cf-hidden', state.versions.length === 0);
      refreshResults();
      Object.values(state.charts).forEach((c) => c.resize());
    });
  }
  if (tab === 'surveys') {
    if (state.editing) openEditor(state.editing.survey.id); else loadSurveys();
  }
  if (tab === 'settings') loadSettings();
  if (tab === 'followups') loadVersions().then(() => loadFollowUps());
}

function init() {
  // placeholder: false keeps "All Directions" a normal choice rather than greyed-out placeholder text.
  $('#call-feedback .cf-toolbar .ui.dropdown, #cf-breakdown').dropdown({ placeholder: false });
  document.querySelectorAll('#cf-tabs .item').forEach((a) => a.addEventListener('click', () => selectTab(a.dataset.tab)));
  $id('cf-tabs').addEventListener('scroll', updateTabsOverflow);
  $id('cf-new-language').replaceChildren(...Object.keys(LANGUAGES).map((l) => el('option', { value: l }, languageLabel(l))));
  $id('cf-new-survey').addEventListener('click', () => { $id('cf-new-form').classList.remove('cf-hidden'); $id('cf-new-name').focus(); });
  $id('cf-new-cancel').addEventListener('click', () => $id('cf-new-form').classList.add('cf-hidden'));
  $id('cf-new-form').addEventListener('submit', createSurvey);
  $id('cf-modal-close').addEventListener('click', () => $('#cf-followup-modal').modal('hide'));
  $id('cf-modal-save').addEventListener('click', saveFollowUp);
  addExportButtons();
  applyPreset();
  $id('cf-preset').addEventListener('change', () => {
    if ($id('cf-preset').value === 'custom') return;
    applyPreset();
    refreshResults();
  });
  ['cf-from', 'cf-to'].forEach((id) => $id(id).addEventListener('change', () => {
    $id('cf-preset').value = 'custom';
    $('#cf-preset').dropdown('set selected', 'custom');
    refreshResults();
  }));
  ['cf-version', 'cf-direction'].forEach((id) => $id(id).addEventListener('change', refreshResults));
  $id('cf-breakdown').addEventListener('change', loadBreakdown);
  window.addEventListener('resize', () => { Object.values(state.charts).forEach((c) => c.resize()); updateTabsOverflow(); });
  updateTabsOverflow();
  get('options').then((options) => {
    state.options = options;
    return loadVersions();
  }).then(() => {
    // The alert email links to ?tab=followups.
    const asked = new URLSearchParams(window.location.search).get('tab');
    const tabs = ['overview', 'breakdowns', 'health', 'followups', 'surveys', 'settings'];
    selectTab(tabs.includes(asked) ? asked : (state.versions.length ? 'overview' : 'surveys'));
    if (asked !== 'followups') get('followups', { status: 'open', page: 1 }).then((d) => setOpenCount(d.counts.open));
  });
}

$(document).ready(init);
