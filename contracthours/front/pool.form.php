<?php

/**
 * ------------------------------------------------------------------------
 * Contract Hours - Processamento de ações das abas de Contrato e Chamado
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
    include_once __DIR__ . '/../../../inc/includes.php';
}

require_once __DIR__ . '/../inc/compat.class.php';
require_once __DIR__ . '/../inc/config.class.php';
require_once __DIR__ . '/../inc/pool.class.php';

if (!PluginContracthoursCompat::requireSafePost()) {
    PluginContracthoursCompat::denyAccess(__('Origem da requisição não permitida.', 'contracthours'));
    return;
}

$action = trim((string) ($_POST['action'] ?? ''));

if ($action === 'save_pool') {
    Session::checkRight('contract', UPDATE);

    $contractsId = (int) ($_POST['contracts_id'] ?? 0);
    $contract    = new Contract();
    if ($contractsId <= 0 || !$contract->getFromDB($contractsId)) {
        PluginContracthoursCompat::denyAccess(__('Contrato não encontrado.', 'contracthours'));
        return;
    }

    if (method_exists($contract, 'canUpdateItem') && !$contract->canUpdateItem()) {
        PluginContracthoursCompat::denyAccess();
        return;
    }

    $entityId = method_exists($contract, 'getEntityID')
        ? (int) $contract->getEntityID()
        : (int) ($contract->fields['entities_id'] ?? 0);
    if (method_exists('Session', 'haveAccessToEntity') && !Session::haveAccessToEntity($entityId)) {
        PluginContracthoursCompat::denyAccess();
        return;
    }

    $hours    = max(0, (int) ($_POST['contracted_hours'] ?? 0));
    $minsPart = max(0, min(59, (int) ($_POST['contracted_minutes_part'] ?? 0)));
    $rateRaw  = PluginContracthoursCompat::fromSanitizedInput((string) ($_POST['hourly_rate'] ?? '0'));

    PluginContracthoursPool::savePool($contractsId, [
        'entities_id'         => $entityId,
        'contracted_minutes'  => ($hours * 60) + $minsPart,
        'alert_threshold_pct' => (int) ($_POST['alert_threshold_pct'] ?? 80),
        'block_on_overrun'    => empty($_POST['block_on_overrun']) ? 0 : 1,
        'allow_rollover'      => empty($_POST['allow_rollover']) ? 0 : 1,
        'hourly_rate_cents'   => PluginContracthoursPool::parseMoneyToCents($rateRaw),
        'billing_cycle'       => (string) ($_POST['billing_cycle'] ?? 'total'),
        'is_active'           => empty($_POST['is_active']) ? 0 : 1,
    ]);

    Session::addMessageAfterRedirect(__('Configuração do pool de horas salva.', 'contracthours'));
    Html::back();
    return;
}

if ($action === 'manual_adjustment') {
    Session::checkRight('contract', UPDATE);

    $contractsId = (int) ($_POST['contracts_id'] ?? 0);
    $contract    = new Contract();
    if ($contractsId <= 0 || !$contract->getFromDB($contractsId)) {
        PluginContracthoursCompat::denyAccess(__('Contrato não encontrado.', 'contracthours'));
        return;
    }

    if (method_exists($contract, 'canUpdateItem') && !$contract->canUpdateItem()) {
        PluginContracthoursCompat::denyAccess();
        return;
    }

    $entityId = method_exists($contract, 'getEntityID')
        ? (int) $contract->getEntityID()
        : (int) ($contract->fields['entities_id'] ?? 0);
    if (method_exists('Session', 'haveAccessToEntity') && !Session::haveAccessToEntity($entityId)) {
        PluginContracthoursCompat::denyAccess();
        return;
    }

    $adjType = strtolower(trim((string) ($_POST['adjustment_type'] ?? 'credit')));
    $hours   = max(0, (int) ($_POST['adjust_hours'] ?? 0));
    $mins    = max(0, min(59, (int) ($_POST['adjust_minutes'] ?? 0)));
    $total   = ($hours * 60) + $mins;

    if ($total <= 0) {
        Session::addMessageAfterRedirect(
            __('Informe uma quantidade de horas ou minutos maior que zero para o ajuste.', 'contracthours'),
            false,
            WARNING
        );
        Html::back();
        return;
    }

    $delta = $adjType === 'debit' ? -$total : $total;
    $note  = PluginContracthoursCompat::fromSanitizedInput((string) ($_POST['note'] ?? ''));

    PluginContracthoursPool::recordManualAdjustment($contractsId, $delta, $note);
    Session::addMessageAfterRedirect(__('Ajuste manual registrado no razão.', 'contracthours'));
    Html::back();
    return;
}

if ($action === 'set_ticket_link') {
    Session::checkRight('ticket', UPDATE);

    $ticketsId = (int) ($_POST['tickets_id'] ?? 0);
    $ticket    = new Ticket();
    if ($ticketsId <= 0 || !$ticket->getFromDB($ticketsId)) {
        PluginContracthoursCompat::denyAccess(__('Chamado não encontrado.', 'contracthours'));
        return;
    }

    if (method_exists($ticket, 'canUpdateItem') && !$ticket->canUpdateItem()) {
        PluginContracthoursCompat::denyAccess();
        return;
    }

    $ticketEntity = method_exists($ticket, 'getEntityID')
        ? (int) $ticket->getEntityID()
        : (int) ($ticket->fields['entities_id'] ?? 0);
    if (method_exists('Session', 'haveAccessToEntity') && !Session::haveAccessToEntity($ticketEntity)) {
        PluginContracthoursCompat::denyAccess();
        return;
    }

    $contractsId = max(0, (int) ($_POST['contracts_id'] ?? 0));
    if ($contractsId > 0) {
        $contract = new Contract();
        if (!$contract->getFromDB($contractsId) || !PluginContracthoursPool::isPoolActiveAndValid($contractsId)) {
            Session::addMessageAfterRedirect(
                __('O contrato selecionado não possui um banco de horas ativo.', 'contracthours'),
                false,
                ERROR
            );
            Html::back();
            return;
        }

        $contractEntity = method_exists($contract, 'getEntityID')
            ? (int) $contract->getEntityID()
            : (int) ($contract->fields['entities_id'] ?? 0);
        if (method_exists('Session', 'haveAccessToEntity') && !Session::haveAccessToEntity($contractEntity)) {
            PluginContracthoursCompat::denyAccess();
            return;
        }
    }

    PluginContracthoursPool::setTicketContractLink($ticketsId, $contractsId);
    Session::addMessageAfterRedirect(__('Vínculo de contrato do chamado atualizado.', 'contracthours'));
    Html::back();
    return;
}

PluginContracthoursCompat::denyAccess(__('Ação inválida.', 'contracthours'));
