<?php

/**
 * ------------------------------------------------------------------------
 * Contract Hours - Motor de banco de horas, razão e hooks de tarefas
 *
 * Copyright (C) 2026 by G. Martins
 * ------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Contract Hours.
 *
 * Contract Hours is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Contract Hours is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * ------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Acesso direto não permitido.');
}

/**
 * Gerencia pools de horas por contrato, razão contábil em minutos inteiros e hooks em TicketTask/Ticket/Contract.
 */
class PluginContracthoursPool
{
    public const TABLE_POOLS  = 'glpi_plugin_contracthours_pools';
    public const TABLE_LEDGER = 'glpi_plugin_contracthours_ledger';
    public const TABLE_LINKS  = 'glpi_plugin_contracthours_ticket_links';

    public const ENTRY_DEBIT  = 'debit';
    public const ENTRY_REFUND = 'refund';
    public const ENTRY_MANUAL = 'manual_adjustment';

    /**
     * Cria as tabelas do plugin na instalação/atualização.
     */
    public static function installSchema(): void
    {
        global $DB;

        if (!isset($DB)) {
            return;
        }

        $poolsSql = 'CREATE TABLE IF NOT EXISTS `' . self::TABLE_POOLS . '` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `contracts_id` int unsigned NOT NULL,
            `entities_id` int unsigned NOT NULL DEFAULT \'0\',
            `contracted_minutes` int NOT NULL DEFAULT \'0\',
            `alert_threshold_pct` tinyint unsigned NOT NULL DEFAULT \'80\',
            `block_on_overrun` tinyint NOT NULL DEFAULT \'0\',
            `allow_rollover` tinyint NOT NULL DEFAULT \'0\',
            `hourly_rate_cents` int unsigned NOT NULL DEFAULT \'0\',
            `billing_cycle` varchar(16) NOT NULL DEFAULT \'total\',
            `is_active` tinyint NOT NULL DEFAULT \'1\',
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_contracts_id` (`contracts_id`),
            KEY `idx_entities_active` (`entities_id`, `is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;';

        $ledgerSql = 'CREATE TABLE IF NOT EXISTS `' . self::TABLE_LEDGER . '` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `contracts_id` int unsigned NOT NULL,
            `tickets_id` int unsigned NOT NULL DEFAULT \'0\',
            `tickettasks_id` int unsigned NOT NULL DEFAULT \'0\',
            `entities_id` int unsigned NOT NULL DEFAULT \'0\',
            `users_id` int unsigned NOT NULL DEFAULT \'0\',
            `minutes_delta` int NOT NULL DEFAULT \'0\',
            `entry_type` varchar(32) NOT NULL DEFAULT \'debit\',
            `note` varchar(255) NOT NULL DEFAULT \'\',
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_contracts_id` (`contracts_id`),
            KEY `idx_tickets_id` (`tickets_id`),
            KEY `idx_tickettasks_id` (`tickettasks_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;';

        $linksSql = 'CREATE TABLE IF NOT EXISTS `' . self::TABLE_LINKS . '` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `tickets_id` int unsigned NOT NULL,
            `contracts_id` int unsigned NOT NULL,
            `users_id` int unsigned NOT NULL DEFAULT \'0\',
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_tickets_id` (`tickets_id`),
            KEY `idx_contracts_id` (`contracts_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;';

        PluginContracthoursCompat::execDdl($DB, $poolsSql);
        PluginContracthoursCompat::execDdl($DB, $ledgerSql);
        PluginContracthoursCompat::execDdl($DB, $linksSql);
    }

    /**
     * Remove as tabelas do plugin na desinstalação.
     */
    public static function uninstallSchema(): void
    {
        global $DB;

        if (!isset($DB)) {
            return;
        }

        PluginContracthoursCompat::execDdl($DB, 'DROP TABLE IF EXISTS `' . self::TABLE_LINKS . '`;');
        PluginContracthoursCompat::execDdl($DB, 'DROP TABLE IF EXISTS `' . self::TABLE_LEDGER . '`;');
        PluginContracthoursCompat::execDdl($DB, 'DROP TABLE IF EXISTS `' . self::TABLE_POOLS . '`;');
    }

    /**
     * Converte segundos de ação em minutos inteiros arredondados.
     */
    public static function secondsToMinutes(int $seconds): int
    {
        if ($seconds <= 0) {
            return 0;
        }
        return (int) round($seconds / 60);
    }

    /**
     * Formata minutos inteiros (incluindo saldos negativos) no formato HH:MM.
     */
    public static function formatMinutes(int $mins): string
    {
        $sign = $mins < 0 ? '-' : '';
        $abs  = abs($mins);
        $h    = (int) floor($abs / 60);
        $m    = $abs % 60;
        return sprintf('%s%02d:%02d', $sign, $h, $m);
    }

    /**
     * Formata valor monetário em centavos inteiros com duas casas decimais.
     */
    public static function formatCents(int $cents): string
    {
        if ($cents < 0) {
            $cents = 0;
        }
        return number_format($cents / 100, 2, ',', '.');
    }

