<?php

/**
 * ------------------------------------------------------------------------
 * Contract Hours - Aba em Contratos (Contract) e Chamados (Ticket)
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

require_once __DIR__ . '/compat.class.php';
require_once __DIR__ . '/config.class.php';
require_once __DIR__ . '/pool.class.php';

interface PluginContracthoursTabConstants
{
    public const TAB_NUM = 1;
}

trait PluginContracthoursTabBody
{
    public static function getTypeName($nb = 0): string
    {
        return __('Banco de Horas', 'contracthours');
    }

    public static function canView(): bool
    {
        return (bool) (Session::haveRight('contract', READ) || Session::haveRight('ticket', READ));
    }

    public static function isAllowedInCurrentInterface(): bool
    {
        if (method_exists('Session', 'getCurrentInterface') && Session::getCurrentInterface() === 'central') {
            return true;
        }
        $cfg = PluginContracthoursConfig::getConfig();
        return !empty($cfg['show_tab_selfservice']);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (self::canAccessContract($item, $withtemplate)) {
            $summary = PluginContracthoursPool::getPoolSummary((int) $item->getID());
            $count   = $summary !== null ? (int) $summary['ledger_count'] : 0;
            return self::createTabEntry(__('Banco de Horas', 'contracthours'), $count);
        }

        if (self::canAccessTicket($item, $withtemplate)) {
            $entries = PluginContracthoursPool::getLedgerForTicket((int) $item->getID(), 50);
            return self::createTabEntry(__('Banco de Horas', 'contracthours'), count($entries));
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (self::canAccessContract($item, $withtemplate)) {
            self::renderContractTab($item);
            return true;
        }

        if (self::canAccessTicket($item, $withtemplate)) {
            self::renderTicketTab($item);
            return true;
        }

        return false;
    }

    private static function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function canAccessContract(CommonGLPI $item, $withtemplate = 0): bool
    {
        if ($withtemplate || !($item instanceof Contract)) {
            return false;
        }
        $contractsId = (int) $item->getID();
        if ($contractsId <= 0) {
            return false;
        }
        if ((int) Session::getLoginUserID(true) <= 0) {
            return false;
        }
        if (!Session::haveRight('contract', READ)) {
            return false;
        }
        if (!$item->canViewItem()) {
            return false;
        }
        $entityId = method_exists($item, 'getEntityID') ? (int) $item->getEntityID() : (int) ($item->fields['entities_id'] ?? 0);
        if (method_exists('Session', 'haveAccessToEntity') && !Session::haveAccessToEntity($entityId)) {
            return false;
        }
        return true;
    }

    private static function canAccessTicket(CommonGLPI $item, $withtemplate = 0): bool
    {
        if ($withtemplate || !($item instanceof Ticket)) {
            return false;
        }
        $ticketsId = (int) $item->getID();
        if ($ticketsId <= 0) {
            return false;
        }
        if ((int) Session::getLoginUserID(true) <= 0) {
            return false;
        }
        if (!Session::haveRight('ticket', READ)) {
            return false;
        }
        if (!$item->canViewItem()) {
            return false;
        }
        $entityId = method_exists($item, 'getEntityID') ? (int) $item->getEntityID() : (int) ($item->fields['entities_id'] ?? 0);
        if (method_exists('Session', 'haveAccessToEntity') && !Session::haveAccessToEntity($entityId)) {
            return false;
        }
        return self::isAllowedInCurrentInterface();
    }

    private static function renderContractTab(CommonGLPI $item): void
    {
        $contractsId = (int) $item->getID();
        $summary     = PluginContracthoursPool::getPoolSummary($contractsId);
        $cfg         = PluginContracthoursConfig::getConfig();
        $canUpdate   = Session::haveRight('contract', UPDATE)
            && (!method_exists($item, 'canUpdateItem') || $item->canUpdateItem());
        $formAction  = PluginContracthoursCompat::webPath() . '/front/pool.form.php';

        $isActive       = $summary !== null ? !empty($summary['is_active']) : false;
        $contractedMins = $summary !== null ? (int) $summary['contracted_minutes'] : 0;
        $consumedMins   = $summary !== null ? (int) $summary['consumed_minutes'] : 0;
        $remainingMins  = $summary !== null ? (int) $summary['remaining_minutes'] : 0;
        $pct            = $summary !== null ? (int) $summary['consumption_pct'] : 0;
        $threshold      = $summary !== null ? (int) $summary['alert_threshold_pct'] : (int) $cfg['default_alert_threshold_pct'];
        $blockOverrun   = $summary !== null ? !empty($summary['block_on_overrun']) : false;
        $allowRollover  = $summary !== null ? !empty($summary['allow_rollover']) : false;
        $rateCents      = $summary !== null ? (int) $summary['hourly_rate_cents'] : 0;
        $cycle          = $summary !== null ? (string) $summary['billing_cycle'] : 'total';

        $contractedH = (int) floor($contractedMins / 60);
        $contractedM = $contractedMins % 60;
        $disabled    = $canUpdate ? '' : ' disabled';

        echo '<div class="contracthours-tab">';

        // Resumo de saldo
        echo '<div class="card mb-3">';
        echo '<div class="card-header d-flex align-items-center justify-content-between">';
        echo '<h3 class="card-title mb-0"><i class="ti ti-clock-hour-4 me-2" aria-hidden="true"></i>'
            . self::escapeHtml(__('Saldo do Banco de Horas', 'contracthours')) . '</h3>';
        echo self::renderStatusBadge($summary);
        echo '</div>';
        echo '<div class="card-body">';

        if ($summary === null) {
            echo '<p class="text-muted mb-0">'
                . self::escapeHtml(__('Este contrato ainda não possui um banco de horas configurado. Utilize o formulário abaixo para ativar e definir a franquia.', 'contracthours'))
                . '</p>';
        } else {
            self::renderSummaryGrid($summary);
        }

        echo '</div></div>';

        // Formulário de configuração do pool
        echo '<div class="card mb-3">';
        echo '<div class="card-header"><h4 class="card-title mb-0">'
            . self::escapeHtml(__('Configuração do Pool de Horas', 'contracthours')) . '</h4></div>';
        echo '<div class="card-body">';
        echo '<form method="post" action="' . self::escapeHtml($formAction) . '">';
        echo '<input type="hidden" name="action" value="save_pool">';
        echo '<input type="hidden" name="contracts_id" value="' . self::escapeHtml((string) $contractsId) . '">';

        echo '<div class="row g-3">';
        echo '<div class="col-md-3">';
        echo '<label class="form-label" for="ch-hours">' . self::escapeHtml(__('Franquia (Horas)', 'contracthours')) . '</label>';
        echo '<input type="number" class="form-control" id="ch-hours" name="contracted_hours" min="0" max="100000" value="'
            . self::escapeHtml((string) $contractedH) . '"' . $disabled . '>';
        echo '</div>';

        echo '<div class="col-md-3">';
        echo '<label class="form-label" for="ch-mins">' . self::escapeHtml(__('Minutos adicionais', 'contracthours')) . '</label>';
        echo '<input type="number" class="form-control" id="ch-mins" name="contracted_minutes_part" min="0" max="59" value="'
            . self::escapeHtml((string) $contractedM) . '"' . $disabled . '>';
        echo '</div>';

        echo '<div class="col-md-3">';
        echo '<label class="form-label" for="ch-threshold">' . self::escapeHtml(__('Limiar de alerta (%)', 'contracthours')) . '</label>';
        echo '<input type="number" class="form-control" id="ch-threshold" name="alert_threshold_pct" min="1" max="100" value="'
            . self::escapeHtml((string) $threshold) . '"' . $disabled . '>';
        echo '</div>';

        echo '<div class="col-md-3">';
        echo '<label class="form-label" for="ch-rate">' . self::escapeHtml(__('Valor hora', 'contracthours')) . '</label>';
        echo '<input type="text" class="form-control" id="ch-rate" name="hourly_rate" value="'
            . self::escapeHtml(PluginContracthoursPool::formatCents($rateCents)) . '"' . $disabled . '>';
        echo '</div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label" for="ch-cycle">' . self::escapeHtml(__('Ciclo de faturamento', 'contracthours')) . '</label>';
        echo '<select class="form-select" id="ch-cycle" name="billing_cycle"' . $disabled . '>';
        echo '<option value="total"' . ($cycle === 'total' ? ' selected' : '') . '>'
            . self::escapeHtml(__('Total do contrato', 'contracthours')) . '</option>';
        echo '<option value="monthly"' . ($cycle === 'monthly' ? ' selected' : '') . '>'
            . self::escapeHtml(__('Mensal', 'contracthours')) . '</option>';
        echo '</select>';
        echo '</div>';

        echo '<div class="col-md-8 d-flex align-items-end gap-4 flex-wrap">';
        echo '<div class="form-check form-switch">';
        echo '<input type="hidden" name="is_active" value="0">';
        echo '<input class="form-check-input" type="checkbox" id="ch-active" name="is_active" value="1"'
            . (($summary === null || $isActive) ? ' checked' : '') . $disabled . '>';
        echo '<label class="form-check-label" for="ch-active">' . self::escapeHtml(__('Pool ativo', 'contracthours')) . '</label>';
        echo '</div>';

        echo '<div class="form-check form-switch">';
        echo '<input type="hidden" name="block_on_overrun" value="0">';
        echo '<input class="form-check-input" type="checkbox" id="ch-block" name="block_on_overrun" value="1"'
            . ($blockOverrun ? ' checked' : '') . $disabled . '>';
        echo '<label class="form-check-label" for="ch-block">' . self::escapeHtml(__('Bloquear tarefas ao exceder saldo', 'contracthours')) . '</label>';
        echo '</div>';

        echo '<div class="form-check form-switch">';
        echo '<input type="hidden" name="allow_rollover" value="0">';
        echo '<input class="form-check-input" type="checkbox" id="ch-rollover" name="allow_rollover" value="1"'
            . ($allowRollover ? ' checked' : '') . $disabled . '>';
        echo '<label class="form-check-label" for="ch-rollover">' . self::escapeHtml(__('Permitir acúmulo (rollover)', 'contracthours')) . '</label>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        if ($canUpdate) {
            echo '<div class="mt-3">';
            echo '<button type="submit" class="btn btn-primary" name="submit_pool" value="1">'
                . '<i class="ti ti-device-floppy me-1" aria-hidden="true"></i>'
                . self::escapeHtml(__('Salvar configuração do pool', 'contracthours'))
                . '</button>';
            echo '</div>';
        }

        Html::closeForm();
        echo '</div></div>';

        // Formulário de ajuste manual (somente se o pool já existir e usuário puder editar)
        if ($summary !== null && $canUpdate) {
            echo '<div class="card mb-3">';
            echo '<div class="card-header"><h4 class="card-title mb-0">'
                . self::escapeHtml(__('Ajuste Manual de Saldo', 'contracthours')) . '</h4></div>';
            echo '<div class="card-body">';
            echo '<form method="post" action="' . self::escapeHtml($formAction) . '">';
            echo '<input type="hidden" name="action" value="manual_adjustment">';
            echo '<input type="hidden" name="contracts_id" value="' . self::escapeHtml((string) $contractsId) . '">';

            echo '<div class="row g-3 align-items-end">';
            echo '<div class="col-md-3">';
            echo '<label class="form-label" for="ch-adj-type">' . self::escapeHtml(__('Tipo de ajuste', 'contracthours')) . '</label>';
            echo '<select class="form-select" id="ch-adj-type" name="adjustment_type">';
            echo '<option value="credit">' . self::escapeHtml(__('Crédito (+)', 'contracthours')) . '</option>';
            echo '<option value="debit">' . self::escapeHtml(__('Débito (-)', 'contracthours')) . '</option>';
            echo '</select>';
            echo '</div>';

            echo '<div class="col-md-2">';
            echo '<label class="form-label" for="ch-adj-h">' . self::escapeHtml(__('Horas', 'contracthours')) . '</label>';
            echo '<input type="number" class="form-control" id="ch-adj-h" name="adjust_hours" min="0" max="10000" value="0">';
            echo '</div>';

            echo '<div class="col-md-2">';
            echo '<label class="form-label" for="ch-adj-m">' . self::escapeHtml(__('Minutos', 'contracthours')) . '</label>';
            echo '<input type="number" class="form-control" id="ch-adj-m" name="adjust_minutes" min="0" max="59" value="30">';
            echo '</div>';

            echo '<div class="col-md-3">';
            echo '<label class="form-label" for="ch-adj-note">' . self::escapeHtml(__('Justificativa', 'contracthours')) . '</label>';
            echo '<input type="text" class="form-control" id="ch-adj-note" name="note" maxlength="255" placeholder="'
                . self::escapeHtml(__('Ex.: Bonificação ou ajuste mensal', 'contracthours')) . '">';
            echo '</div>';

            echo '<div class="col-md-2">';
            echo '<button type="submit" class="btn btn-outline-primary w-100" name="submit_adjustment" value="1">'
                . self::escapeHtml(__('Lançar ajuste', 'contracthours')) . '</button>';
            echo '</div>';
            echo '</div>';

            Html::closeForm();
            echo '</div></div>';
        }

        // Extrato do razão do contrato
        $entries = PluginContracthoursPool::getLedgerForContract($contractsId, 100);
        echo '<div class="card">';
        echo '<div class="card-header"><h4 class="card-title mb-0">'
            . self::escapeHtml(__('Extrato do Razão (Ledger)', 'contracthours')) . '</h4></div>';
        echo '<div class="card-body p-0">';
        self::renderLedgerTable($entries, false);
        echo '</div></div>';

        echo '</div>';
    }

    private static function renderTicketTab(CommonGLPI $item): void
    {
        $ticketsId    = (int) $item->getID();
        $ticketEntity = method_exists($item, 'getEntityID') ? (int) $item->getEntityID() : (int) ($item->fields['entities_id'] ?? 0);
        $resolved     = PluginContracthoursPool::resolveContractForTicket($ticketsId);
        $explicitCid  = PluginContracthoursPool::getTicketContractLink($ticketsId);
        $canUpdate    = Session::haveRight('ticket', UPDATE)
            && (!method_exists($item, 'canUpdateItem') || $item->canUpdateItem())
            && method_exists('Session', 'getCurrentInterface')
            && Session::getCurrentInterface() === 'central';
        $formAction   = PluginContracthoursCompat::webPath() . '/front/pool.form.php';

        echo '<div class="contracthours-tab">';

        echo '<div class="card mb-3">';
        echo '<div class="card-header d-flex align-items-center justify-content-between">';
        echo '<h3 class="card-title mb-0"><i class="ti ti-clock-hour-4 me-2" aria-hidden="true"></i>'
            . self::escapeHtml(__('Banco de Horas do Chamado', 'contracthours')) . '</h3>';

        if ($resolved !== null) {
            $summary = PluginContracthoursPool::getPoolSummary((int) $resolved['contracts_id']);
            echo self::renderStatusBadge($summary);
        } else {
            $summary = null;
            echo '<span class="badge bg-secondary">' . self::escapeHtml(__('Sem contrato ativo', 'contracthours')) . '</span>';
        }
        echo '</div>';
        echo '<div class="card-body">';

        if ($resolved !== null && $summary !== null) {
            $contractLabel = self::resolveContractDisplayName((int) $resolved['contracts_id']);
            $sourceLabel   = self::translateResolutionSource((string) $resolved['source']);
            echo '<div class="mb-3">';
            echo '<strong>' . self::escapeHtml(__('Contrato aplicado:', 'contracthours')) . '</strong> '
                . self::escapeHtml($contractLabel)
                . ' <span class="badge bg-info-lt ms-2">' . self::escapeHtml($sourceLabel) . '</span>';
            echo '</div>';
            self::renderSummaryGrid($summary);
        } else {
            echo '<p class="text-muted mb-0">'
                . self::escapeHtml(__('Nenhum banco de horas ativo foi encontrado para este chamado pelos critérios automáticos (vínculo direto, ativo associado ou contrato padrão da entidade).', 'contracthours'))
                . '</p>';
        }

        echo '</div></div>';

        if ($canUpdate) {
            $availablePools = PluginContracthoursPool::getAccessiblePoolsForTicket($ticketEntity, $explicitCid);
            echo '<div class="card mb-3">';
            echo '<div class="card-header"><h4 class="card-title mb-0">'
                . self::escapeHtml(__('Vínculo Explícito de Contrato', 'contracthours')) . '</h4></div>';
            echo '<div class="card-body">';
            echo '<form method="post" action="' . self::escapeHtml($formAction) . '" class="row g-3 align-items-end">';
            echo '<input type="hidden" name="action" value="set_ticket_link">';
            echo '<input type="hidden" name="tickets_id" value="' . self::escapeHtml((string) $ticketsId) . '">';

            echo '<div class="col-md-8">';
            echo '<label class="form-label" for="ch-ticket-contract">'
                . self::escapeHtml(__('Contrato vinculado ao chamado', 'contracthours')) . '</label>';
            echo '<select class="form-select" id="ch-ticket-contract" name="contracts_id">';
            echo '<option value="0"' . ($explicitCid === 0 ? ' selected' : '') . '>'
                . self::escapeHtml(__('Automático (resolução padrão em 4 níveis)', 'contracthours')) . '</option>';

            foreach ($availablePools as $poolOption) {
                $optCid   = (int) $poolOption['contracts_id'];
                $optTitle = trim((string) ($poolOption['contract_name'] ?? ''));
                if ($optTitle === '') {
                    $optTitle = '#' . $optCid;
                }
                $optNum   = trim((string) ($poolOption['contract_num'] ?? ''));
                $optLabel = $optNum !== '' ? ($optTitle . ' (' . $optNum . ')') : $optTitle;
                $optLabel .= ' — ' . __('Saldo:', 'contracthours') . ' '
                    . PluginContracthoursPool::formatMinutes((int) $poolOption['remaining_minutes']);
                echo '<option value="' . self::escapeHtml((string) $optCid) . '"'
                    . ($explicitCid === $optCid ? ' selected' : '') . '>'
                    . self::escapeHtml($optLabel) . '</option>';
            }

            echo '</select>';
            echo '</div>';

            echo '<div class="col-md-4">';
            echo '<button type="submit" class="btn btn-primary" name="submit_ticket_link" value="1">'
                . self::escapeHtml(__('Aplicar contrato', 'contracthours')) . '</button>';
            echo '</div>';

            Html::closeForm();
            echo '</div></div>';
        }

        $entries = PluginContracthoursPool::getLedgerForTicket($ticketsId, 100);
        echo '<div class="card">';
        echo '<div class="card-header"><h4 class="card-title mb-0">'
            . self::escapeHtml(__('Lançamentos deste Chamado', 'contracthours')) . '</h4></div>';
        echo '<div class="card-body p-0">';
        self::renderLedgerTable($entries, true);
        echo '</div></div>';

        echo '</div>';
    }

    private static function renderSummaryGrid(array $summary): void
    {
        $contracted = PluginContracthoursPool::formatMinutes((int) $summary['contracted_minutes']);
        $consumed   = PluginContracthoursPool::formatMinutes((int) $summary['consumed_minutes']);
        $remaining  = PluginContracthoursPool::formatMinutes((int) $summary['remaining_minutes']);
        $pct        = (int) $summary['consumption_pct'];
        $barWidth   = max(0, min(100, $pct));

        $barClass = 'bg-success';
        if (!empty($summary['is_overrun'])) {
            $barClass = 'bg-danger';
        } elseif (!empty($summary['is_threshold_reached'])) {
            $barClass = 'bg-warning';
        }

        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-3"><div class="contracthours-kpi"><span class="contracthours-kpi-label">'
            . self::escapeHtml(__('Contratado', 'contracthours')) . '</span><strong class="contracthours-kpi-value">'
            . self::escapeHtml($contracted) . '</strong></div></div>';
        echo '<div class="col-md-3"><div class="contracthours-kpi"><span class="contracthours-kpi-label">'
            . self::escapeHtml(__('Consumido', 'contracthours')) . '</span><strong class="contracthours-kpi-value">'
            . self::escapeHtml($consumed) . '</strong></div></div>';
        echo '<div class="col-md-3"><div class="contracthours-kpi"><span class="contracthours-kpi-label">'
            . self::escapeHtml(__('Saldo restante', 'contracthours')) . '</span><strong class="contracthours-kpi-value">'
            . self::escapeHtml($remaining) . '</strong></div></div>';
        echo '<div class="col-md-3"><div class="contracthours-kpi"><span class="contracthours-kpi-label">'
            . self::escapeHtml(__('Consumo', 'contracthours')) . '</span><strong class="contracthours-kpi-value">'
            . self::escapeHtml($pct . '%') . '</strong></div></div>';
        echo '</div>';

        echo '<div class="progress contracthours-progress" role="progressbar" aria-valuenow="'
            . self::escapeHtml((string) $barWidth) . '" aria-valuemin="0" aria-valuemax="100">';
        echo '<div class="progress-bar ' . self::escapeHtml($barClass) . '" style="width: '
            . self::escapeHtml((string) $barWidth) . '%"></div>';
        echo '</div>';
    }

    private static function renderStatusBadge(?array $summary): string
    {
        if ($summary === null) {
            return '<span class="badge bg-secondary">' . self::escapeHtml(__('Não configurado', 'contracthours')) . '</span>';
        }
        if (empty($summary['is_active'])) {
            return '<span class="badge bg-secondary">' . self::escapeHtml(__('Inativo', 'contracthours')) . '</span>';
        }
        if (!empty($summary['is_overrun'])) {
            return '<span class="badge bg-danger">' . self::escapeHtml(__('Saldo excedido', 'contracthours')) . '</span>';
        }
        if (!empty($summary['is_threshold_reached'])) {
            return '<span class="badge bg-warning text-dark">' . self::escapeHtml(__('Em alerta', 'contracthours')) . '</span>';
        }
        return '<span class="badge bg-success">' . self::escapeHtml(__('Ativo', 'contracthours')) . '</span>';
    }

    private static function renderLedgerTable(array $entries, bool $showContractColumn): void
    {
        if (empty($entries)) {
            echo '<div class="p-3 text-muted">'
                . self::escapeHtml(__('Nenhum lançamento registrado até o momento.', 'contracthours'))
                . '</div>';
            return;
        }

        echo '<div class="table-responsive">';
        echo '<table class="table table-hover table-striped mb-0 contracthours-table">';
        echo '<thead><tr>';
        echo '<th>' . self::escapeHtml(__('Data', 'contracthours')) . '</th>';
        if ($showContractColumn) {
            echo '<th>' . self::escapeHtml(__('Contrato', 'contracthours')) . '</th>';
        }
        echo '<th>' . self::escapeHtml(__('Tipo', 'contracthours')) . '</th>';
        echo '<th>' . self::escapeHtml(__('Chamado', 'contracthours')) . '</th>';
        echo '<th>' . self::escapeHtml(__('Tarefa', 'contracthours')) . '</th>';
        echo '<th>' . self::escapeHtml(__('Variação', 'contracthours')) . '</th>';
        echo '<th>' . self::escapeHtml(__('Observação', 'contracthours')) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($entries as $row) {
            $delta      = (int) ($row['minutes_delta'] ?? 0);
            $deltaLabel = ($delta > 0 ? '+' : '') . PluginContracthoursPool::formatMinutes($delta);
            $deltaClass = $delta >= 0 ? 'text-success' : 'text-danger';
            $typeLabel  = self::translateEntryType((string) ($row['entry_type'] ?? 'debit'));
            $ticketsId  = (int) ($row['tickets_id'] ?? 0);
            $taskId     = (int) ($row['tickettasks_id'] ?? 0);

            echo '<tr>';
            echo '<td>' . self::escapeHtml((string) ($row['date_creation'] ?? '')) . '</td>';
            if ($showContractColumn) {
                echo '<td>' . self::escapeHtml(self::resolveContractDisplayName((int) ($row['contracts_id'] ?? 0))) . '</td>';
            }
            echo '<td>' . self::escapeHtml($typeLabel) . '</td>';
            echo '<td>' . self::escapeHtml($ticketsId > 0 ? ('#' . $ticketsId) : '-') . '</td>';
            echo '<td>' . self::escapeHtml($taskId > 0 ? ('#' . $taskId) : '-') . '</td>';
            echo '<td class="fw-bold ' . self::escapeHtml($deltaClass) . '">' . self::escapeHtml($deltaLabel) . '</td>';
            echo '<td>' . self::escapeHtml((string) ($row['note'] ?? '')) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    private static function translateEntryType(string $type): string
    {
        if ($type === PluginContracthoursPool::ENTRY_REFUND) {
            return __('Estorno', 'contracthours');
        }
        if ($type === PluginContracthoursPool::ENTRY_MANUAL) {
            return __('Ajuste manual', 'contracthours');
        }
        return __('Débito', 'contracthours');
    }

    private static function translateResolutionSource(string $source): string
    {
        if ($source === 'ticket_link') {
            return __('Vínculo explícito no chamado', 'contracthours');
        }
        if ($source === 'contract_item_ticket') {
            return __('Item de contrato (Chamado)', 'contracthours');
        }
        if ($source === 'contract_item_asset') {
            return __('Item de contrato (Ativo vinculado)', 'contracthours');
        }
        if ($source === 'entity_default') {
            return __('Contrato padrão da entidade', 'contracthours');
        }
        return $source;
    }

    private static function resolveContractDisplayName(int $contracts_id): string
    {
        global $DB;

        if ($contracts_id <= 0) {
            return '-';
        }

        if (isset($DB) && $DB->tableExists('glpi_contracts')) {
            $it = $DB->request([
                'SELECT' => ['name', 'num'],
                'FROM'   => 'glpi_contracts',
                'WHERE'  => ['id' => $contracts_id],
                'LIMIT'  => 1,
            ]);
            foreach ($it as $row) {
                $name = PluginContracthoursCompat::fromCore((string) ($row['name'] ?? ''));
                $num  = PluginContracthoursCompat::fromCore((string) ($row['num'] ?? ''));
                if ($name !== '' && $num !== '') {
                    return $name . ' (' . $num . ')';
                }
                if ($name !== '') {
                    return $name;
                }
            }
        }

        return '#' . $contracts_id;
    }
}

if (PluginContracthoursCompat::typedRightname()) {
    class PluginContracthoursTab extends CommonGLPI implements PluginContracthoursTabConstants
    {
        use PluginContracthoursTabBody;

        public static string $rightname = 'contract';
    }
} else {
    class PluginContracthoursTab extends CommonGLPI implements PluginContracthoursTabConstants
    {
        use PluginContracthoursTabBody;

        public static $rightname = 'contract';
    }
}
