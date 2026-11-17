<?php

/**
 * ------------------------------------------------------------------------
 * Contract Hours - Controle de banco de horas por contrato
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

define('PLUGIN_CONTRACTHOURS_VERSION', '1.0.0');
define('PLUGIN_CONTRACTHOURS_MIN_GLPI', '10.0.0');
define('PLUGIN_CONTRACTHOURS_MAX_GLPI', '12.0.99');
define('PLUGIN_CONTRACTHOURS_MIN_PHP', '7.4');

/**
 * Inicializa hooks, classes e recursos estáticos do plugin.
 */
function plugin_init_contracthours()
{
    global $PLUGIN_HOOKS;

    include_once __DIR__ . '/inc/compat.class.php';
    include_once __DIR__ . '/inc/config.class.php';
    include_once __DIR__ . '/inc/pool.class.php';
    include_once __DIR__ . '/inc/tab.class.php';

    $PLUGIN_HOOKS['csrf_compliant']['contracthours'] = true;
    $PLUGIN_HOOKS['config_page']['contracthours']    = 'front/config.form.php';
    $PLUGIN_HOOKS['add_css']['contracthours']        = [
        PluginContracthoursCompat::assetPath('css/contracthours.css'),
    ];

    if (class_exists('Plugin') && method_exists('Plugin', 'registerClass')) {
        Plugin::registerClass(PluginContracthoursConfig::class);
        Plugin::registerClass(PluginContracthoursTab::class, [
            'addtabon' => ['Contract', 'Ticket'],
        ]);
    }

    $PLUGIN_HOOKS['pre_item_add']['contracthours'] = [
        'TicketTask' => [PluginContracthoursPool::class, 'preItemAddTicketTask'],
    ];

    $PLUGIN_HOOKS['pre_item_update']['contracthours'] = [
        'TicketTask' => [PluginContracthoursPool::class, 'preItemUpdateTicketTask'],
    ];

    $PLUGIN_HOOKS['item_add']['contracthours'] = [
        'TicketTask' => [PluginContracthoursPool::class, 'itemAddTicketTask'],
    ];

    $PLUGIN_HOOKS['item_update']['contracthours'] = [
        'TicketTask' => [PluginContracthoursPool::class, 'itemUpdateTicketTask'],
    ];

    $PLUGIN_HOOKS['item_delete']['contracthours'] = [
        'TicketTask' => [PluginContracthoursPool::class, 'itemDeleteTicketTask'],
    ];

    $PLUGIN_HOOKS['item_purge']['contracthours'] = [
        'TicketTask' => [PluginContracthoursPool::class, 'itemPurgeTicketTask'],
        'Ticket'     => [PluginContracthoursPool::class, 'itemPurgeTicket'],
        'Contract'   => [PluginContracthoursPool::class, 'itemPurgeContract'],
    ];
}

/**
 * Metadados exibidos em Configurar > Plugins.
 */
function plugin_version_contracthours()
{
    return [
        'name'         => 'Contract Hours',
        'version'      => PLUGIN_CONTRACTHOURS_VERSION,
        'author'       => 'G. Martins',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/GustavoMS0/GLPI-ContractHours',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_CONTRACTHOURS_MIN_GLPI,
                'max' => PLUGIN_CONTRACTHOURS_MAX_GLPI,
            ],
            'php'  => [
                'min' => PLUGIN_CONTRACTHOURS_MIN_PHP,
            ],
        ],
    ];
}

/**
 * Verifica pré-requisitos de PHP e GLPI antes da instalação/ativação.
 */
function plugin_contracthours_check_prerequisites()
{
    if (version_compare(PHP_VERSION, PLUGIN_CONTRACTHOURS_MIN_PHP, '<')) {
        echo sprintf('Este plugin exige PHP %s ou superior.', PLUGIN_CONTRACTHOURS_MIN_PHP);
        return false;
    }

    if (defined('GLPI_VERSION')) {
        if (version_compare((string) GLPI_VERSION, PLUGIN_CONTRACTHOURS_MIN_GLPI, '<')) {
            echo sprintf('Este plugin exige GLPI %s ou superior.', PLUGIN_CONTRACTHOURS_MIN_GLPI);
            return false;
        }
        if (version_compare((string) GLPI_VERSION, PLUGIN_CONTRACTHOURS_MAX_GLPI, '>')) {
            echo sprintf('Este plugin suporta até GLPI %s.', PLUGIN_CONTRACTHOURS_MAX_GLPI);
            return false;
        }
    }

    return true;
}

/**
 * Verifica se o plugin está pronto para ativação.
 */
function plugin_contracthours_check_config($verbose = false)
{
    if ($verbose) {
        echo function_exists('__') ? __('Instalado / configurado', 'contracthours') : 'Instalado / configurado';
    }
    return true;
}
