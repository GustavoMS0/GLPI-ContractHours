<?php

/**
 * ------------------------------------------------------------------------
 * Contract Hours - Painel administrativo e configurações
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

Session::checkRight('config', READ);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
    Session::checkRight('config', UPDATE);

    if (!PluginContracthoursCompat::requireSafePost()) {
        PluginContracthoursCompat::denyAccess(__('Origem da requisição não permitida.', 'contracthours'));
        return;
    }

    if (isset($_POST['update_global'])) {
        PluginContracthoursConfig::saveGlobalOptions([
            'default_alert_threshold_pct' => $_POST['default_alert_threshold_pct'] ?? PluginContracthoursConfig::DEFAULT_THRESHOLD_PCT,
            'show_tab_selfservice'        => empty($_POST['show_tab_selfservice']) ? 0 : 1,
        ]);

        Session::addMessageAfterRedirect(__('Configurações gerais salvas.', 'contracthours'));
        Html::back();
        return;
    }

    if (isset($_POST['save_entity_default'])) {
        $entitiesId  = (int) ($_POST['entities_id'] ?? -1);
        $contractsId = (int) ($_POST['contracts_id'] ?? 0);
        $isRecursive = empty($_POST['is_recursive']) ? 0 : 1;

        if ($entitiesId < 0 || (method_exists('Session', 'haveAccessToEntity') && !Session::haveAccessToEntity($entitiesId))) {
            PluginContracthoursCompat::denyAccess();
            return;
        }

        if ($contractsId > 0 && !PluginContracthoursPool::isPoolActiveAndValid($contractsId)) {
            Session::addMessageAfterRedirect(
                __('Selecione um contrato com pool de horas ativo.', 'contracthours'),
                false,
                ERROR
            );
            Html::back();
            return;
        }

        PluginContracthoursConfig::setEntityDefault($entitiesId, $contractsId, $isRecursive);
        Session::addMessageAfterRedirect(__('Contrato padrão da entidade atualizado.', 'contracthours'));
        Html::back();
        return;
    }

    if (isset($_POST['delete_entity_default'])) {
        $entitiesId = (int) ($_POST['entities_id'] ?? -1);
        if ($entitiesId < 0 || (method_exists('Session', 'haveAccessToEntity') && !Session::haveAccessToEntity($entitiesId))) {
            PluginContracthoursCompat::denyAccess();
            return;
        }

        PluginContracthoursConfig::setEntityDefault($entitiesId, 0, 0);
        Session::addMessageAfterRedirect(__('Mapeamento da entidade removido.', 'contracthours'));
        Html::back();
        return;
    }
}

$e = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};

$config         = PluginContracthoursConfig::getConfig();
$canUpdate      = PluginContracthoursConfig::canUpdate();
$activePools    = PluginContracthoursPool::getAllPoolSummaries(true, true);
$allPools       = PluginContracthoursPool::getAllPoolSummaries(false, true);
$entityDefaults = $config['entity_defaults'];
$title          = __('Contract Hours', 'contracthours');
$action         = PluginContracthoursCompat::webPath() . '/front/config.form.php';
$disabled       = $canUpdate ? '' : ' disabled';

$totalActive  = count($activePools);
$alertCount   = 0;
$overrunCount = 0;
foreach ($activePools as $pool) {
    if (!empty($pool['is_overrun'])) {
        $overrunCount++;
    } elseif (!empty($pool['is_threshold_reached'])) {
        $alertCount++;
    }
}

$poolMap = [];
foreach ($allPools as $pool) {
    $poolMap[(int) $pool['contracts_id']] = $pool;
}

Html::header($title, '', 'config', 'plugin');

echo '<div class="container-fluid py-3 contracthours-admin">';

if (!$canUpdate) {
    echo '<div class="alert alert-warning mb-3">'
        . $e(__('Você possui permissão apenas de leitura nas configurações.', 'contracthours'))
        . '</div>';
}

// Painel resumo dos pools ativos
echo '<div class="card mb-4">';
echo '<div class="card-header d-flex align-items-center justify-content-between">';
echo '<h3 class="card-title mb-0"><i class="ti ti-clock-hour-4 me-2" aria-hidden="true"></i>'
    . $e(__('Painel de Bancos de Horas Ativos', 'contracthours')) . '</h3>';
echo '<div class="d-flex gap-2">';
echo '<span class="badge bg-primary">' . $e(sprintf(__('Ativos: %d', 'contracthours'), $totalActive)) . '</span>';
echo '<span class="badge bg-warning text-dark">' . $e(sprintf(__('Em alerta: %d', 'contracthours'), $alertCount)) . '</span>';
echo '<span class="badge bg-danger">' . $e(sprintf(__('Estouro: %d', 'contracthours'), $overrunCount)) . '</span>';
echo '</div></div>';
echo '<div class="card-body p-0">';

if (empty($activePools)) {
    echo '<div class="p-3 text-muted">'
        . $e(__('Nenhum contrato possui banco de horas ativo até o momento. Acesse um Contrato e configure a aba Banco de Horas.', 'contracthours'))
        . '</div>';
} else {
    echo '<div class="table-responsive">';
    echo '<table class="table table-hover table-striped mb-0 contracthours-table">';
    echo '<thead><tr>';
    echo '<th>' . $e(__('Contrato', 'contracthours')) . '</th>';
    echo '<th>' . $e(__('Entidade', 'contracthours')) . '</th>';
    echo '<th>' . $e(__('Contratado', 'contracthours')) . '</th>';
    echo '<th>' . $e(__('Consumido', 'contracthours')) . '</th>';
    echo '<th>' . $e(__('Saldo', 'contracthours')) . '</th>';
    echo '<th class="contracthours-col-progress">' . $e(__('Consumo', 'contracthours')) . '</th>';
    echo '<th>' . $e(__('Status', 'contracthours')) . '</th>';
    echo '<th>' . $e(__('Bloqueio', 'contracthours')) . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($activePools as $pool) {
        $cid       = (int) $pool['contracts_id'];
        $cname     = trim((string) ($pool['contract_name'] ?? ''));
        $cnum      = trim((string) ($pool['contract_num'] ?? ''));
        $cLabel    = $cname !== '' ? ($cnum !== '' ? ($cname . ' (' . $cnum . ')') : $cname) : ('#' . $cid);
        $entityTxt = class_exists('Dropdown') ? Dropdown::getDropdownName('glpi_entities', (int) $pool['entities_id']) : ('#' . (int) $pool['entities_id']);
        $pct       = (int) $pool['consumption_pct'];
        $barWidth  = max(0, min(100, $pct));

        $barClass   = 'bg-success';
        $badgeHtml  = '<span class="badge bg-success">' . $e(__('Normal', 'contracthours')) . '</span>';
        if (!empty($pool['is_overrun'])) {
            $barClass  = 'bg-danger';
            $badgeHtml = '<span class="badge bg-danger">' . $e(__('Estouro', 'contracthours')) . '</span>';
        } elseif (!empty($pool['is_threshold_reached'])) {
            $barClass  = 'bg-warning';
            $badgeHtml = '<span class="badge bg-warning text-dark">'
                . $e(sprintf(__('Alerta >= %d%%', 'contracthours'), (int) $pool['alert_threshold_pct']))
                . '</span>';
        }

        echo '<tr>';
        echo '<td>' . $e($cLabel) . '</td>';
        echo '<td>' . $e((string) $entityTxt) . '</td>';
        echo '<td>' . $e(PluginContracthoursPool::formatMinutes((int) $pool['contracted_minutes'])) . '</td>';
        echo '<td>' . $e(PluginContracthoursPool::formatMinutes((int) $pool['consumed_minutes'])) . '</td>';
        echo '<td class="fw-bold">' . $e(PluginContracthoursPool::formatMinutes((int) $pool['remaining_minutes'])) . '</td>';
        echo '<td>';
        echo '<div class="d-flex align-items-center gap-2">';
        echo '<div class="progress flex-grow-1 contracthours-progress-sm"><div class="progress-bar '
            . $e($barClass) . '" style="width: ' . $e((string) $barWidth) . '%"></div></div>';
        echo '<span class="small text-muted">' . $e($pct . '%') . '</span>';
        echo '</div></td>';
        echo '<td>' . $badgeHtml . '</td>';
        echo '<td>' . $e(!empty($pool['block_on_overrun']) ? __('Sim', 'contracthours') : __('Não', 'contracthours')) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

echo '</div></div>';

// Mapeamento de contrato padrão por entidade
echo '<div class="card mb-4">';
echo '<div class="card-header"><h4 class="card-title mb-0">'
    . $e(__('Contrato Padrão por Entidade', 'contracthours')) . '</h4></div>';
echo '<div class="card-body">';

if ($canUpdate) {
    echo '<form method="post" action="' . $e($action) . '" class="row g-3 align-items-end mb-4">';
    echo '<div class="col-md-4">';
    echo '<label class="form-label">' . $e(__('Entidade', 'contracthours')) . '</label>';
    if (class_exists('Entity') && method_exists('Entity', 'dropdown')) {
        Entity::dropdown(['name' => 'entities_id', 'value' => 0]);
    } else {
        echo '<input type="number" class="form-control" name="entities_id" min="0" value="0">';
    }
    echo '</div>';

    echo '<div class="col-md-4">';
    echo '<label class="form-label" for="ch-def-contract">' . $e(__('Contrato com pool ativo', 'contracthours')) . '</label>';
    echo '<select class="form-select" id="ch-def-contract" name="contracts_id">';
    echo '<option value="0">' . $e(__('-- Selecione --', 'contracthours')) . '</option>';
    foreach ($activePools as $pool) {
        $cid    = (int) $pool['contracts_id'];
        $cLabel = trim((string) ($pool['contract_name'] ?? ''));
        if ($cLabel === '') {
            $cLabel = '#' . $cid;
        }
        echo '<option value="' . $e((string) $cid) . '">' . $e($cLabel) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="col-md-2">';
    echo '<div class="form-check form-switch">';
    echo '<input type="hidden" name="is_recursive" value="0">';
    echo '<input class="form-check-input" type="checkbox" id="ch-def-rec" name="is_recursive" value="1" checked>';
    echo '<label class="form-check-label" for="ch-def-rec">' . $e(__('Recursivo', 'contracthours')) . '</label>';
    echo '</div></div>';

    echo '<div class="col-md-2">';
    echo '<button type="submit" class="btn btn-primary w-100" name="save_entity_default" value="1">'
        . $e(__('Definir padrão', 'contracthours')) . '</button>';
    echo '</div>';
    Html::closeForm();
}

if (empty($entityDefaults)) {
    echo '<p class="text-muted mb-0">'
        . $e(__('Nenhum contrato padrão por entidade configurado.', 'contracthours'))
        . '</p>';
} else {
    echo '<div class="table-responsive">';
    echo '<table class="table table-sm table-striped mb-0">';
    echo '<thead><tr>';
    echo '<th>' . $e(__('Entidade', 'contracthours')) . '</th>';
    echo '<th>' . $e(__('Contrato padrão', 'contracthours')) . '</th>';
    echo '<th>' . $e(__('Recursivo', 'contracthours')) . '</th>';
    if ($canUpdate) {
        echo '<th class="text-end">' . $e(__('Ações', 'contracthours')) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($entityDefaults as $eid => $entry) {
        $cid       = (int) $entry['contracts_id'];
        $entityTxt = class_exists('Dropdown') ? Dropdown::getDropdownName('glpi_entities', (int) $eid) : ('#' . (int) $eid);
        $cLabel    = isset($poolMap[$cid]) ? (string) $poolMap[$cid]['contract_name'] : ('#' . $cid);
        if ($cLabel === '') {
            $cLabel = '#' . $cid;
        }

        echo '<tr>';
        echo '<td>' . $e((string) $entityTxt) . '</td>';
        echo '<td>' . $e($cLabel) . '</td>';
        echo '<td>' . $e(!empty($entry['is_recursive']) ? __('Sim', 'contracthours') : __('Não', 'contracthours')) . '</td>';
        if ($canUpdate) {
            echo '<td class="text-end">';
            echo '<form method="post" action="' . $e($action) . '" class="d-inline">';
            echo '<input type="hidden" name="entities_id" value="' . $e((string) $eid) . '">';
            echo '<button type="submit" class="btn btn-sm btn-outline-danger" name="delete_entity_default" value="1">'
                . $e(__('Remover', 'contracthours')) . '</button>';
            Html::closeForm();
            echo '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

echo '</div></div>';

// Configurações gerais
echo '<div class="card">';
echo '<div class="card-header"><h4 class="card-title mb-0">'
    . $e(__('Configurações Gerais', 'contracthours')) . '</h4></div>';
echo '<div class="card-body">';
echo '<form method="post" action="' . $e($action) . '">';

echo '<div class="mb-3">';
echo '<label class="form-label" for="ch-global-threshold">'
    . $e(__('Limiar padrão de alerta (%)', 'contracthours')) . '</label>';
echo '<input type="number" class="form-control contracthours-threshold-input" id="ch-global-threshold"'
    . ' name="default_alert_threshold_pct" min="1" max="100" value="'
    . $e((string) $config['default_alert_threshold_pct']) . '"' . $disabled . '>';
echo '<div class="form-text">'
    . $e(__('Percentual padrão de consumo sugerido ao criar um novo banco de horas de contrato (1 a 100%).', 'contracthours'))
    . '</div></div>';

echo '<div class="form-check form-switch mb-3">';
echo '<input type="hidden" name="show_tab_selfservice" value="0">';
echo '<input class="form-check-input" type="checkbox" role="switch" id="ch-global-selfservice"'
    . ' name="show_tab_selfservice" value="1"'
    . (!empty($config['show_tab_selfservice']) ? ' checked' : '') . $disabled . '>';
echo '<label class="form-check-label" for="ch-global-selfservice">'
    . $e(__('Exibir aba Banco de Horas nos chamados do autoatendimento', 'contracthours'))
    . '</label>';
echo '</div>';

if ($canUpdate) {
    echo '<button type="submit" class="btn btn-primary" name="update_global" value="1">'
        . '<i class="ti ti-device-floppy me-1" aria-hidden="true"></i>'
        . $e(__('Salvar configurações', 'contracthours'))
        . '</button>';
}

Html::closeForm();
echo '</div></div>';
echo '</div>';

Html::footer();
