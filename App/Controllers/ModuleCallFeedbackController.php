<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\App\Controllers;

use InvalidArgumentException;
use MikoPBX\AdminCabinet\Controllers\BaseController;
use MikoPBX\AdminCabinet\Providers\AssetProvider;
use MikoPBX\Common\Providers\JwtProvider;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\Directories;
use MikoPBX\Core\Workers\Libs\WorkerModelsEvents\Actions\ReloadDialplanAction;
use MikoPBX\Core\Workers\WorkerModelsEvents;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleCallFeedback\Lib\AccessPolicy;
use Modules\ModuleCallFeedback\Lib\BuiltInPrompts;
use Modules\ModuleCallFeedback\Lib\CallBacks;
use Modules\ModuleCallFeedback\Lib\CallFeedbackConf;
use Modules\ModuleCallFeedback\Lib\CdrReader;
use Modules\ModuleCallFeedback\Lib\ConfigOptions;
use Modules\ModuleCallFeedback\Lib\DialplanBuilder;
use Modules\ModuleCallFeedback\Lib\FollowUps;
use Modules\ModuleCallFeedback\Lib\ModuleSettings;
use Modules\ModuleCallFeedback\Lib\Privacy;
use Modules\ModuleCallFeedback\Lib\Results;
use Modules\ModuleCallFeedback\Lib\ScalePresets;
use Modules\ModuleCallFeedback\Lib\SurveyEditor;
use Modules\ModuleCallFeedback\Lib\SurveyRepository;
use Modules\ModuleCallFeedback\Lib\SurveyTemplates;
use PDO;
use Phalcon\Mvc\Dispatcher;
use Throwable;

/**
 * Admin-cabinet page and JSON actions for Call Feedback.
 *
 * Every request is checked for the admins role here, on top of the core's
 * SecurityPlugin: another module can grant other roles access to any
 * controller. The core's beforeExecuteRoute() returns void, so a refused
 * request is forwarded to deniedAction() rather than stopped.
 *
 * Read actions are AJAX GETs over read-only connections. Write actions go
 * through write(): AJAX POST with a Bearer token, so the login cookie alone,
 * as a cross-site form would send it, is refused. All validation is in the
 * Lib classes. Admin-entered names and caller numbers are returned as data;
 * the page renders them as text only.
 */
class ModuleCallFeedbackController extends BaseController
{
    private const string DENIED_ACTION = 'denied';

    public function beforeExecuteRoute(Dispatcher $dispatcher): void
    {
        parent::beforeExecuteRoute($dispatcher);

        if ($dispatcher->getActionName() === self::DENIED_ACTION) {
            return;
        }
        if (!AccessPolicy::isAllowedRole($this->currentRole())) {
            $dispatcher->forward(['action' => self::DENIED_ACTION]);
        }
    }

    /** The module page. No data goes through Volt; the page loads it as JSON. */
    public function indexAction(): void
    {
        $this->assets->collection(AssetProvider::SEMANTIC_UI_CSS)
            ->addCss('css/vendor/semantic/modal.min.css', true);
        $this->assets->collection(AssetProvider::SEMANTIC_UI_JS)
            ->addJs('js/vendor/semantic/modal.min.js', true);
        $this->assets->collection(AssetProvider::HEADER_CSS)
            ->addCss('css/cache/' . CallFeedbackConf::MODULE_ID . '/call-feedback.css', true);
        $this->assets->collection(AssetProvider::FOOTER_JS)
            ->addJs('js/cache/' . CallFeedbackConf::MODULE_ID . '/vendor/echarts.min.js', true)
            ->addJs('js/cache/' . CallFeedbackConf::MODULE_ID . '/call-feedback.js', true);
    }

    /** GET versions: survey versions with results to show, and the display names they need. */
    public function versionsAction(): void
    {
        $this->json(fn (): array => [
            'versions' => $this->results()->versions(),
            'labels'   => ConfigOptions::labels($this->configDb()),
        ]);
    }

    /** GET overview: survey, from, to, direction. */
    public function overviewAction(): void
    {
        $this->json(fn (): array => $this->results()->overview($this->resultsQuery()));
    }

    /** GET health: survey, from, to, direction. The survey's funnel and the calls not asked. */
    public function healthAction(): void
    {
        $this->json(fn (): array => $this->results()->health($this->resultsQuery()));
    }

