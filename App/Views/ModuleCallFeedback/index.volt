{#
  Call Feedback page shell. No survey, route or caller data is rendered
  here: the script loads it as JSON and inserts it as text only. Only
  translations go through Volt, escaped with |e.
#}
<div id="call-feedback" class="cf-page">

    <div class="ui negative message cf-hidden" id="cf-error"></div>

    <div class="ui form cf-toolbar" id="cf-toolbar">
        <div class="cf-version-wrap">
            <select id="cf-version" class="ui dropdown" aria-label="{{ t._('cf_Survey')|e }}"></select>
        </div>
        <div class="cf-range">
            <select id="cf-preset" class="ui dropdown" aria-label="{{ t._('cf_Period')|e }}">
                <option value="7">{{ t._('cf_PresetLast7')|e }}</option>
                <option value="30" selected>{{ t._('cf_PresetLast30')|e }}</option>
                <option value="90">{{ t._('cf_PresetLast90')|e }}</option>
                <option value="365">{{ t._('cf_PresetLast365')|e }}</option>
                <option value="custom">{{ t._('cf_PresetCustom')|e }}</option>
            </select>
            <input type="date" id="cf-from" aria-label="{{ t._('cf_From')|e }}">
            <span class="cf-range-sep" aria-hidden="true">–</span>
            <input type="date" id="cf-to" aria-label="{{ t._('cf_To')|e }}">
        </div>
        <select id="cf-direction" class="ui dropdown" aria-label="{{ t._('cf_Direction')|e }}">
            <option value="">{{ t._('cf_AllDirections')|e }}</option>
            <option value="in">{{ t._('cf_Inbound')|e }}</option>
            <option value="out">{{ t._('cf_Outbound')|e }}</option>
        </select>
    </div>

    <div class="ui top attached tabular menu" id="cf-tabs">
        <a class="item" data-tab="overview">{{ t._('cf_TabOverview')|e }}</a>
        <a class="item" data-tab="breakdowns">{{ t._('cf_TabBreakdowns')|e }}</a>
        <a class="item" data-tab="health">{{ t._('cf_TabHealth')|e }}</a>
        <a class="item" data-tab="followups">{{ t._('cf_TabFollowUps')|e }}<span class="ui mini label cf-hidden" id="cf-open-count"></span></a>
        <a class="item" data-tab="surveys">{{ t._('cf_TabSurveys')|e }}</a>
        <div class="right menu">
            <a class="item" data-tab="settings"><i class="cog icon"></i>{{ t._('cf_TabSettings')|e }}</a>
        </div>
    </div>

    <div class="ui bottom attached tab segment" data-tab="overview">
        <div id="cf-overview"></div>
        <div id="cf-overview-charts">
            <div class="cf-section-head">
                <h4 class="ui header">{{ t._('cf_Trend')|e }} <span class="cf-subheader" id="cf-trend-interval"></span></h4>
                <div class="cf-section-actions"></div>
            </div>
            <div class="cf-chart" id="cf-chart-trend"></div>
            <div class="cf-section-head">
                <h4 class="ui header">{{ t._('cf_Distribution')|e }}</h4>
                <div class="cf-section-actions"></div>
            </div>
            <div class="cf-chart cf-chart-short" id="cf-chart-distribution"></div>
        </div>
        <div id="cf-overview-notes"></div>
    </div>

    <div class="ui bottom attached tab segment" data-tab="breakdowns">
        <div id="cf-breakdown-box">
            <div class="cf-section-head">
                <h4 class="ui header" id="cf-breakdown-title">{{ t._('cf_ScoreByGroup')|e }}</h4>
                <div class="cf-section-actions">
                    <select id="cf-breakdown" class="ui dropdown" aria-label="{{ t._('cf_BreakDownBy')|e }}">
                        <option value="agent">{{ t._('cf_B_agent')|e }}</option>
                        <option value="queue">{{ t._('cf_B_queue')|e }}</option>
                        <option value="did">{{ t._('cf_B_did')|e }}</option>
                        <option value="direction">{{ t._('cf_B_direction')|e }}</option>
                        <option value="hour">{{ t._('cf_B_hour')|e }}</option>
                        <option value="weekday">{{ t._('cf_B_weekday')|e }}</option>
                        <option value="wait">{{ t._('cf_B_wait')|e }}</option>
                        <option value="talk">{{ t._('cf_B_talk')|e }}</option>
                    </select>
                </div>
            </div>
            <table class="ui very basic table cf-table" id="cf-breakdown-table"></table>
        </div>
        <div id="cf-breakdown-notes"></div>
    </div>

    <div class="ui bottom attached tab segment" data-tab="health">
        <div id="cf-health-box">
            <div id="cf-health-kpis"></div>
            <div class="cf-section-head">
                <h4 class="ui header">{{ t._('cf_FunnelHeader')|e }}</h4>
                <div class="cf-section-actions"></div>
            </div>
            <div class="cf-chart cf-chart-strip" id="cf-chart-progress"></div>
            <table class="ui very basic table cf-table" id="cf-health-table"></table>
            <div class="cf-section-head">
                <h4 class="ui header">{{ t._('cf_NotSurveyedHeader')|e }} <span class="cf-subheader" id="cf-skipped-total"></span></h4>
                <div class="cf-section-actions"></div>
            </div>
            <table class="ui very basic table cf-table cf-narrow" id="cf-skipped-table"></table>
        </div>
        <div id="cf-health-notes"></div>
    </div>

    <div class="ui bottom attached tab segment" data-tab="followups">
        <div class="cf-section-head">
            <div class="ui small basic buttons cf-filter" id="cf-followup-filter" role="group" aria-label="{{ t._('cf_Status')|e }}"></div>
            <span class="cf-muted" id="cf-followup-summary"></span>
        </div>
        <div id="cf-followups"></div>
    </div>

    <div class="ui bottom attached tab segment" data-tab="surveys">
        <div id="cf-survey-list">
            <div class="cf-section-head">
                <h4 class="ui header">{{ t._('cf_Surveys')|e }}</h4>
                <div class="cf-section-actions">
                    <button class="ui primary small button" type="button" id="cf-new-survey"><i class="plus icon"></i>{{ t._('cf_NewSurvey')|e }}</button>
                </div>
            </div>
            <form class="ui form cf-hidden cf-inline-form" id="cf-new-form">
                <div class="fields">
                    <div class="sixteen wide field">
                        <label for="cf-new-template">{{ t._('cf_StartFrom')|e }}</label>
                        <select id="cf-new-template" class="ui dropdown">
                            <option value="csat" selected>{{ t._('cf_TemplateCsat')|e }}</option>
                            <option value="nps">{{ t._('cf_TemplateNps')|e }}</option>
                            <option value="ces">{{ t._('cf_TemplateCes')|e }}</option>
                            <option value="blank">{{ t._('cf_TemplateBlank')|e }}</option>
                        </select>
                    </div>
                </div>
                <div class="fields">
                    <div class="eight wide field">
                        <label for="cf-new-name">{{ t._('cf_Name')|e }}</label>
                        <input type="text" id="cf-new-name" maxlength="60" required placeholder="{{ t._('cf_NewSurveyPlaceholder')|e }}">
                    </div>
                    <div class="four wide field">
                        <label for="cf-new-language">{{ t._('cf_Language')|e }}</label>
                        <select id="cf-new-language"></select>
                    </div>
                    <div class="four wide field cf-form-buttons">
                        <button class="ui primary button" type="submit">{{ t._('cf_Create')|e }}</button>
                        <button class="ui basic button" type="button" id="cf-new-cancel">{{ t._('cf_Cancel')|e }}</button>
                    </div>
                </div>
                <p class="cf-note" id="cf-new-hint"></p>
            </form>
            <table class="ui very basic table cf-table" id="cf-surveys"></table>
        </div>
        <div id="cf-editor" class="cf-hidden"></div>
    </div>

    <div class="ui bottom attached tab segment" data-tab="settings">
        <div id="cf-settings"></div>
    </div>

    <div class="ui mini modal" id="cf-confirm-modal">
        <div class="content"><p id="cf-confirm-text"></p></div>
        <div class="actions">
            <button class="ui basic button" type="button" id="cf-confirm-cancel">{{ t._('cf_Cancel')|e }}</button>
            <button class="ui negative button" type="button" id="cf-confirm-ok"></button>
        </div>
    </div>

    <div class="ui small modal" id="cf-followup-modal">
        <div class="header" id="cf-modal-title"></div>
        <div class="content" id="cf-modal-content"></div>
        <div class="actions">
            <button class="ui basic button" type="button" id="cf-modal-close">{{ t._('cf_Close')|e }}</button>
            <button class="ui primary button" type="button" id="cf-modal-save">{{ t._('cf_SaveStatus')|e }}</button>
        </div>
    </div>
</div>