    /**
     * Converte texto decimal (ex.: "150,50", "150.50", "1.250,00") para centavos inteiros sem imprecisão binária.
     */
    public static function parseMoneyToCents(string $raw): int
    {
        $clean = preg_replace('/[^0-9.,]/', '', trim($raw));
        if ($clean === null || $clean === '') {
            return 0;
        }

        $lastComma = strrpos($clean, ',');
        $lastDot   = strrpos($clean, '.');
        $sepPos    = false;

        if ($lastComma !== false && $lastDot !== false) {
            $sepPos = max($lastComma, $lastDot);
        } elseif ($lastComma !== false) {
            $sepPos = $lastComma;
        } elseif ($lastDot !== false) {
            $sepPos = $lastDot;
        }

        if ($sepPos === false) {
            $digits = preg_replace('/\D/', '', $clean);
            return ((int) $digits) * 100;
        }

        $intPart  = preg_replace('/\D/', '', substr($clean, 0, $sepPos));
        $fracPart = preg_replace('/\D/', '', substr($clean, $sepPos + 1));
        $fracPart = substr(str_pad((string) $fracPart, 2, '0', STR_PAD_RIGHT), 0, 2);

        return ((int) $intPart) * 100 + ((int) $fracPart);
    }

    /**
     * Retorna o registro do pool pelo ID do contrato.
     */
    public static function getPoolByContract(int $contracts_id): ?array
    {
        global $DB;

        if ($contracts_id <= 0 || !isset($DB) || !$DB->tableExists(self::TABLE_POOLS)) {
            return null;
        }

        $it = $DB->request([
            'FROM'  => self::TABLE_POOLS,
            'WHERE' => ['contracts_id' => $contracts_id],
            'LIMIT' => 1,
        ]);
        foreach ($it as $row) {
            return self::normalizePoolRow($row);
        }

        return null;
    }

    /**
     * Verifica se o contrato possui pool ativo e não está na lixeira.
     */
    public static function isPoolActiveAndValid(int $contracts_id): bool
    {
        $pool = self::getPoolByContract($contracts_id);
        if ($pool === null || empty($pool['is_active'])) {
            return false;
        }

        $contractRow = self::getContractRow($contracts_id);
        if ($contractRow !== null && !empty($contractRow['is_deleted'])) {
            return false;
        }

        return true;
    }

    /**
     * Cria ou atualiza o pool de horas de um contrato.
     */
    public static function savePool(int $contracts_id, array $data): bool
    {
        global $DB;

        if ($contracts_id <= 0 || !isset($DB) || !$DB->tableExists(self::TABLE_POOLS)) {
            return false;
        }

        $contractRow = self::getContractRow($contracts_id);
        $entitiesId  = isset($data['entities_id'])
            ? max(0, (int) $data['entities_id'])
            : (int) ($contractRow['entities_id'] ?? 0);

        $contractedMinutes = max(0, (int) ($data['contracted_minutes'] ?? 0));

        $cfgDefaults = PluginContracthoursConfig::getConfig();
        $threshold   = isset($data['alert_threshold_pct'])
            ? (int) $data['alert_threshold_pct']
            : (int) $cfgDefaults['default_alert_threshold_pct'];
        if ($threshold < 1) {
            $threshold = 1;
        } elseif ($threshold > 100) {
            $threshold = 100;
        }

        $cycle = strtolower(trim((string) ($data['billing_cycle'] ?? 'total')));
        if ($cycle !== 'monthly' && $cycle !== 'total') {
            $cycle = 'total';
        }

        $now = date('Y-m-d H:i:s');
        $payload = [
            'contracts_id'        => $contracts_id,
            'entities_id'         => $entitiesId,
            'contracted_minutes'  => $contractedMinutes,
            'alert_threshold_pct' => $threshold,
            'block_on_overrun'    => !empty($data['block_on_overrun']) ? 1 : 0,
            'allow_rollover'      => !empty($data['allow_rollover']) ? 1 : 0,
            'hourly_rate_cents'   => max(0, (int) ($data['hourly_rate_cents'] ?? 0)),
            'billing_cycle'       => $cycle,
            'is_active'           => array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1,
            'date_mod'            => $now,
        ];

        $existing = self::getPoolByContract($contracts_id);
        if ($existing !== null) {
            return (bool) $DB->update(
                self::TABLE_POOLS,
                PluginContracthoursCompat::dbValues($payload),
                ['id' => (int) $existing['id']]
            );
        }

        $payload['date_creation'] = $now;
        return (bool) $DB->insert(
            self::TABLE_POOLS,
            PluginContracthoursCompat::dbValues($payload)
        );
    }

    /**
     * Retorna o resumo contábil completo de um pool de contrato.
     */
    public static function getPoolSummary(int $contracts_id): ?array
    {
        global $DB;

        $pool = self::getPoolByContract($contracts_id);
        if ($pool === null) {
            return null;
        }

        $netDelta    = 0;
        $ledgerCount = 0;

        if (isset($DB) && $DB->tableExists(self::TABLE_LEDGER)) {
            $it = $DB->request([
                'SELECT' => ['minutes_delta'],
                'FROM'   => self::TABLE_LEDGER,
                'WHERE'  => ['contracts_id' => $contracts_id],
            ]);
            foreach ($it as $row) {
                $netDelta += (int) ($row['minutes_delta'] ?? 0);
                $ledgerCount++;
            }
        }

        $contracted = (int) $pool['contracted_minutes'];
        $remaining  = $contracted + $netDelta;
        $consumed   = $contracted - $remaining;

        if ($contracted > 0) {
            $pct = (int) round(($consumed * 100) / $contracted);
        } else {
            $pct = $remaining < 0 ? 100 : 0;
        }

        $threshold = (int) $pool['alert_threshold_pct'];
        $isOverrun = $remaining < 0;
        $isAlert   = $pct >= $threshold;

        $pool['net_delta']            = $netDelta;
        $pool['remaining_minutes']    = $remaining;
        $pool['consumed_minutes']     = $consumed;
        $pool['consumption_pct']      = $pct;
        $pool['is_threshold_reached'] = $isAlert;
        $pool['is_overrun']           = $isOverrun;
        $pool['ledger_count']         = $ledgerCount;

        return $pool;
    }