    /** GET breakdown: survey, from, to, direction, breakdown. */
    public function breakdownAction(): void
    {
        $this->json(fn (): array => $this->results()->breakdown($this->resultsQuery() + [
            'breakdown' => (string)$this->request->getQuery('breakdown'),
        ]));
    }

    /** GET followups: status ('' = all), page. Newest first, with the call and its answers. */
    public function followupsAction(): void
    {
        $this->json(function (): array {
            $status = $this->request->getQuery('status');
            $page = $this->request->getQuery('page');

            $db = $this->moduleDb();
            $list = (new FollowUps($db))->list(
                is_string($status) ? $status : '',
                is_string($page) && preg_match('/^[1-9][0-9]{0,4}$/', $page) ? (int)$page : 1,
            );
            // How each case may be called back, and the call-backs already placed.
            $prefixes = ModuleSettings::load($db)['callback_prefixes'];
            $history = (new CallBacks($db))->ofFollowUps(array_column($list['rows'], 'id'));
            foreach ($list['rows'] as &$row) {
                $row['callback'] = CallBacks::mode($row['caller_num'], $prefixes);
                $row['callbacks'] = $history[$row['id']] ?? [];
            }
            unset($row);

            return $list;
        });
    }

    /** POST saveFollowUp: id, status, note. */
    public function saveFollowUpAction(): void
    {
        $this->write(function (): array {
            (new FollowUps($this->writableModuleDb()))->update(
                $this->idParam($this->request->getPost('id')),
                (string)$this->request->getPost('status'),
                (string)$this->request->getPost('note'),
                $this->currentLogin(),
                time(),
            );
            return [];
        });
    }

    /** GET options: what the survey editor can choose from. */
    public function optionsAction(): void
    {
        $this->json(function (): array {
            $config = $this->configDb();

            return [
                'routes'     => ConfigOptions::routes($config, new SurveyEditor($this->moduleDb(), $config)),
                'soundFiles' => ConfigOptions::soundFiles($config),
                'builtInPrompts' => BuiltInPrompts::catalogue(Directories::getDir(Directories::AST_SOUNDS_DIR)),
                'extensions' => ConfigOptions::extensions($config),
                'scales'     => ScalePresets::all(),
                'languages'  => DialplanBuilder::LANGUAGES,
            ];
        });
    }

    /** GET surveys: every version, with targets and the last 7 days' counts. */
    public function surveysAction(): void
    {
        $this->json(function (): array {
            $rows = (new SurveyRepository($this->moduleDb()))->listing(time() - 7 * 86400);
            // How many things stop each draft going live, so the list can say which are ready.
            $editor = new SurveyEditor($this->moduleDb(), $this->configDb());
            foreach ($rows as &$row) {
                $row['problems'] = $row['status'] === SurveyRepository::DRAFT
                    ? count($editor->activationProblems($row['id'], $this->promptUsable())) : 0;
            }
            unset($row);

            return $rows;
        });
    }

    /** GET survey: id. One version, its family's targets, test calls and what blocks activation. */
    public function surveyAction(): void
    {
        $this->json(function (): array {
            $id = $this->idParam($this->request->getQuery('id'));
            $repository = new SurveyRepository($this->moduleDb());
            $survey = $repository->find($id) ?? throw new InvalidArgumentException('Unknown survey.');

            return [
                'survey'    => $survey,
                'targets'   => $repository->targetsOfFamily($survey['family_id']),
                'family'    => $repository->family($survey['family_id']),
                'taken'     => $repository->routesTakenByOthers($survey['family_id']),
                'testCalls' => $repository->testCalls($id),
                'problems'  => $survey['status'] === SurveyRepository::DRAFT
                    ? (new SurveyEditor($this->moduleDb(), $this->configDb()))->activationProblems($id, $this->promptUsable())
                    : [],
            ];
        });
    }

    /** POST createSurvey: name, language, template ('' for a blank survey, or a SurveyTemplates key). */
    public function createSurveyAction(): void
    {
        $this->write(function (SurveyEditor $editor): array {
            $name = (string)$this->request->getPost('name');
            $language = (string)$this->request->getPost('language');
            $template = (string)$this->request->getPost('template');

            return ['id' => $template === ''
                ? $editor->create($name, $language, time())
                : SurveyTemplates::create($editor, $template, $name, $language, time())];
        }, reload: true);
    }

