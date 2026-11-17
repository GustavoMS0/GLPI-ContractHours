<?php

/**
 * ------------------------------------------------------------------------
 * Contract Hours - Suíte de testes automatizados (PHP 7.4+)
 *
 * Executa todas as asserções simulando GLPI 10 (10.0.18), GLPI 11 (11.0.0)
 * e GLPI 12 (12.0.0) com tipagem condicional de CommonGLPI::$rightname.
 *
 * Copyright (C) 2026 by G. Martins
 * ------------------------------------------------------------------------
 */

require_once __DIR__ . '/bootstrap.php';

function assertSameValue($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $label = $message !== '' ? $message . ': ' : '';
        throw new RuntimeException(sprintf(
            '%sExpected %s, got %s',
            $label,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertTrueValue(bool $condition, string $message = ''): void
{
    if (!$condition) {
        throw new RuntimeException($message !== '' ? $message : 'Expected true, got false');
    }
}

function assertFalseValue(bool $condition, string $message = ''): void
{
    if ($condition) {
        throw new RuntimeException($message !== '' ? $message : 'Expected false, got true');
    }
}

function assertStringContains(string $needle, string $haystack, string $message = ''): void
{
    if (strpos($haystack, $needle) === false) {
        $label = $message !== '' ? $message . ': ' : '';
        throw new RuntimeException(sprintf('%sExpected string to contain %s', $label, var_export($needle, true)));
    }
}

function assertStringNotContains(string $needle, string $haystack, string $message = ''): void
{
    if (strpos($haystack, $needle) !== false) {
        $label = $message !== '' ? $message . ': ' : '';
        throw new RuntimeException(sprintf('%sExpected string NOT to contain %s', $label, var_export($needle, true)));
    }
}

function resetFixture(): void
{
    global $DB, $PLUGIN_HOOKS, $CFG_GLPI;

    $GLOBALS['TEST_CLI_MODE']       = false;
    $PLUGIN_HOOKS                   = [];
    $CFG_GLPI                       = ['root_doc' => '', 'url_base' => 'https://glpi.local'];
    Config::$store                  = [];
    Plugin::$registered             = [];
    Plugin::$webDirCalled           = false;
    Html::$backed                   = false;
    CommonDBTM::$canViewItemResult  = true;
    CommonDBTM::$canUpdateItemResult = true;

    if (property_exists('Html', 'rightErrorDisplayed')) {
        Html::$rightErrorDisplayed = false;
    }

    Session::reset();
    $DB->reset();

    plugin_init_contracthours();
    plugin_contracthours_install();
}

function seedContractWithPool(
    int $contracts_id,
    int $entities_id,
    string $name,
    int $contracted_minutes,
    array $poolOverrides = []
): void {
    global $DB;

    $DB->seed('glpi_contracts', [[
        'id'          => $contracts_id,
        'entities_id' => $entities_id,
        'name'        => $name,
        'num'         => 'CTR-' . $contracts_id,
        'is_deleted'  => 0,
    ]]);

    PluginContracthoursPool::savePool($contracts_id, array_merge([
        'entities_id'         => $entities_id,
        'contracted_minutes'  => $contracted_minutes,
        'alert_threshold_pct' => 80,
        'block_on_overrun'    => 0,
        'allow_rollover'      => 0,
        'hourly_rate_cents'   => 15000,
        'billing_cycle'       => 'total',
        'is_active'           => 1,
    ], $poolOverrides));
}

function makeTask(int $id, int $tickets_id, int $actiontime, int $entities_id = 0, int $users_id = 2): TicketTask
{
    $task         = new TicketTask();
    $task->fields = [
        'id'          => $id,
        'tickets_id'  => $tickets_id,
        'actiontime'  => $actiontime,
        'entities_id' => $entities_id,
        'users_id'    => $users_id,
    ];
    $task->input  = $task->fields;
    return $task;
}

function test_plugin_manifest_and_hooks(): void
{
    global $PLUGIN_HOOKS;

    resetFixture();

    $info = plugin_version_contracthours();
    assertSameValue('Contract Hours', $info['name'], 'Plugin name');
    assertSameValue('1.0.0', $info['version'], 'Version mismatch');
    assertSameValue('G. Martins', $info['author'], 'Author mismatch');
    assertSameValue('GPLv3+', $info['license'], 'License mismatch');
    assertSameValue('10.0.0', $info['requirements']['glpi']['min'], 'Min GLPI');
    assertSameValue('12.0.99', $info['requirements']['glpi']['max'], 'Max GLPI');
    assertSameValue('7.4', $info['requirements']['php']['min'], 'Min PHP');

    assertTrueValue(plugin_contracthours_check_prerequisites(), 'Prerequisites pass');
    assertTrueValue(plugin_contracthours_check_config(false), 'Check config pass');

    assertTrueValue(!empty($PLUGIN_HOOKS['csrf_compliant']['contracthours']), 'CSRF compliant');
    assertSameValue('front/config.form.php', $PLUGIN_HOOKS['config_page']['contracthours'] ?? '', 'Config page');
    assertTrueValue(isset($PLUGIN_HOOKS['pre_item_add']['contracthours']['TicketTask']), 'pre_item_add TicketTask');
    assertTrueValue(isset($PLUGIN_HOOKS['pre_item_update']['contracthours']['TicketTask']), 'pre_item_update TicketTask');
    assertTrueValue(isset($PLUGIN_HOOKS['item_add']['contracthours']['TicketTask']), 'item_add TicketTask');
    assertTrueValue(isset($PLUGIN_HOOKS['item_update']['contracthours']['TicketTask']), 'item_update TicketTask');
    assertTrueValue(isset($PLUGIN_HOOKS['item_delete']['contracthours']['TicketTask']), 'item_delete TicketTask');
    assertTrueValue(isset($PLUGIN_HOOKS['item_purge']['contracthours']['TicketTask']), 'item_purge TicketTask');
    assertTrueValue(isset($PLUGIN_HOOKS['item_purge']['contracthours']['Ticket']), 'item_purge Ticket');
    assertTrueValue(isset($PLUGIN_HOOKS['item_purge']['contracthours']['Contract']), 'item_purge Contract');

    $tabRegistered = false;
    foreach (Plugin::$registered as $entry) {
        if ($entry['class'] === PluginContracthoursTab::class) {
            $tabRegistered = true;
            assertSameValue(['Contract', 'Ticket'], $entry['params']['addtabon'] ?? [], 'Tab registered on Contract and Ticket');
        }
    }
    assertTrueValue($tabRegistered, 'Tab class registered');
}

function test_compat_and_rightname_typing(): void
{
    resetFixture();

    $ref = new ReflectionProperty(PluginContracthoursTab::class, 'rightname');
    assertSameValue(TEST_TYPED_RIGHTNAME, $ref->hasType(), 'Tab $rightname type matches core');
    assertSameValue(TEST_TYPED_RIGHTNAME, PluginContracthoursCompat::typedRightname(), 'Compat typedRightname');

    $isModern = version_compare((string) GLPI_VERSION, '11.0.0-dev', '>=');
    $expectedAsset = $isModern ? 'css/contracthours.css' : 'public/css/contracthours.css';
    assertSameValue($expectedAsset, PluginContracthoursCompat::assetPath('css/contracthours.css'), 'assetPath');

    $web = PluginContracthoursCompat::webPath(false);
    assertSameValue('/plugins/contracthours', $web, 'webPath relative');
    assertStringNotContains('/marketplace/', $web, 'webPath never marketplace');
    if ($isModern) {
        assertFalseValue(Plugin::$webDirCalled, 'GLPI 11+ never calls Plugin::getWebDir');
    }

    $legacyDb = new LegacyQueryOnlyDB();
    assertTrueValue((bool) PluginContracthoursCompat::execDdl($legacyDb, 'CREATE TABLE `test_tbl` (`id` int);'), 'Legacy query()-only DB works');
    assertSameValue(1, count($legacyDb->executed), 'Legacy query() executed once');

    $decoded = PluginContracthoursCompat::fromCore('A &#38; B \\\\ C');
    if ($isModern) {
        assertSameValue('A &#38; B \\\\ C', $decoded, 'GLPI 11+ does not decode HTML entities');
    } else {
        assertSameValue('A & B \\\\ C', $decoded, 'GLPI <11 decodes HTML entities without stripslashes');
    }

    if (PluginContracthoursCompat::glpiMajor() >= 12) {
        $threw = false;
        try {
            PluginContracthoursCompat::denyAccess('Forbidden');
        } catch (\Glpi\Exception\Http\AccessDeniedHttpException $e) {
            $threw = true;
        }
        assertTrueValue($threw, 'GLPI 12+ throws AccessDeniedHttpException');
    } else {
        PluginContracthoursCompat::denyAccess('Forbidden');
        assertTrueValue(Html::$rightErrorDisplayed, 'GLPI 10/11 calls Html::displayRightError');
    }
}

function test_format_minutes_and_money(): void
{
    assertSameValue('00:00', PluginContracthoursPool::formatMinutes(0), 'Format 0m');
    assertSameValue('01:30', PluginContracthoursPool::formatMinutes(90), 'Format 90m');
    assertSameValue('-01:30', PluginContracthoursPool::formatMinutes(-90), 'Format -90m');
    assertSameValue('120:05', PluginContracthoursPool::formatMinutes(7205), 'Format 7205m');

    assertSameValue(0, PluginContracthoursPool::secondsToMinutes(0), '0s -> 0m');
    assertSameValue(0, PluginContracthoursPool::secondsToMinutes(-120), 'Negative -> 0m');
    assertSameValue(90, PluginContracthoursPool::secondsToMinutes(5429), '5429s -> 90m');
    assertSameValue(91, PluginContracthoursPool::secondsToMinutes(5431), '5431s -> 91m');

    assertSameValue(15050, PluginContracthoursPool::parseMoneyToCents('150,50'), 'Parse comma decimal');
    assertSameValue(15050, PluginContracthoursPool::parseMoneyToCents('150.50'), 'Parse dot decimal');
    assertSameValue(125075, PluginContracthoursPool::parseMoneyToCents('1.250,75'), 'Parse thousands+comma');
    assertSameValue(20000, PluginContracthoursPool::parseMoneyToCents('200'), 'Parse integer');
}

function test_four_tier_contract_resolution(): void
{
    global $DB;

    resetFixture();

    $DB->seed('glpi_entities', [
        ['id' => 0, 'entities_id' => 0, 'name' => 'Root'],
        ['id' => 10, 'entities_id' => 0, 'name' => 'Parent'],
        ['id' => 11, 'entities_id' => 10, 'name' => 'Child'],
        ['id' => 12, 'entities_id' => 10, 'name' => 'ChildNonRec'],
    ]);

    seedContractWithPool(1, 0, 'Default Root', 600);
    seedContractWithPool(2, 10, 'Default Parent', 600);
    seedContractWithPool(3, 11, 'Asset Contract', 600);
    seedContractWithPool(4, 11, 'Ticket Item Contract', 600);
    seedContractWithPool(5, 11, 'Explicit Ticket Link Contract', 600);
    seedContractWithPool(6, 11, 'Inactive Pool', 600, ['is_active' => 0]);

    // Nível 4: Herança recursiva da entidade
    PluginContracthoursConfig::setEntityDefault(0, 1, 1);
    PluginContracthoursConfig::setEntityDefault(10, 2, 1);
    $DB->seed('glpi_tickets', [
        ['id' => 100, 'entities_id' => 11, 'name' => 'Ticket child'],
    ]);

    $res = PluginContracthoursPool::resolveContractForTicket(100);
    assertSameValue(2, $res['contracts_id'] ?? 0, 'Tier 4 selects closest recursive ancestor');
    assertSameValue('entity_default', $res['source'] ?? '', 'Tier 4 source');

    // Ancestral não recursivo não se aplica ao filho
    PluginContracthoursConfig::setEntityDefault(10, 2, 0);
    $resNonRec = PluginContracthoursPool::resolveContractForTicket(100);
    assertSameValue(1, $resNonRec['contracts_id'] ?? 0, 'Non-recursive parent skipped, falls back to root');

    // Nível 3: Ativo vinculado em glpi_items_tickets -> glpi_contracts_items
    $DB->seed('glpi_items_tickets', [
        ['id' => 1, 'itemtype' => 'Computer', 'items_id' => 77, 'tickets_id' => 100],
    ]);
    $DB->seed('glpi_contracts_items', [
        ['id' => 1, 'contracts_id' => 6, 'itemtype' => 'Computer', 'items_id' => 77], // pool inativo ignorado
        ['id' => 2, 'contracts_id' => 3, 'itemtype' => 'Computer', 'items_id' => 77],
    ]);
    $res3 = PluginContracthoursPool::resolveContractForTicket(100);
    assertSameValue(3, $res3['contracts_id'] ?? 0, 'Tier 3 resolves via asset contract skipping inactive pool');
    assertSameValue('contract_item_asset', $res3['source'] ?? '', 'Tier 3 source');

    // Nível 2: glpi_contracts_items diretamente no Ticket
    $DB->seed('glpi_contracts_items', [
        ['id' => 3, 'contracts_id' => 4, 'itemtype' => 'Ticket', 'items_id' => 100],
    ]);
    $res2 = PluginContracthoursPool::resolveContractForTicket(100);
    assertSameValue(4, $res2['contracts_id'] ?? 0, 'Tier 2 overrides Tier 3');
    assertSameValue('contract_item_ticket', $res2['source'] ?? '', 'Tier 2 source');

    // Nível 1: Vínculo explícito na tabela ticket_links
    PluginContracthoursPool::setTicketContractLink(100, 5, 2);
    $res1 = PluginContracthoursPool::resolveContractForTicket(100);
    assertSameValue(5, $res1['contracts_id'] ?? 0, 'Tier 1 overrides Tier 2');
    assertSameValue('ticket_link', $res1['source'] ?? '', 'Tier 1 source');

    // Se contrato 5 for para a lixeira (is_deleted = 1), volta ao Tier 2
    $DB->update('glpi_contracts', ['is_deleted' => 1], ['id' => 5]);
    $resFallback = PluginContracthoursPool::resolveContractForTicket(100);
    assertSameValue(4, $resFallback['contracts_id'] ?? 0, 'Deleted contract skipped');
}

function test_tickettask_add_update_delete_and_purge_math(): void
{
    global $DB;

    resetFixture();

    seedContractWithPool(10, 0, 'Contrato A', 600);
    seedContractWithPool(20, 0, 'Contrato B', 300);

    $DB->seed('glpi_tickets', [
        ['id' => 200, 'entities_id' => 0, 'name' => 'Chamado Teste'],
    ]);
    PluginContracthoursPool::setTicketContractLink(200, 10, 2);

    // Add task 1: 5400s = 90m
    $t1 = makeTask(501, 200, 5400);
    PluginContracthoursPool::itemAddTicketTask($t1);
    $sumA = PluginContracthoursPool::getPoolSummary(10);
    assertSameValue(510, $sumA['remaining_minutes'], 'Remaining after 90m debit');
    assertSameValue(90, $sumA['consumed_minutes'], 'Consumed after 90m debit');

    // Idempotência: chamar itemAdd novamente para a mesma tarefa não duplica
    PluginContracthoursPool::itemAddTicketTask($t1);
    $sumA2 = PluginContracthoursPool::getPoolSummary(10);
    assertSameValue(510, $sumA2['remaining_minutes'], 'Idempotent itemAdd does not double-debit');

    // Update task 1 para 7200s (120m): delta +30m debit
    $t1->fields['actiontime'] = 7200;
    PluginContracthoursPool::itemUpdateTicketTask($t1);
    $sumA3 = PluginContracthoursPool::getPoolSummary(10);
    assertSameValue(480, $sumA3['remaining_minutes'], 'Remaining after update to 120m');

    // Update task 1 para 2700s (45m): delta -75m -> refund +75m
    $t1->fields['actiontime'] = 2700;
    PluginContracthoursPool::itemUpdateTicketTask($t1);
    $sumA4 = PluginContracthoursPool::getPoolSummary(10);
    assertSameValue(555, $sumA4['remaining_minutes'], 'Remaining after reduction to 45m');

    // Muda vínculo do chamado para Contrato B e atualiza tarefa para 3600s (60m):
    // Contrato A deve receber estorno de 45m (volta a 600) e Contrato B deve debitar 60m (fica 240)
    PluginContracthoursPool::setTicketContractLink(200, 20, 2);
    $t1->fields['actiontime'] = 3600;
    PluginContracthoursPool::itemUpdateTicketTask($t1);

    $sumAAfterSwitch = PluginContracthoursPool::getPoolSummary(10);
    $sumBAfterSwitch = PluginContracthoursPool::getPoolSummary(20);
    assertSameValue(600, $sumAAfterSwitch['remaining_minutes'], 'Contract A refunded on contract switch');
    assertSameValue(240, $sumBAfterSwitch['remaining_minutes'], 'Contract B debited on contract switch');

    // Delete task 1 -> estorna 60m para Contrato B; Purge em seguida não duplica estorno
    PluginContracthoursPool::itemDeleteTicketTask($t1);
    $sumBDeleted = PluginContracthoursPool::getPoolSummary(20);
    assertSameValue(300, $sumBDeleted['remaining_minutes'], 'Contract B refunded on task delete');

    PluginContracthoursPool::itemPurgeTicketTask($t1);
    $sumBPurged = PluginContracthoursPool::getPoolSummary(20);
    assertSameValue(300, $sumBPurged['remaining_minutes'], 'Subsequent purge does not double-refund');

    // Add task 2 (30m) e purge direto (sem delete) -> estorna 30m
    $t2 = makeTask(502, 200, 1800);
    PluginContracthoursPool::itemAddTicketTask($t2);
    assertSameValue(270, PluginContracthoursPool::getPoolSummary(20)['remaining_minutes'], 'Task 2 debited');
    PluginContracthoursPool::itemPurgeTicketTask($t2);
    assertSameValue(300, PluginContracthoursPool::getPoolSummary(20)['remaining_minutes'], 'Direct purge refunds task 2');
}

function test_block_on_overrun_guard(): void
{
    global $DB;

    resetFixture();
    seedContractWithPool(30, 0, 'Contrato Bloqueado', 60, ['block_on_overrun' => 1]);
    $DB->seed('glpi_tickets', [['id' => 300, 'entities_id' => 0, 'name' => 'Ticket Guard']]);
    PluginContracthoursPool::setTicketContractLink(300, 30, 2);

    // Tentar adicionar tarefa de 90m em pool de 60m deve bloquear
    $overTask        = new TicketTask();
    $overTask->input = ['tickets_id' => 300, 'actiontime' => 5400];
    PluginContracthoursPool::preItemAddTicketTask($overTask);
    assertSameValue(false, $overTask->input, 'Overrun task creation blocked');
    assertTrueValue(!empty(Session::$messages), 'Error message added on block');

    // Adicionar tarefa de 40m cabe no saldo (fica 20m)
    Session::$messages = [];
    $okTask        = new TicketTask();
    $okTask->input = ['tickets_id' => 300, 'actiontime' => 2400];
    PluginContracthoursPool::preItemAddTicketTask($okTask);
    assertTrueValue(is_array($okTask->input), 'Task within balance allowed');
    $okTask->fields = ['id' => 601, 'tickets_id' => 300, 'actiontime' => 2400, 'entities_id' => 0, 'users_id' => 2];
    PluginContracthoursPool::itemAddTicketTask($okTask);

    // Tentar atualizar tarefa 601 de 40m para 70m (+30m > 20m restantes) deve bloquear
    $okTask->input = ['tickets_id' => 300, 'actiontime' => 4200];
    PluginContracthoursPool::preItemUpdateTicketTask($okTask);
    assertSameValue(false, $okTask->input, 'Overrun task update blocked');

    // Reduzir para 30m sempre é permitido
    $okTask->input = ['tickets_id' => 300, 'actiontime' => 1800];
    PluginContracthoursPool::preItemUpdateTicketTask($okTask);
    assertTrueValue(is_array($okTask->input), 'Task reduction allowed');

    // Em CLI/cron, bloqueio não interrompe automação
    $GLOBALS['TEST_CLI_MODE'] = true;
    $cliTask                  = new TicketTask();
    $cliTask->input           = ['tickets_id' => 300, 'actiontime' => 7200];
    PluginContracthoursPool::preItemAddTicketTask($cliTask);
    assertTrueValue(is_array($cliTask->input), 'CLI/cron bypasses interactive block');
}

function test_threshold_alert_and_overrun_transitions(): void
{
    global $DB;

    resetFixture();
    seedContractWithPool(40, 0, 'Contrato Alerta', 100, [
        'alert_threshold_pct' => 80,
        'block_on_overrun'    => 0,
    ]);
    $DB->seed('glpi_tickets', [['id' => 400, 'entities_id' => 0, 'name' => 'Ticket Alerta']]);
    PluginContracthoursPool::setTicketContractLink(400, 40, 2);

    // 50% (50m): sem alerta
    PluginContracthoursPool::itemAddTicketTask(makeTask(701, 400, 3000));
    assertSameValue(0, count(Session::$messages), 'No alert at 50%');

    // +30m -> 80% (cruza limiar): 1 WARNING
    PluginContracthoursPool::itemAddTicketTask(makeTask(702, 400, 1800));
    assertSameValue(1, count(Session::$messages), 'Warning emitted when crossing 80%');
    assertSameValue(WARNING, Session::$messages[0]['type'], 'Threshold alert uses WARNING');

    // +10m -> 90% (já estava >= 80%): não repete WARNING
    Session::$messages = [];
    PluginContracthoursPool::itemAddTicketTask(makeTask(703, 400, 600));
    assertSameValue(0, count(Session::$messages), 'No duplicate warning at 90%');

    // +20m -> -10m (cruza para saldo negativo): 1 ERROR
    PluginContracthoursPool::itemAddTicketTask(makeTask(704, 400, 1200));
    assertSameValue(1, count(Session::$messages), 'Error emitted when crossing into negative balance');
    assertSameValue(ERROR, Session::$messages[0]['type'], 'Overrun alert uses ERROR');
    assertSameValue('-00:10', PluginContracthoursPool::formatMinutes(PluginContracthoursPool::getPoolSummary(40)['remaining_minutes']), 'Formatted negative balance');

    // +10m -> -20m (já estava negativo): não repete ERROR
    Session::$messages = [];
    PluginContracthoursPool::itemAddTicketTask(makeTask(705, 400, 600));
    assertSameValue(0, count(Session::$messages), 'No duplicate overrun error');
}

function test_manual_adjustment_and_ledger_extract(): void
{
    resetFixture();
    seedContractWithPool(50, 0, 'Contrato Manual', 120);

    assertTrueValue(PluginContracthoursPool::recordManualAdjustment(50, 60, 'Bonificação mensal', 2), 'Credit succeeds');
    assertSameValue(180, PluginContracthoursPool::getPoolSummary(50)['remaining_minutes'], 'Balance after credit');

    assertTrueValue(PluginContracthoursPool::recordManualAdjustment(50, -30, 'Ajuste auditoria', 2), 'Debit succeeds');
    assertSameValue(150, PluginContracthoursPool::getPoolSummary(50)['remaining_minutes'], 'Balance after debit');

    assertFalseValue(PluginContracthoursPool::recordManualAdjustment(50, 0, 'Zero'), 'Zero delta rejected');

    $ledger = PluginContracthoursPool::getLedgerForContract(50, 50);
    assertSameValue(2, count($ledger), 'Two ledger entries');
    assertSameValue(-30, $ledger[0]['minutes_delta'], 'Latest entry first');
    assertSameValue(PluginContracthoursPool::ENTRY_MANUAL, $ledger[0]['entry_type'], 'Entry type manual');
}

function test_contract_and_ticket_purge_cleanup(): void
{
    global $DB;

    resetFixture();
    seedContractWithPool(60, 0, 'Contrato Expurgo', 200);
    seedContractWithPool(61, 0, 'Contrato Outro', 200);
    PluginContracthoursConfig::setEntityDefault(0, 60, 1);

    $DB->seed('glpi_tickets', [
        ['id' => 600, 'entities_id' => 0, 'name' => 'Ticket 600'],
        ['id' => 601, 'entities_id' => 0, 'name' => 'Ticket 601'],
    ]);
    PluginContracthoursPool::setTicketContractLink(600, 60, 2);
    PluginContracthoursPool::setTicketContractLink(601, 61, 2);

    PluginContracthoursPool::itemAddTicketTask(makeTask(801, 600, 1800));
    PluginContracthoursPool::itemAddTicketTask(makeTask(802, 601, 1800));

    // Expurga Ticket 601 -> limpa link e razão do Ticket 601, mantém Contrato 61
    $ticket601         = new Ticket();
    $ticket601->fields = ['id' => 601, 'entities_id' => 0];
    PluginContracthoursPool::itemPurgeTicket($ticket601);

    assertSameValue(0, PluginContracthoursPool::getTicketContractLink(601), 'Ticket link purged');
    assertSameValue([], PluginContracthoursPool::getLedgerForTicket(601), 'Ticket ledger purged');
    assertTrueValue(PluginContracthoursPool::getPoolByContract(61) !== null, 'Contract 61 pool intact');

    // Expurga Contrato 60 -> limpa pool, razão, link de Ticket 600 e entity_default
    $contract60         = new Contract();
    $contract60->fields = ['id' => 60, 'entities_id' => 0];
    PluginContracthoursPool::itemPurgeContract($contract60);

    assertSameValue(null, PluginContracthoursPool::getPoolByContract(60), 'Contract pool purged');
    assertSameValue([], PluginContracthoursPool::getLedgerForContract(60), 'Contract ledger purged');
    assertSameValue(0, PluginContracthoursPool::getTicketContractLink(600), 'Ticket 600 link purged');
    assertSameValue([], PluginContracthoursConfig::getEntityDefaults(), 'Entity default removed');
}

function test_tabs_security_and_rendering(): void
{
    global $DB;

    resetFixture();
    seedContractWithPool(70, 5, '<script>alert(1)</script> Contrato', 240);
    $DB->seed('glpi_tickets', [
        ['id' => 700, 'entities_id' => 5, 'name' => 'Chamado XSS'],
    ]);
    PluginContracthoursPool::setTicketContractLink(700, 70, 2);
    PluginContracthoursPool::recordManualAdjustment(70, -60, '<img src=x onerror=alert(1)>', 2);

    $tab      = new PluginContracthoursTab();
    $contract = new Contract();
    $contract->getFromDB(70);

    $ticket = new Ticket();
    $ticket->getFromDB(700);

    assertSameValue('Banco de Horas 1', $tab->getTabNameForItem($contract), 'Contract tab name with ledger count');
    assertSameValue('Banco de Horas', $tab->getTabNameForItem($ticket), 'Ticket tab name');

    ob_start();
    $renderedC = PluginContracthoursTab::displayTabContentForItem($contract);
    $htmlC     = (string) ob_get_clean();
    assertTrueValue($renderedC, 'Contract tab rendered');
    assertStringNotContains('<img src=x onerror=alert(1)>', $htmlC, 'XSS escaped in contract ledger');
    assertStringContains('&lt;img src=x onerror=alert(1)&gt;', $htmlC, 'Escaped payload present');

    ob_start();
    $renderedT = PluginContracthoursTab::displayTabContentForItem($ticket);
    $htmlT     = (string) ob_get_clean();
    assertTrueValue($renderedT, 'Ticket tab rendered');
    assertStringNotContains('<script>alert(1)</script>', $htmlT, 'XSS escaped in ticket tab');
    assertStringContains('03:00', $htmlT, 'Remaining balance 03:00 displayed');

    // Sem acesso à entidade -> bloqueia aba
    Session::$accessibleEntities = [0 => true];
    assertSameValue('', $tab->getTabNameForItem($contract), 'Denied entity hides Contract tab');
    assertSameValue('', $tab->getTabNameForItem($ticket), 'Denied entity hides Ticket tab');
    assertFalseValue(PluginContracthoursTab::displayTabContentForItem($ticket), 'Denied entity blocks Ticket tab content');

    // Self-service oculto por padrão e visível quando habilitado
    Session::$accessibleEntities = null;
    Session::$interface          = 'helpdesk';
    assertSameValue('', $tab->getTabNameForItem($ticket), 'Self-service hidden by default');

    PluginContracthoursConfig::saveGlobalOptions([
        'default_alert_threshold_pct' => 80,
        'show_tab_selfservice'        => 1,
    ]);
    assertSameValue('Banco de Horas', $tab->getTabNameForItem($ticket), 'Self-service visible when enabled');
}

function test_front_endpoints_post_and_security(): void
{
    global $DB;

    $origServer = $_SERVER;
    $origPost   = $_POST;

    try {
        resetFixture();
        $DB->seed('glpi_entities', [
            ['id' => 0, 'entities_id' => 0, 'name' => 'Root'],
        ]);
        $DB->seed('glpi_contracts', [
            ['id' => 80, 'entities_id' => 0, 'name' => 'Contrato Front', 'num' => 'C-80', 'is_deleted' => 0],
        ]);
        $DB->seed('glpi_tickets', [
            ['id' => 800, 'entities_id' => 0, 'name' => 'Ticket Front'],
        ]);

        // 1. pool.form.php: save_pool
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];
        $_POST   = [
            'action'                  => 'save_pool',
            'contracts_id'            => '80',
            'contracted_hours'        => '10',
            'contracted_minutes_part' => '30',
            'alert_threshold_pct'     => '75',
            'hourly_rate'             => '180,25',
            'billing_cycle'           => 'monthly',
            'block_on_overrun'        => '1',
            'allow_rollover'          => '1',
            'is_active'               => '1',
        ];
        include GLPI_ROOT . '/contracthours/front/pool.form.php';
        $pool = PluginContracthoursPool::getPoolByContract(80);
        assertSameValue(630, $pool['contracted_minutes'] ?? 0, 'save_pool saved 10:30');
        assertSameValue(18025, $pool['hourly_rate_cents'] ?? 0, 'save_pool saved cents');
        assertSameValue(1, $pool['block_on_overrun'] ?? 0, 'save_pool saved block_on_overrun');

        // 2. pool.form.php: manual_adjustment
        $_POST = [
            'action'          => 'manual_adjustment',
            'contracts_id'    => '80',
            'adjustment_type' => 'debit',
            'adjust_hours'    => '1',
            'adjust_minutes'  => '15',
            'note'            => 'Ajuste inicial',
        ];
        include GLPI_ROOT . '/contracthours/front/pool.form.php';
        assertSameValue(555, PluginContracthoursPool::getPoolSummary(80)['remaining_minutes'], 'manual_adjustment debited 75m');

        // 3. pool.form.php: set_ticket_link
        $_POST = [
            'action'       => 'set_ticket_link',
            'tickets_id'   => '800',
            'contracts_id' => '80',
        ];
        include GLPI_ROOT . '/contracthours/front/pool.form.php';
        assertSameValue(80, PluginContracthoursPool::getTicketContractLink(800), 'set_ticket_link updated');

        // 4. config.form.php: POST save_entity_default + update_global + GET render
        $_POST = [
            'save_entity_default' => '1',
            'entities_id'         => '0',
            'contracts_id'        => '80',
            'is_recursive'        => '1',
        ];
        include GLPI_ROOT . '/contracthours/front/config.form.php';
        assertSameValue(80, PluginContracthoursConfig::resolveDefaultContractForEntity(0), 'Entity default saved');

        $_POST = [
            'update_global'               => '1',
            'default_alert_threshold_pct' => '85',
            'show_tab_selfservice'        => '1',
        ];
        include GLPI_ROOT . '/contracthours/front/config.form.php';
        $cfg = PluginContracthoursConfig::getConfig();
        assertSameValue(85, $cfg['default_alert_threshold_pct'], 'Global threshold saved');
        assertSameValue(1, $cfg['show_tab_selfservice'], 'Self-service option saved');

        $_SERVER = ['REQUEST_METHOD' => 'GET'];
        $_POST   = [];
        ob_start();
        include GLPI_ROOT . '/contracthours/front/config.form.php';
        $dashboardHtml = (string) ob_get_clean();
        assertStringContains('Contrato Front', $dashboardHtml, 'Dashboard lists active contract');
        assertStringContains('09:15', $dashboardHtml, 'Dashboard displays remaining balance');

        // 5. Cross-origin POST deve falhar
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_SEC_FETCH_SITE' => 'cross-site'];
        $_POST   = ['update_global' => '1'];
        $blocked = false;
        try {
            include GLPI_ROOT . '/contracthours/front/config.form.php';
        } catch (RuntimeException $e) {
            if (PluginContracthoursCompat::glpiMajor() >= 12) {
                assertTrueValue(
                    $e instanceof \Glpi\Exception\Http\AccessDeniedHttpException,
                    'GLPI 12 cross-origin throws AccessDeniedHttpException'
                );
            }
            $blocked = true;
        }
        if (PluginContracthoursCompat::glpiMajor() < 12 && Html::$rightErrorDisplayed) {
            $blocked = true;
        }
        assertTrueValue($blocked, 'Cross-origin POST rejected');

        // 6. Somente leitura em config bloqueia POST
        Session::$rights['config'] = READ;
        $_SERVER                   = ['REQUEST_METHOD' => 'POST', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];
        $denied                    = false;
        try {
            include GLPI_ROOT . '/contracthours/front/config.form.php';
        } catch (RuntimeException $e) {
            $denied = true;
        }
        assertTrueValue($denied, 'Read-only config right rejects POST');
    } finally {
        $_SERVER = $origServer;
        $_POST   = $origPost;
    }
}

function test_install_and_uninstall_lifecycle(): void
{
    global $DB;

    resetFixture();
    assertTrueValue($DB->tableExists(PluginContracthoursPool::TABLE_POOLS), 'Pools table exists');
    assertTrueValue($DB->tableExists(PluginContracthoursPool::TABLE_LEDGER), 'Ledger table exists');
    assertTrueValue($DB->tableExists(PluginContracthoursPool::TABLE_LINKS), 'Links table exists');

    $cfg = Config::getConfigurationValues(PluginContracthoursConfig::CONFIG_CONTEXT);
    assertSameValue('80', $cfg['default_alert_threshold_pct'] ?? null, 'Default threshold populated');

    plugin_contracthours_uninstall();
    assertFalseValue($DB->tableExists(PluginContracthoursPool::TABLE_POOLS), 'Pools table dropped');
    assertFalseValue($DB->tableExists(PluginContracthoursPool::TABLE_LEDGER), 'Ledger table dropped');
    assertFalseValue($DB->tableExists(PluginContracthoursPool::TABLE_LINKS), 'Links table dropped');

    $cfgAfter = Config::getConfigurationValues(PluginContracthoursConfig::CONFIG_CONTEXT);
    assertSameValue([], $cfgAfter, 'Config values deleted on uninstall');
}

$tests = [
    'test_plugin_manifest_and_hooks',
    'test_compat_and_rightname_typing',
    'test_format_minutes_and_money',
    'test_four_tier_contract_resolution',
    'test_tickettask_add_update_delete_and_purge_math',
    'test_block_on_overrun_guard',
    'test_threshold_alert_and_overrun_transitions',
    'test_manual_adjustment_and_ledger_extract',
    'test_contract_and_ticket_purge_cleanup',
    'test_tabs_security_and_rendering',
    'test_front_endpoints_post_and_security',
    'test_install_and_uninstall_lifecycle',
];

$isGlpi11Flag = in_array('--glpi11', $argv ?? [], true);
$isGlpi12Flag = in_array('--glpi12', $argv ?? [], true) || in_array('--typed-rightname', $argv ?? [], true);
if (TEST_TYPED_RIGHTNAME && $isGlpi12Flag) {
    $modeLabel = 'GLPI 12.0.0 (typed $rightname)';
} elseif ($isGlpi11Flag) {
    $modeLabel = 'GLPI 11.0.0 (typed $rightname, modern assets/webPath)';
} else {
    $modeLabel = 'GLPI 10.0.18 (untyped $rightname)';
}

echo "Running Contract Hours tests in {$modeLabel}...\n";

foreach ($tests as $testFn) {
    $testFn();
    echo "  [PASS] {$testFn}\n";
}

echo count($tests) . " tests passed in {$modeLabel}.\n";

if (!$isGlpi11Flag && !$isGlpi12Flag) {
    foreach (['--glpi11', '--glpi12'] as $flag) {
        $cmd    = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . $flag . ' 2>&1';
        $output = [];
        $code   = 0;
        exec($cmd, $output, $code);
        foreach ($output as $line) {
            echo $line . "\n";
        }
        if ($code !== 0) {
            exit($code);
        }
    }
}