    /**
     * Lista resumos de pools para o painel administrativo ou seleção.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getAllPoolSummaries(bool $activeOnly = false, bool $filterEntityAccess = false): array
    {
        global $DB;

        if (!isset($DB) || !$DB->tableExists(self::TABLE_POOLS)) {
            return [];
        }

        $where = [];
        if ($activeOnly) {
            $where['is_active'] = 1;
        }

        $it = $DB->request([
            'FROM'  => self::TABLE_POOLS,
            'WHERE' => $where,
            'ORDER' => ['contracts_id ASC'],
        ]);

        $list = [];
        foreach ($it as $row) {
            $cid      = (int) ($row['contracts_id'] ?? 0);
            $contract = self::getContractRow($cid);
            if ($contract !== null && !empty($contract['is_deleted'])) {
                continue;
            }

            $entityId = $contract !== null ? (int) ($contract['entities_id'] ?? 0) : (int) ($row['entities_id'] ?? 0);
            if ($filterEntityAccess && class_exists('Session') && method_exists('Session', 'haveAccessToEntity')) {
                if (!Session::haveAccessToEntity($entityId)) {
                    continue;
                }
            }

            $summary = self::getPoolSummary($cid);
            if ($summary === null) {
                continue;
            }
            $summary['contract_name'] = $contract !== null
                ? PluginContracthoursCompat::fromCore((string) ($contract['name'] ?? ''))
                : ('#' . $cid);
            $summary['contract_num'] = $contract !== null
                ? PluginContracthoursCompat::fromCore((string) ($contract['num'] ?? ''))
                : '';
            $summary['entities_id'] = $entityId;
            $list[]                 = $summary;
        }

        return $list;
    }

    /**
     * Retorna pools ativos elegíveis para seleção manual em um chamado de determinada entidade.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getAccessiblePoolsForTicket(int $ticketEntityId, int $currentLinkedContractId = 0): array
    {
        $ancestors = PluginContracthoursCompat::getEntitiesAncestorsFallback($ticketEntityId);
        $allowedEntities = array_merge([$ticketEntityId], $ancestors);

        $all    = self::getAllPoolSummaries(true, true);
        $result = [];

        foreach ($all as $summary) {
            $cid      = (int) $summary['contracts_id'];
            $entityId = (int) $summary['entities_id'];
            if ($cid === $currentLinkedContractId || in_array($entityId, $allowedEntities, true)) {
                $result[] = $summary;
            }
        }

        return $result;
    }

    /**
     * Retorna o extrato do razão de um contrato ordenado do mais recente para o mais antigo.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getLedgerForContract(int $contracts_id, int $limit = 100): array
    {
        return self::queryLedger(['contracts_id' => $contracts_id], $limit);
    }

    /**
     * Retorna o extrato do razão de um chamado ordenado do mais recente para o mais antigo.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getLedgerForTicket(int $tickets_id, int $limit = 100): array
    {
        return self::queryLedger(['tickets_id' => $tickets_id], $limit);
    }

    /**
     * Retorna o contrato explicitamente vinculado ao chamado na tabela ticket_links (0 se não houver).
     */
    public static function getTicketContractLink(int $tickets_id): int
    {
        global $DB;

        if ($tickets_id <= 0 || !isset($DB) || !$DB->tableExists(self::TABLE_LINKS)) {
            return 0;
        }

        $it = $DB->request([
            'SELECT' => ['contracts_id'],
            'FROM'   => self::TABLE_LINKS,
            'WHERE'  => ['tickets_id' => $tickets_id],
            'LIMIT'  => 1,
        ]);
        foreach ($it as $row) {
            return (int) ($row['contracts_id'] ?? 0);
        }

        return 0;
    }

    /**
     * Define ou remove o vínculo explícito de contrato de um chamado.
     */
    public static function setTicketContractLink(int $tickets_id, int $contracts_id, int $users_id = 0): bool
    {
        global $DB;

        if ($tickets_id <= 0 || !isset($DB) || !$DB->tableExists(self::TABLE_LINKS)) {
            return false;
        }

        if ($contracts_id <= 0) {
            return (bool) $DB->delete(self::TABLE_LINKS, ['tickets_id' => $tickets_id]);
        }

        if ($users_id <= 0 && class_exists('Session') && method_exists('Session', 'getLoginUserID')) {
            $users_id = (int) Session::getLoginUserID(true);
        }

        $now      = date('Y-m-d H:i:s');
        $existing = self::getTicketContractLink($tickets_id);

        if ($existing > 0) {
            return (bool) $DB->update(
                self::TABLE_LINKS,
                PluginContracthoursCompat::dbValues([
                    'contracts_id' => $contracts_id,
                    'users_id'     => max(0, $users_id),
                    'date_mod'     => $now,
                ]),
                ['tickets_id' => $tickets_id]
            );
        }

        return (bool) $DB->insert(
            self::TABLE_LINKS,
            PluginContracthoursCompat::dbValues([
                'tickets_id'    => $tickets_id,
                'contracts_id'  => $contracts_id,
                'users_id'      => max(0, $users_id),
                'date_creation' => $now,
                'date_mod'      => $now,
            ])
        );
    }