    /** POST saveSurvey: id, fields (JSON object of SurveyEditor::update() fields). */
    public function saveSurveyAction(): void
    {
        $this->write(function (SurveyEditor $editor): array {
            $editor->update($this->idParam($this->request->getPost('id')), $this->jsonPost('fields'));
            return [];
        }, reload: true);
    }

    /** POST saveTargets: id, targets (JSON list of {direction, route_id}). */
    public function saveTargetsAction(): void
    {
        $this->write(function (SurveyEditor $editor): array {
            $editor->setTargets($this->idParam($this->request->getPost('id')), $this->jsonPost('targets'));
            return [];
        }, reload: true);
    }

    /** POST activateSurvey: id. */
    public function activateSurveyAction(): void
    {
        $this->write(function (SurveyEditor $editor): array {
            $editor->activate($this->idParam($this->request->getPost('id')), $this->promptUsable(), time());
            return [];
        }, reload: true);
    }

    /** POST archiveSurvey: id. */
    public function archiveSurveyAction(): void
    {
        $this->write(function (SurveyEditor $editor): array {
            $editor->archive($this->idParam($this->request->getPost('id')), time());
            return [];
        }, reload: true);
    }

    /** POST deleteSurvey: id of a draft. Returns the family's newest remaining version, or null. */
    public function deleteSurveyAction(): void
    {
        $this->write(fn (SurveyEditor $editor): array => ['id' => $editor->deleteDraft($this->idParam($this->request->getPost('id')))]);
    }

    /** POST newVersion: id. Returns the draft's ID. */
    public function newVersionAction(): void
    {
        $this->write(fn (SurveyEditor $editor): array => ['id' => $editor->newVersion(
            $this->idParam($this->request->getPost('id')), time()
        )], reload: true);
    }

    /** POST duplicateSurvey: id. Returns the copy's ID. */
    public function duplicateSurveyAction(): void
    {
        $this->write(fn (SurveyEditor $editor): array => ['id' => $editor->duplicate(
            $this->idParam($this->request->getPost('id')), time()
        )], reload: true);
    }

    /**
     * POST testCall: id, extension. Rings the extension and, when it answers,
     * plays this version (draft or active) without the rules. The session is
     * stored as a test call, kept apart from results; one is needed before a
     * version can be activated.
     */
    public function testCallAction(): void
    {
        $this->write(function (): array {
            $id = $this->idParam($this->request->getPost('id'));
            $survey = (new SurveyRepository($this->moduleDb()))->find($id) ?? throw new InvalidArgumentException('Unknown survey.');
            if ($survey['status'] === SurveyRepository::ARCHIVED) {
                throw new InvalidArgumentException('An archived survey can no longer be called.');
            }
            $extension = (string)$this->request->getPost('extension');
            $known = array_column(ConfigOptions::extensions($this->configDb()), 'number');
            if (!in_array($extension, $known, true)) {
                throw new InvalidArgumentException('Unknown extension.');
            }
            // The web process has no AMI connection; the module's worker places the call.
            (new BeanstalkClient(CallFeedbackConf::COMMANDS_TUBE))->publish(json_encode([
                'command' => CallFeedbackConf::COMMAND_TEST_CALL, 'survey' => (string)$id, 'extension' => $extension,
            ], JSON_THROW_ON_ERROR));

            return ['ringing' => $extension];
        });
    }

    /** GET settings: module settings and the exclusion list. */
    public function settingsAction(): void
    {
        $this->json(function (): array {
            $db = $this->moduleDb();

            return [
                'settings'      => ModuleSettings::load($db),
                'exclusions'    => (new SurveyRepository($db))->exclusionList(),
                'mailEnabled'   => $this->mailEnabled(),
                // Days the PBX keeps its call history; callers' numbers follow it. Under 30: no limit.
                'retentionDays' => (int)$this->pbxSetting('PBXRecordSavePeriod', CallFeedbackConf::DEFAULT_RETENTION_DAYS),
                'retentionUnlimitedBelow' => Privacy::UNLIMITED_BELOW_DAYS,
            ];
        });
    }

    /** POST saveSettings: settings (JSON object). */
    public function saveSettingsAction(): void
    {
        $this->write(fn (): array => ModuleSettings::save($this->writableModuleDb(), $this->jsonPost('settings')), reload: true);
    }

    /** POST addExclusions: numbers (text, one per line), note. */
    public function addExclusionsAction(): void
    {
        $this->write(fn (SurveyEditor $editor): array => ['added' => $editor->addExclusions(
            (string)$this->request->getPost('numbers'), (string)$this->request->getPost('note'), time()
        )], reload: true);
    }

