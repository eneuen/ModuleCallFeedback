<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Tests\Unit;

use Modules\ModuleCallFeedback\Lib\ConfigOptions;
use Modules\ModuleCallFeedback\Lib\FeedbackStore;
use Modules\ModuleCallFeedback\Lib\SurveyEditor;
use Modules\ModuleCallFeedback\Lib\SurveyRepository;
use Modules\ModuleCallFeedback\Tests\Unit\Support\Surveys;
use Modules\ModuleCallFeedback\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\TestCase;

final class ConfigOptionsTest extends TestCase
{
    private TestDatabases $databases;
    private PDO $module;
    private PDO $config;

    protected function setUp(): void
    {
        $this->databases = new TestDatabases();
        $this->module = TestDatabases::writable($this->databases->moduleDatabase());
        $this->config = TestDatabases::writable($this->databases->configDatabase());
    }

    public function testRoutesCarryReadableNamesAndLeaveOutTheDefault(): void
    {
        $this->config->exec("INSERT INTO m_IncomingRoutingTable (id, rulename, number, provider, priority) VALUES (43, 'Pattern', '_555XXXX', '', 1005)");
        $routes = ConfigOptions::routes($this->config, new SurveyEditor($this->module, $this->config));

        $in = array_column($routes['in'], null, 'id');
        self::assertArrayNotHasKey(1, $in, 'The default action is not a route.');
        self::assertSame(['CallSim 5551001', '5551001', 'CallSim carrier', true], array_values(array_intersect_key($in[40], array_flip(['name', 'number', 'provider', 'usable']))));
        self::assertFalse($in[43]['usable'], 'A number pattern can\'t be matched.');
        self::assertSame('CallSim carrier', $routes['out'][0]['provider']);
    }

    public function testOnlyCustomSoundFilesAndPhoneExtensions(): void
    {
        self::assertSame([['id' => 11, 'name' => 'Survey intro']], ConfigOptions::soundFiles($this->config));
        self::assertSame([['number' => '301', 'name' => 'Anna'], ['number' => '302', 'name' => '']], ConfigOptions::extensions($this->config));
    }

    public function testTheSurveyListCountsRecentSessionsWithoutTestCalls(): void
    {
        $editor = new SurveyEditor($this->module, $this->config);
        $id = Surveys::complete($editor, $this->module, [['direction' => 'in', 'route_id' => 40]]);
        $insert = $this->module->prepare('INSERT INTO ' . FeedbackStore::SESSIONS . " (linkedid, survey_id, direction, status, ended_at, excluded, is_test)
            VALUES (?, ?, 'in', ?, ?, 0, 0)");
        $insert->execute(['a', $id, 'completed', Surveys::NOW]);
        $insert->execute(['b', $id, 'offered', Surveys::NOW]);
        $insert->execute(['old', $id, 'completed', Surveys::NOW - 30 * 86400]);

        $row = (new SurveyRepository($this->module))->listing(Surveys::NOW - 7 * 86400)[0];

        self::assertSame([2, 1, 1], [$row['offered'], $row['responded'], $row['test_calls']]);
        self::assertSame([['direction' => 'in', 'route_id' => 40]], $row['targets']);
    }
}