    /**
     * Resolve o contrato ativo aplicável a um chamado em 4 níveis:
     * 1) Vínculo explícito em glpi_plugin_contracthours_ticket_links
     * 2) glpi_contracts_items com itemtype = 'Ticket'
     * 3) glpi_contracts_items via ativo vinculado em glpi_items_tickets
     * 4) Contrato padrão configurado para a entidade / ancestral recursivo
     *
     * @return array{contracts_id: int, source: string, pool: array<string, mixed>}|null
     */
    public static function resolveContractForTicket(int $tickets_id): ?array
    {
        global $DB;

        if ($tickets_id <= 0 || !isset($DB)) {
            return null;
        }

        // Nível 1: Vínculo explícito na tabela ticket_links
        $linkedCid = self::getTicketContractLink($tickets_id);
        if ($linkedCid > 0 && self::isPoolActiveAndValid($linkedCid)) {
            $pool = self::getPoolByContract($linkedCid);
            if ($pool !== null) {
                return [
                    'contracts_id' => $linkedCid,
                    'source'       => 'ticket_link',
                    'pool'         => $pool,
                ];
            }
        }

        // Nível 2: glpi_contracts_items diretamente no chamado (itemtype = 'Ticket')
        if ($DB->tableExists('glpi_contracts_items')) {
            $it = $DB->request([
                'SELECT' => ['contracts_id'],
                'FROM'   => 'glpi_contracts_items',
                'WHERE'  => [
                    'itemtype' => 'Ticket',
                    'items_id' => $tickets_id,
                ],
                'ORDER'  => ['id ASC'],
            ]);
            foreach ($it as $row) {
                $cid = (int) ($row['contracts_id'] ?? 0);
                if ($cid > 0 && self::isPoolActiveAndValid($cid)) {
                    $pool = self::getPoolByContract($cid);
                    if ($pool !== null) {
                        return [
                            'contracts_id' => $cid,
                            'source'       => 'contract_item_ticket',
                            'pool'         => $pool,
                        ];
                    }
                }
            }
        }

        // Nível 3: glpi_contracts_items via ativo vinculado em glpi_items_tickets
        if ($DB->tableExists('glpi_items_tickets') && $DB->tableExists('glpi_contracts_items')) {
            $itemsIt = $DB->request([
                'SELECT' => ['itemtype', 'items_id'],
                'FROM'   => 'glpi_items_tickets',
                'WHERE'  => ['tickets_id' => $tickets_id],
                'ORDER'  => ['id ASC'],
            ]);
            foreach ($itemsIt as $itemRow) {
                $itemtype = trim((string) ($itemRow['itemtype'] ?? ''));
                $itemsId  = (int) ($itemRow['items_id'] ?? 0);
                if ($itemtype === '' || $itemtype === 'Ticket' || $itemsId <= 0) {
                    continue;
                }

                $contractsIt = $DB->request([
                    'SELECT' => ['contracts_id'],
                    'FROM'   => 'glpi_contracts_items',
                    'WHERE'  => [
                        'itemtype' => $itemtype,
                        'items_id' => $itemsId,
                    ],
                    'ORDER'  => ['id ASC'],
                ]);
                foreach ($contractsIt as $cRow) {
                    $cid = (int) ($cRow['contracts_id'] ?? 0);
                    if ($cid > 0 && self::isPoolActiveAndValid($cid)) {
                        $pool = self::getPoolByContract($cid);
                        if ($pool !== null) {
                            return [
                                'contracts_id' => $cid,
                                'source'       => 'contract_item_asset',
                                'pool'         => $pool,
                            ];
                        }
                    }
                }
            }
        }

        // Nível 4: Contrato padrão da entidade do chamado ou ancestral recursivo
        $ticketEntity = self::getTicketEntityId($tickets_id);
        $defaultCid   = PluginContracthoursConfig::resolveDefaultContractForEntity($ticketEntity);
        if ($defaultCid > 0) {
            $pool = self::getPoolByContract($defaultCid);
            if ($pool !== null) {
                return [
                    'contracts_id' => $defaultCid,
                    'source'       => 'entity_default',
                    'pool'         => $pool,
                ];
            }
        }

        return null;
    }