    /** POST removeExclusion: id. */
    public function removeExclusionAction(): void
    {
        $this->write(function (SurveyEditor $editor): array {
            $editor->removeExclusion($this->idParam($this->request->getPost('id')));
            return [];
        }, reload: true);
    }

    /**
     * POST callBack: id (follow-up), extension, typed (the number, when it must
     * be typed in). Rings the extension, then calls the customer through the
     * outgoing routes. Checked and logged here; placed by the worker.
     */
    public function callBackAction(): void
    {
        $this->write(function (): array {
            $db = $this->writableModuleDb();
            $id = (new CallBacks($db))->request(
                $this->idParam($this->request->getPost('id')),
                (string)$this->request->getPost('extension'),
                (string)$this->request->getPost('typed'),
                ModuleSettings::load($db)['callback_prefixes'],
                array_column(ConfigOptions::extensions($this->configDb()), 'number'),
                $this->currentLogin(),
                time(),
            );
            (new BeanstalkClient(CallFeedbackConf::COMMANDS_TUBE))->publish(json_encode([
                'command' => CallFeedbackConf::COMMAND_CALL_BACK, 'callback' => (string)$id,
            ], JSON_THROW_ON_ERROR));

            return ['callback' => $id];
        });
    }

    /**
     * POST forgetNumber: number. Deletes every session, answer and follow-up of
     * that number, and its frequency-cap key (through the worker, which has
     * AMI). The log records the count and who asked, never the number.
     */
    public function forgetNumberAction(): void
    {
        $this->write(function (): array {
            $result = (new Privacy($this->writableModuleDb()))->forgetNumber((string)$this->request->getPost('number'));
            (new BeanstalkClient(CallFeedbackConf::COMMANDS_TUBE))->publish(json_encode([
                'command' => CallFeedbackConf::COMMAND_FORGET_NUMBER, 'digits' => $result['digits'],
            ], JSON_THROW_ON_ERROR));
            SystemMessages::sysLogMsg(self::class, sprintf('Deleted all data for a number on request of %s: %d session(s)',
                $this->currentLogin() ?: 'an admin', $result['sessions']), LOG_NOTICE);

            return ['sessions' => $result['sessions']];
        });
    }

    /** 403 for anyone who is not an admin: JSON for AJAX, a page otherwise. */
    public function deniedAction(): void
    {
        $this->response->setStatusCode(403, 'Forbidden');
        if ($this->request->isAjax()) {
            $this->view->setVar('raw_response', json_encode([
                'success' => false,
                'reload'  => false,
                'message' => ['error' => $this->translation->_('cf_AccessDenied')],
            ]));
        }
    }

