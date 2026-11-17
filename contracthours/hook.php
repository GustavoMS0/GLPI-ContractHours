<?php

/**
 * ------------------------------------------------------------------------
 * Contract Hours - Instalação e desinstalação
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

/**
 * Cria as tabelas e valores padrão na instalação ou atualização.
 */
function plugin_contracthours_install()
{
    include_once __DIR__ . '/inc/compat.class.php';
    include_once __DIR__ . '/inc/config.class.php';
    include_once __DIR__ . '/inc/pool.class.php';

    PluginContracthoursPool::installSchema();
    PluginContracthoursConfig::installDefaults();
    return true;
}

/**
 * Remove as tabelas e configurações do plugin na desinstalação.
 */
function plugin_contracthours_uninstall()
{
    include_once __DIR__ . '/inc/compat.class.php';
    include_once __DIR__ . '/inc/config.class.php';
    include_once __DIR__ . '/inc/pool.class.php';

    PluginContracthoursPool::uninstallSchema();
    PluginContracthoursConfig::uninstallConfig();
    return true;
}