    /**
     * Hook pre_item_add em TicketTask: bloqueia criação se block_on_overrun = 1 e exceder saldo.
     *
     * @param object $item
     */
    public static function preItemAddTicketTask($item): void
    {
        if (!is_object($item) || !isset($item->input) || !is_array($item->input)) {
            return;
        }
        if (PluginContracthoursCompat::isCliOrCron()) {
            return;
        }

        $ticketsId  = (int) ($item->input['tickets_id'] ?? 0);
        $actiontime = (int) ($item->input['actiontime'] ?? 0);
        $minutes    = self::secondsToMinutes($actiontime);

        if ($ticketsId <= 0 || $minutes <= 0) {
            return;
        }

        $resolved = self::resolveContractForTicket($ticketsId);
        if ($resolved === null || empty($resolved['pool']['block_on_overrun'])) {
            return;
        }

        $summary = self::getPoolSummary((int) $resolved['contracts_id']);
        if ($summary === null) {
            return;
        }

        $remaining = (int) $summary['remaining_minutes'];
        if (($remaining - $minutes) < 0) {
            $item->input = false;
            if (class_exists('Session') && method_exists('Session', 'addMessageAfterRedirect')) {
                Session::addMessageAfterRedirect(
                    sprintf(
                        __('Bloqueado: o tempo da tarefa (%s) excede o saldo disponível (%s) no banco de horas do contrato.', 'contracthours'),
                        self::formatMinutes($minutes),
                        self::formatMinutes($remaining)
                    ),
                    false,
                    ERROR
                );
            }
        }
    }

    /**
     * Hook pre_item_update em TicketTask: bloqueia aumento de tempo se block_on_overrun = 1 e exceder saldo.
     *
     * @param object $item
     */
    public static function preItemUpdateTicketTask($item): void
    {
        if (!is_object($item) || !isset($item->input) || !is_array($item->input)) {
            return;
        }
        if (PluginContracthoursCompat::isCliOrCron()) {
            return;
        }

        $taskId     = method_exists($item, 'getID') ? (int) $item->getID() : (int) ($item->fields['id'] ?? 0);
        $ticketsId  = (int) ($item->input['tickets_id'] ?? $item->fields['tickets_id'] ?? 0);
        $actiontime = array_key_exists('actiontime', $item->input)
            ? (int) $item->input['actiontime']
            : (int) ($item->fields['actiontime'] ?? 0);
        $newMinutes = self::secondsToMinutes($actiontime);

        if ($taskId <= 0 || $ticketsId <= 0) {
            return;
        }

        $resolved = self::resolveContractForTicket($ticketsId);
        if ($resolved === null || empty($resolved['pool']['block_on_overrun'])) {
            return;
        }

        $contractsId = (int) $resolved['contracts_id'];
        $debitedMap  = self::getNetDebitedMinutesByContractForTask($taskId);
        $prevDebited = (int) ($debitedMap[$contractsId] ?? 0);
        $additional  = $newMinutes - $prevDebited;

        if ($additional <= 0) {
            return;
        }

        $summary = self::getPoolSummary($contractsId);
        if ($summary === null) {
            return;
        }

        $remaining = (int) $summary['remaining_minutes'];
        if (($remaining - $additional) < 0) {
            $item->input = false;
            if (class_exists('Session') && method_exists('Session', 'addMessageAfterRedirect')) {
                Session::addMessageAfterRedirect(
                    sprintf(
                        __('Bloqueado: o acréscimo de tempo (%s) excede o saldo disponível (%s) no banco de horas do contrato.', 'contracthours'),
                        self::formatMinutes($additional),
                        self::formatMinutes($remaining)
                    ),
                    false,
                    ERROR
                );
            }
        }
    }

    /**
     * Hook item_add em TicketTask: debita round(actiontime / 60) minutos no contrato resolvido.
     *
     * @param object $item
     */
    public static function itemAddTicketTask($item): void
    {
        if (!is_object($item)) {
            return;
        }

        $taskId = method_exists($item, 'getID') ? (int) $item->getID() : (int) ($item->fields['id'] ?? 0);
        $fields = (isset($item->fields) && is_array($item->fields)) ? $item->fields : [];
        $input  = (isset($item->input) && is_array($item->input)) ? $item->input : [];

        $ticketsId  = (int) ($fields['tickets_id'] ?? $input['tickets_id'] ?? 0);
        $actiontime = (int) ($fields['actiontime'] ?? $input['actiontime'] ?? 0);
        $minutes    = self::secondsToMinutes($actiontime);

        if ($taskId <= 0 || $ticketsId <= 0 || $minutes <= 0) {
            return;
        }

        // Evita débito duplicado se o hook for acionado mais de uma vez para a mesma tarefa.
        $existingMap = self::getNetDebitedMinutesByContractForTask($taskId);
        if (!empty($existingMap)) {
            return;
        }

        $resolved = self::resolveContractForTicket($ticketsId);
        if ($resolved === null) {
            return;
        }

        $contractsId = (int) $resolved['contracts_id'];
        $entitiesId  = (int) ($fields['entities_id'] ?? self::getTicketEntityId($ticketsId));
        $usersId     = (int) ($fields['users_id'] ?? 0);
        if ($usersId <= 0 && class_exists('Session') && method_exists('Session', 'getLoginUserID')) {
            $usersId = (int) Session::getLoginUserID(true);
        }

        self::recordLedgerTransition(
            $contractsId,
            $ticketsId,
            $taskId,
            $entitiesId,
            $usersId,
            -$minutes,
            self::ENTRY_DEBIT,
            sprintf('Ticket #%d / Task #%d', $ticketsId, $taskId)
        );
    }

