{#
  Call Feedback page shell. No survey, route or caller data is rendered
  here: the script loads it as JSON and inserts it as text only. Only
  translations go through Volt, escaped with |e.
#}
<div id="call-feedback" class="cf-page">

    <div class="ui negative message cf-hidden" id="cf-error"></div>

    <div class="ui form cf-toolbar" id="cf-toolbar">
        <select id="cf-version" aria-label="{{ t._('cf_Survey')|e }}"></select>
        <select id="cf-preset" aria-label="{{ t._('cf_Period')|e }}">
            <option value="7">{{ t._('cf_PresetLast7')|e }}</option>
            <option value="30" selected>{{ t._('cf_PresetLast30')|e }}</option>
            <option value="90">{{ t._('cf_PresetLast90')|e }}</option>
            <option value="365">{{ t._('cf_PresetLast365')|e }}</option>
            <option value="custom">{{ t._('cf_PresetCustom')|e }}</option>
        </select>
        <input type="date" id="cf-from" aria-label="{{ t._('cf_From')|e }}">
        <input type="date" id="cf-to" aria-label="{{ t._('cf_To')|e }}">
        <select id="cf-direction" aria-label="{{ t._('cf_Direction')|e }}">
            <option value="">{{ t._('cf_AllDirections')|e }}</option>
            <option value="in">{{ t._('cf_Inbound')|e }}</option>
            <option value="out">{{ t._('cf_Outbound')|e }}</option>
        </select>
    </div>

    <div class="ui top attached tabular menu" id="cf-tabs">
        <a class="item" data-tab="overview">{{ t._('cf_TabOverview')|e }}</a>
        <a class="item" data-tab="breakdowns">{{ t._('cf_TabBreakdowns')|e }}</a>
        <a class="item" data-tab="health">{{ t._('cf_TabHealth')|e }}</a>
        <a class="item" data-tab="followups">{{ t._('cf_TabFollowUps')|e }}</a>
        <a class="item" data-tab="surveys">{{ t._('cf_TabSurveys')|e }}</a>
        <div class="right menu">
            <a class="item" data-tab="settings"><i class="cog icon"></i>{{ t._('cf_TabSettings')|e }}</a>
        </div>
    </div>

    <div class="ui bottom attached tab segment" data-tab="overview">
        <div id="cf-overview"></div>
        <div class="cf-chart-head"><h4 class="ui header">{{ t._('cf_Trend')|e }}</h4></div>
        <div class="cf-chart" id="cf-chart-trend"></div>
        <div class="cf-chart-head"><h4 class="ui header">{{ t._('cf_Distribution')|e }}</h4></div>
        <div class="cf-chart cf-chart-short" id="cf-chart-distribution"></div>
        <div id="cf-overview-notes"></div>
    </div>

    <div class="ui bottom attached tab segment" data-tab="breakdowns">
        <div class="ui form cf-inline-form">
            <div class="inline field">
                <label for="cf-breakdown">{{ t._('cf_BreakDownBy')|e }}</label>
                <select id="cf-breakdown">
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
        <div id="cf-breakdown-notes"></div>
    </div>

    <div class="ui bottom attached tab segment" data-tab="health">
        <table class="ui very basic table cf-table" id="cf-health-table"></table>
        <div id="cf-health-notes"></div>
    </div>

    <div class="ui bottom attached tab segment" data-tab="followups">
        <div class="cf-section-head">
            <div class="ui form"><select id="cf-followup-status">
                <option value="open">{{ t._('cf_FollowOpen')|e }}</option>
                <option value="contacted">{{ t._('cf_FollowContacted')|e }}</option>
                <option value="resolved">{{ t._('cf_FollowResolved')|e }}</option>
                <option value="">{{ t._('cf_FollowAll')|e }}</option>
            </select></div>
            <span class="cf-muted" id="cf-followup-summary"></span>
        </div>
        <div id="cf-followups"></div>
    </div>

    <div class="ui bottom attached tab segment" data-tab="surveys">
        <div id="cf-survey-list">
            <div class="cf-section-head">
                <h4 class="ui header">{{ t._('cf_Surveys')|e }}</h4>
                <button class="ui primary small button" type="button" id="cf-new-survey"><i class="plus icon"></i>{{ t._('cf_NewSurvey')|e }}</button>
            </div>
            <form class="ui form cf-hidden cf-inline-form" id="cf-new-form">
                <div class="fields">
                    <div class="eight wide field">
                        <label for="cf-new-name">{{ t._('cf_Name')|e }}</label>
                        <input type="text" id="cf-new-name" maxlength="60" required>
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
            </form>
            <table class="ui very basic table cf-table" id="cf-surveys"></table>
        </div>
        <div id="cf-editor" class="cf-hidden"></div>
    </div>

    <div class="ui bottom attached tab segment" data-tab="settings">
        <div id="cf-settings"></div>
    </div>
</div>