    /**
     * Runs $build and sends its result as JSON. Bad requests get 400 with a
     * short reason; anything else gets a generic 500 and a log entry.
     */
    private function json(callable $build): void
    {
        $status = 200;
        try {
            if (!$this->request->isGet() || !$this->request->isAjax()) {
                throw new InvalidArgumentException('Use an AJAX GET request.');
            }
            $body = ['success' => true, 'data' => $build()];
        } catch (InvalidArgumentException $e) {
            $status = 400;
            $body = ['success' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            $status = 500;
            $body = ['success' => false, 'message' => 'Call Feedback could not load this data.'];
            SystemMessages::sysLogMsg(self::class, 'Request failed: ' . $e->getMessage(), LOG_ERR);
        }
        $this->response->setStatusCode($status);
        $this->view->setVar('raw_response', json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * Runs $change for a write action, after the write rules: AJAX POST with a
     * Bearer token. With $reload, the dialplan is regenerated afterwards.
     * Same responses as json(); a refused request gets 403.
     *
     * @param callable(SurveyEditor): array<string, mixed> $change
     */
    private function write(callable $change, bool $reload = false): void
    {
        $auth = $this->request->getHeader('Authorization');
        if (!AccessPolicy::isAllowedWrite($this->request->isPost(), $this->request->isAjax(), $auth === '' ? null : $auth)) {
            $this->response->setStatusCode(403, 'Forbidden');
            $this->view->setVar('raw_response', json_encode(['success' => false, 'message' => 'Write requests need an AJAX POST with a Bearer token.']));
            return;
        }
        $status = 200;
        try {
            $body = ['success' => true, 'data' => $change(new SurveyEditor($this->writableModuleDb(), $this->configDb()))];
            if ($reload) {
                WorkerModelsEvents::invokeAction(ReloadDialplanAction::class);
            }
        } catch (InvalidArgumentException $e) {
            $status = 400;
            $body = ['success' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            $status = 500;
            $body = ['success' => false, 'message' => 'Call Feedback could not save this change.'];
            SystemMessages::sysLogMsg(self::class, 'Write failed: ' . $e->getMessage(), LOG_ERR);
        }
        $this->response->setStatusCode($status);
        $this->view->setVar('raw_response', json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function results(): Results
    {
        $db = $this->moduleDb();

        return new Results($db, (int)ModuleSettings::load($db)['min_sample']);
    }

    /** The results filters as plain strings; Results validates every one. */
    private function resultsQuery(): array
    {
        $q = [];
        foreach (['survey', 'from', 'to', 'direction'] as $name) {
            $value = $this->request->getQuery($name);
            $q[$name] = is_string($value) ? $value : '';
        }

        return $q;
    }

    private function idParam(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,9}$/', $value)) {
            throw new InvalidArgumentException('Invalid id.');
        }

        return (int)$value;
    }

    /** A JSON-encoded POST field, decoded; nested values arrive intact this way. */
    private function jsonPost(string $name): array
    {
        $raw = $this->request->getPost($name);
        if (!is_string($raw) || strlen($raw) > 65536) {
            throw new InvalidArgumentException("$name is missing or too large.");
        }
        try {
            $value = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException("$name is not valid JSON.");
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException("$name must be an object or a list.");
        }

        return $value;
    }

    /** @return callable(int, string): bool Whether a prompt ID can be played in a survey language. */
    private function promptUsable(): callable
    {
        $moduleDir = PbxExtensionUtils::getModuleDir(CallFeedbackConf::MODULE_ID);

        return fn (int $id, string $language = DialplanBuilder::LANGUAGES[0]): bool => BuiltInPrompts::isBuiltIn($id)
            ? BuiltInPrompts::available($moduleDir, $id, $language)
            : DialplanBuilder::isUsablePath(CallFeedbackConf::promptPath($id));
    }

    private function mailEnabled(): bool
    {
        return $this->pbxSetting('MailEnableNotifications', '') === '1';
    }

    /** A PBX setting from m_PbxSettings, or $default when it is not set. */
    private function pbxSetting(string $key, string $default): string
    {
        $stmt = $this->configDb()->prepare('SELECT value FROM m_PbxSettings WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null || $value === '' ? $default : (string)$value;
    }

    private function moduleFile(): string
    {
        return PbxExtensionUtils::getModuleDir(CallFeedbackConf::MODULE_ID) . '/db/module.db';
    }

    private function moduleDb(): PDO
    {
        return CdrReader::openReadOnly($this->moduleFile());
    }

    /** A read-write connection for write actions. The worker sets the journal mode; this leaves it alone. */
    private function writableModuleDb(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->moduleFile(), null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }

    private function configDb(): PDO
    {
        return CdrReader::openReadOnly((string)$this->di->getShared('config')->path('database.dbfile'));
    }

    /** The admin's login from the request's Bearer token (writes always carry one). */
    private function currentLogin(): string
    {
        $header = (string)$this->request->getHeader('Authorization');
        if (!str_starts_with($header, 'Bearer ')) {
            return '';
        }
        $payload = $this->di->getShared(JwtProvider::SERVICE_NAME)->validate(substr($header, 7));

        return is_array($payload) && is_string($payload['userId'] ?? null) ? $payload['userId'] : '';
    }

    /**
     * The caller's role, resolved the same way as the core's SecurityPlugin:
     * the Bearer token first, then the refresh-token cookie of a page load.
     */
    private function currentRole(): ?string
    {
        $jwt = $this->di->getShared(JwtProvider::SERVICE_NAME);

        $role = $jwt->extractRoleFromHeader($this->request->getHeader('Authorization'));
        if ($role !== null) {
            return $role;
        }

        if ($this->cookies->has('refreshToken')) {
            try {
                $refreshToken = $this->cookies->get('refreshToken')->getValue();
                if (!empty($refreshToken)) {
                    return $jwt->extractRoleFromRefreshToken($refreshToken);
                }
            } catch (Throwable) {
                // An unreadable cookie counts as no role: refused.
            }
        }

        return null;
    }
}