    /**
     * Hook item_update em TicketTask: calcula delta incremental e lança débito ou estorno.
     *
     * @param object $item
     */
    public static function itemUpdateTicketTask($item): void
    {
        if (!is_object($item)) {
            return;
        }

        $taskId = method_exists($item, 'getID') ? (int) $item->getID() : (int) ($item->fields['id'] ?? 0);
        $fields = (isset($item->fields) && is_array($item->fields)) ? $item->fields : [];
        $input  = (isset($item->input) && is_array($item->input)) ? $item->input : [];

        if ($taskId <= 0) {
            return;
        }

        $ticketsId  = (int) ($fields['tickets_id'] ?? $input['tickets_id'] ?? 0);
        $actiontime = (int) ($fields['actiontime'] ?? $input['actiontime'] ?? 0);
        $newMinutes = self::secondsToMinutes($actiontime);

        if ($ticketsId <= 0) {
            return;
        }

        $entitiesId = (int) ($fields['entities_id'] ?? self::getTicketEntityId($ticketsId));
        $usersId    = (int) ($fields['users_id'] ?? 0);
        if ($usersId <= 0 && class_exists('Session') && method_exists('Session', 'getLoginUserID')) {
            $usersId = (int) Session::getLoginUserID(true);
        }

        $resolved           = self::resolveContractForTicket($ticketsId);
        $resolvedContractId = $resolved !== null ? (int) $resolved['contracts_id'] : 0;
        $debitedByContract  = self::getNetDebitedMinutesByContractForTask($taskId);

        // Estorna débitos anteriores caso o contrato resolvido do chamado tenha mudado ou não haja contrato ativo.
        foreach ($debitedByContract as $prevCid => $prevMinutes) {
            if ($prevMinutes === 0) {
                continue;
            }
            if ((int) $prevCid !== $resolvedContractId) {
                self::recordLedgerTransition(
                    (int) $prevCid,
                    $ticketsId,
                    $taskId,
                    $entitiesId,
                    $usersId,
                    $prevMinutes,
                    self::ENTRY_REFUND,
                    sprintf('Refund Ticket #%d / Task #%d', $ticketsId, $taskId)
                );
            }
        }

        if ($resolvedContractId <= 0) {
            return;
        }

        $alreadyDebitedOnResolved = (int) ($debitedByContract[$resolvedContractId] ?? 0);
        $diff                     = $newMinutes - $alreadyDebitedOnResolved;

        if ($diff > 0) {
            self::recordLedgerTransition(
                $resolvedContractId,
                $ticketsId,
                $taskId,
                $entitiesId,
                $usersId,
                -$diff,
                self::ENTRY_DEBIT,
                sprintf('Ticket #%d / Task #%d', $ticketsId, $taskId)
            );
        } elseif ($diff < 0) {
            self::recordLedgerTransition(
                $resolvedContractId,
                $ticketsId,
                $taskId,
                $entitiesId,
                $usersId,
                abs($diff),
                self::ENTRY_REFUND,
                sprintf('Refund Ticket #%d / Task #%d', $ticketsId, $taskId)
            );
        }
    }

    /**
     * Hook item_delete em TicketTask: estorna integralmente o saldo líquido debitado pela tarefa.
     *
     * @param object $item
     */
    public static function itemDeleteTicketTask($item): void
    {
        self::refundTaskAll($item);
    }

    /**
     * Hook item_purge em TicketTask: garante estorno se a tarefa for expurgada diretamente.
     *
     * @param object $item
     */
    public static function itemPurgeTicketTask($item): void
    {
        self::refundTaskAll($item);
    }

    /**
     * Hook item_purge em Ticket: remove vínculos e lançamentos órfãos do chamado expurgado.
     *
     * @param object $item
     */
    public static function itemPurgeTicket($item): void
    {
        global $DB;

        if (!is_object($item) || !isset($DB)) {
            return;
        }
        $ticketsId = method_exists($item, 'getID') ? (int) $item->getID() : (int) ($item->fields['id'] ?? 0);
        if ($ticketsId <= 0) {
            return;
        }

        if ($DB->tableExists(self::TABLE_LINKS)) {
            $DB->delete(self::TABLE_LINKS, ['tickets_id' => $ticketsId]);
        }
        if ($DB->tableExists(self::TABLE_LEDGER)) {
            $DB->delete(self::TABLE_LEDGER, ['tickets_id' => $ticketsId]);
        }
    }

    /**
     * Hook item_purge em Contract: remove pool, razão, vínculos e padrões de entidade do contrato expurgado.
     *
     * @param object $item
     */
    public static function itemPurgeContract($item): void
    {
        global $DB;

        if (!is_object($item) || !isset($DB)) {
            return;
        }
        $contractsId = method_exists($item, 'getID') ? (int) $item->getID() : (int) ($item->fields['id'] ?? 0);
        if ($contractsId <= 0) {
            return;
        }

        if ($DB->tableExists(self::TABLE_LINKS)) {
            $DB->delete(self::TABLE_LINKS, ['contracts_id' => $contractsId]);
        }
        if ($DB->tableExists(self::TABLE_LEDGER)) {
            $DB->delete(self::TABLE_LEDGER, ['contracts_id' => $contractsId]);
        }
        if ($DB->tableExists(self::TABLE_POOLS)) {
            $DB->delete(self::TABLE_POOLS, ['contracts_id' => $contractsId]);
        }

        PluginContracthoursConfig::removeContractFromEntityDefaults($contractsId);
    }

    /**
     * Registra um ajuste manual de crédito (+) ou débito (-) no razão do contrato.
     */
    public static function recordManualAdjustment(
        int $contracts_id,
        int $minutes_delta,
        string $note = '',
        int $users_id = 0
    ): bool {
        if ($contracts_id <= 0 || $minutes_delta === 0) {
            return false;
        }

        $pool = self::getPoolByContract($contracts_id);
        if ($pool === null) {
            return false;
        }

        if ($users_id <= 0 && class_exists('Session') && method_exists('Session', 'getLoginUserID')) {
            $users_id = (int) Session::getLoginUserID(true);
        }

        $cleanNote = trim($note);
        if ($cleanNote === '') {
            $cleanNote = $minutes_delta > 0 ? 'Manual credit' : 'Manual debit';
        }
        $cleanNote = substr($cleanNote, 0, 255);

        return self::recordLedgerTransition(
            $contracts_id,
            0,
            0,
            (int) $pool['entities_id'],
            max(0, $users_id),
            $minutes_delta,
            self::ENTRY_MANUAL,
            $cleanNote
        );
    }

    /**
     * Retorna o total líquido de minutos debitados por contrato para uma tarefa específica.
     *
     * @return array<int, int> Mapa contracts_id => minutos líquidos debitados (> 0 significa que há débito ativo)
     */
    public static function getNetDebitedMinutesByContractForTask(int $tickettasks_id): array
    {
        global $DB;

        if ($tickettasks_id <= 0 || !isset($DB) || !$DB->tableExists(self::TABLE_LEDGER)) {
            return [];
        }

        $it = $DB->request([
            'SELECT' => ['contracts_id', 'minutes_delta'],
            'FROM'   => self::TABLE_LEDGER,
            'WHERE'  => ['tickettasks_id' => $tickettasks_id],
        ]);

        $sums = [];
        foreach ($it as $row) {
            $cid = (int) ($row['contracts_id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            if (!isset($sums[$cid])) {
                $sums[$cid] = 0;
            }
            // No razão, débito é negativo e estorno é positivo; líquido debitado = -SUM(minutes_delta).
            $sums[$cid] -= (int) ($row['minutes_delta'] ?? 0);
        }

        foreach ($sums as $cid => $val) {
            if ($val === 0) {
                unset($sums[$cid]);
            }
        }

        return $sums;
    }

    /**
     * Estorna integralmente os minutos ainda debitados de uma tarefa (idempotente).
     *
     * @param object $item
     */
    private static function refundTaskAll($item): void
    {
        if (!is_object($item)) {
            return;
        }

        $taskId = method_exists($item, 'getID') ? (int) $item->getID() : (int) ($item->fields['id'] ?? 0);
        if ($taskId <= 0) {
            return;
        }

        $debitedByContract = self::getNetDebitedMinutesByContractForTask($taskId);
        if (empty($debitedByContract)) {
            return;
        }

        $fields    = (isset($item->fields) && is_array($item->fields)) ? $item->fields : [];
        $ticketsId = (int) ($fields['tickets_id'] ?? 0);
        $entities  = (int) ($fields['entities_id'] ?? ($ticketsId > 0 ? self::getTicketEntityId($ticketsId) : 0));
        $usersId   = 0;
        if (class_exists('Session') && method_exists('Session', 'getLoginUserID')) {
            $usersId = (int) Session::getLoginUserID(true);
        }

        foreach ($debitedByContract as $cid => $debitedMinutes) {
            if ($debitedMinutes === 0) {
                continue;
            }
            self::recordLedgerTransition(
                (int) $cid,
                $ticketsId,
                $taskId,
                $entities,
                max(0, $usersId),
                $debitedMinutes,
                self::ENTRY_REFUND,
                sprintf('Refund Ticket #%d / Task #%d', $ticketsId, $taskId)
            );
        }
    }

    /**
     * Grava lançamento no razão e dispara alerta de sessão ao cruzar limiar ou saldo negativo.
     */
    private static function recordLedgerTransition(
        int $contracts_id,
        int $tickets_id,
        int $tickettasks_id,
        int $entities_id,
        int $users_id,
        int $minutes_delta,
        string $entry_type,
        string $note
    ): bool {
        global $DB;

        if ($contracts_id <= 0 || $minutes_delta === 0 || !isset($DB) || !$DB->tableExists(self::TABLE_LEDGER)) {
            return false;
        }

        $before = self::getPoolSummary($contracts_id);

        $ok = (bool) $DB->insert(
            self::TABLE_LEDGER,
            PluginContracthoursCompat::dbValues([
                'contracts_id'   => $contracts_id,
                'tickets_id'     => max(0, $tickets_id),
                'tickettasks_id' => max(0, $tickettasks_id),
                'entities_id'    => max(0, $entities_id),
                'users_id'       => max(0, $users_id),
                'minutes_delta'  => $minutes_delta,
                'entry_type'     => $entry_type,
                'note'           => substr($note, 0, 255),
                'date_creation'  => date('Y-m-d H:i:s'),
            ])
        );

        if ($ok && $minutes_delta < 0 && $before !== null) {
            $after = self::getPoolSummary($contracts_id);
            if ($after !== null) {
                self::maybeNotifyThresholdTransition($contracts_id, $before, $after);
            }
        }

        return $ok;
    }

    /**
     * Emite alerta na sessão interativa uma vez na transição para limiar ou estouro.
     */
    private static function maybeNotifyThresholdTransition(int $contracts_id, array $before, array $after): void
    {
        if (PluginContracthoursCompat::isCliOrCron()) {
            return;
        }
        if (!class_exists('Session') || !method_exists('Session', 'addMessageAfterRedirect')) {
            return;
        }

        $wasOverrun = !empty($before['is_overrun']);
        $isOverrun  = !empty($after['is_overrun']);

        if (!$wasOverrun && $isOverrun) {
            Session::addMessageAfterRedirect(
                sprintf(
                    __('Atenção: o banco de horas do contrato #%d atingiu saldo negativo (%s).', 'contracthours'),
                    $contracts_id,
                    self::formatMinutes((int) $after['remaining_minutes'])
                ),
                true,
                ERROR
            );
            return;
        }

        $wasThreshold = !empty($before['is_threshold_reached']);
        $isThreshold  = !empty($after['is_threshold_reached']);

        if (!$wasThreshold && $isThreshold) {
            Session::addMessageAfterRedirect(
                sprintf(
                    __('Alerta: o banco de horas do contrato #%d atingiu %d%% de consumo (%s restantes).', 'contracthours'),
                    $contracts_id,
                    (int) $after['consumption_pct'],
                    self::formatMinutes((int) $after['remaining_minutes'])
                ),
                true,
                WARNING
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function queryLedger(array $where, int $limit): array
    {
        global $DB;

        if (!isset($DB) || !$DB->tableExists(self::TABLE_LEDGER)) {
            return [];
        }

        $it = $DB->request([
            'FROM'  => self::TABLE_LEDGER,
            'WHERE' => $where,
            'ORDER' => ['id DESC'],
            'LIMIT' => max(1, $limit),
        ]);

        $rows = [];
        foreach ($it as $row) {
            $rows[] = [
                'id'             => (int) ($row['id'] ?? 0),
                'contracts_id'   => (int) ($row['contracts_id'] ?? 0),
                'tickets_id'     => (int) ($row['tickets_id'] ?? 0),
                'tickettasks_id' => (int) ($row['tickettasks_id'] ?? 0),
                'entities_id'    => (int) ($row['entities_id'] ?? 0),
                'users_id'       => (int) ($row['users_id'] ?? 0),
                'minutes_delta'  => (int) ($row['minutes_delta'] ?? 0),
                'entry_type'     => (string) ($row['entry_type'] ?? self::ENTRY_DEBIT),
                'note'           => PluginContracthoursCompat::fromCore((string) ($row['note'] ?? '')),
                'date_creation'  => (string) ($row['date_creation'] ?? ''),
            ];
        }

        return $rows;
    }

    private static function getContractRow(int $contracts_id): ?array
    {
        global $DB;

        if ($contracts_id <= 0 || !isset($DB) || !$DB->tableExists('glpi_contracts')) {
            return null;
        }

        $it = $DB->request([
            'SELECT' => ['id', 'entities_id', 'name', 'num', 'is_deleted'],
            'FROM'   => 'glpi_contracts',
            'WHERE'  => ['id' => $contracts_id],
            'LIMIT'  => 1,
        ]);
        foreach ($it as $row) {
            return $row;
        }

        return null;
    }

    private static function getTicketEntityId(int $tickets_id): int
    {
        global $DB;

        if ($tickets_id <= 0 || !isset($DB) || !$DB->tableExists('glpi_tickets')) {
            return 0;
        }

        $it = $DB->request([
            'SELECT' => ['entities_id'],
            'FROM'   => 'glpi_tickets',
            'WHERE'  => ['id' => $tickets_id],
            'LIMIT'  => 1,
        ]);
        foreach ($it as $row) {
            return (int) ($row['entities_id'] ?? 0);
        }

        return 0;
    }

    private static function normalizePoolRow(array $row): array
    {
        return [
            'id'                  => (int) ($row['id'] ?? 0),
            'contracts_id'        => (int) ($row['contracts_id'] ?? 0),
            'entities_id'         => (int) ($row['entities_id'] ?? 0),
            'contracted_minutes'  => (int) ($row['contracted_minutes'] ?? 0),
            'alert_threshold_pct' => (int) ($row['alert_threshold_pct'] ?? 80),
            'block_on_overrun'    => !empty($row['block_on_overrun']) ? 1 : 0,
            'allow_rollover'      => !empty($row['allow_rollover']) ? 1 : 0,
            'hourly_rate_cents'   => (int) ($row['hourly_rate_cents'] ?? 0),
            'billing_cycle'       => (string) ($row['billing_cycle'] ?? 'total'),
            'is_active'           => !empty($row['is_active']) ? 1 : 0,
            'date_creation'       => (string) ($row['date_creation'] ?? ''),
            'date_mod'            => (string) ($row['date_mod'] ?? ''),
        ];
    }
}
