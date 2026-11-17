<?php

/**
 * ------------------------------------------------------------------------
 * Contract Hours - Configuração global e contratos padrão por entidade
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
 * Gerencia configurações globais do plugin e o mapeamento de contrato padrão por entidade.
 */
class PluginContracthoursConfig
{
    public const CONFIG_CONTEXT = 'plugin:contracthours';
    public const DEFAULT_THRESHOLD_PCT = 80;

    /**
     * Verifica permissão de leitura na configuração.
     */
    public static function canView(): bool
    {
        return class_exists('Session') && (bool) Session::haveRight('config', READ);
    }

    /**
     * Verifica permissão de atualização na configuração.
     */
    public static function canUpdate(): bool
    {
        return class_exists('Session') && (bool) Session::haveRight('config', UPDATE);
    }

    /**
     * Inicializa valores padrão na instalação se ainda não existirem.
     */
    public static function installDefaults(): void
    {
        $raw = self::readConfigurationValues();
        $toSet = [];
        if (!array_key_exists('default_alert_threshold_pct', $raw)) {
            $toSet['default_alert_threshold_pct'] = (string) self::DEFAULT_THRESHOLD_PCT;
        }
        if (!array_key_exists('show_tab_selfservice', $raw)) {
            $toSet['show_tab_selfservice'] = '0';
        }
        if (!array_key_exists('entity_defaults', $raw)) {
            $toSet['entity_defaults'] = '{}';
        }
        if (!empty($toSet)) {
            self::writeConfigurationValues($toSet);
        }
    }

    /**
     * Remove todas as configurações do plugin na desinstalação.
     */
    public static function uninstallConfig(): void
    {
        global $DB;

        if (class_exists('Config') && method_exists('Config', 'deleteConfigurationValues')) {
            Config::deleteConfigurationValues(self::CONFIG_CONTEXT, [
                'default_alert_threshold_pct',
                'show_tab_selfservice',
                'entity_defaults',
            ]);
        }

        if (isset($DB) && method_exists($DB, 'delete') && method_exists($DB, 'tableExists')) {
            if ($DB->tableExists('glpi_configs')) {
                $DB->delete('glpi_configs', ['context' => self::CONFIG_CONTEXT]);
            }
        }
    }

    /**
     * Retorna as configurações normalizadas do plugin.
     *
     * @return array{default_alert_threshold_pct: int, show_tab_selfservice: int, entity_defaults: array<int, array{contracts_id: int, is_recursive: int}>}
     */
    public static function getConfig(): array
    {
        $raw = self::readConfigurationValues();

        $threshold = isset($raw['default_alert_threshold_pct'])
            ? (int) $raw['default_alert_threshold_pct']
            : self::DEFAULT_THRESHOLD_PCT;
        if ($threshold < 1) {
            $threshold = 1;
        } elseif ($threshold > 100) {
            $threshold = 100;
        }

        $showSelf = !empty($raw['show_tab_selfservice']) ? 1 : 0;

        return [
            'default_alert_threshold_pct' => $threshold,
            'show_tab_selfservice'        => $showSelf,
            'entity_defaults'             => self::decodeEntityDefaults($raw['entity_defaults'] ?? '{}'),
        ];
    }

    /**
     * Salva as opções globais do plugin.
     */
    public static function saveGlobalOptions(array $input): bool
    {
        $threshold = isset($input['default_alert_threshold_pct'])
            ? (int) $input['default_alert_threshold_pct']
            : self::DEFAULT_THRESHOLD_PCT;
        if ($threshold < 1) {
            $threshold = 1;
        } elseif ($threshold > 100) {
            $threshold = 100;
        }

        $showSelf = !empty($input['show_tab_selfservice']) ? 1 : 0;

        self::writeConfigurationValues([
            'default_alert_threshold_pct' => (string) $threshold,
            'show_tab_selfservice'        => (string) $showSelf,
        ]);

        return true;
    }

    /**
     * Retorna o mapa de contratos padrão por entidade.
     *
     * @return array<int, array{contracts_id: int, is_recursive: int}>
     */
    public static function getEntityDefaults(): array
    {
        $cfg = self::getConfig();
        return $cfg['entity_defaults'];
    }

    /**
     * Define ou remove o contrato padrão de uma entidade.
     */
    public static function setEntityDefault(int $entities_id, int $contracts_id, int $is_recursive = 1): bool
    {
        if ($entities_id < 0) {
            return false;
        }

        $map = self::getEntityDefaults();
        if ($contracts_id <= 0) {
            unset($map[$entities_id]);
        } else {
            $map[$entities_id] = [
                'contracts_id' => $contracts_id,
                'is_recursive' => $is_recursive ? 1 : 0,
            ];
        }

        return self::saveEntityDefaultsMap($map);
    }

    /**
     * Remove referências a um contrato expurgado nos padrões por entidade.
     */
    public static function removeContractFromEntityDefaults(int $contracts_id): void
    {
        if ($contracts_id <= 0) {
            return;
        }

        $map     = self::getEntityDefaults();
        $changed = false;
        foreach ($map as $entityId => $entry) {
            if ((int) ($entry['contracts_id'] ?? 0) === $contracts_id) {
                unset($map[$entityId]);
                $changed = true;
            }
        }

        if ($changed) {
            self::saveEntityDefaultsMap($map);
        }
    }

    /**
     * Resolve o contrato padrão aplicável à entidade (direto ou por herança recursiva de ancestrais).
     */
    public static function resolveDefaultContractForEntity(int $entities_id): int
    {
        if ($entities_id < 0) {
            $entities_id = 0;
        }

        $map = self::getEntityDefaults();
        if (empty($map)) {
            return 0;
        }

        if (isset($map[$entities_id])) {
            $cid = (int) $map[$entities_id]['contracts_id'];
            if ($cid > 0 && PluginContracthoursPool::isPoolActiveAndValid($cid)) {
                return $cid;
            }
        }

        $ancestors = PluginContracthoursCompat::getEntitiesAncestorsFallback($entities_id);
        foreach ($ancestors as $ancestorId) {
            if (!isset($map[$ancestorId])) {
                continue;
            }
            $entry = $map[$ancestorId];
            if (empty($entry['is_recursive'])) {
                continue;
            }
            $cid = (int) $entry['contracts_id'];
            if ($cid > 0 && PluginContracthoursPool::isPoolActiveAndValid($cid)) {
                return $cid;
            }
        }

        return 0;
    }

    /**
     * @param array<int, array{contracts_id: int, is_recursive: int}> $map
     */
    private static function saveEntityDefaultsMap(array $map): bool
    {
        ksort($map);
        $encoded = json_encode($map);
        if (!is_string($encoded)) {
            $encoded = '{}';
        }
        self::writeConfigurationValues([
            'entity_defaults' => $encoded,
        ]);
        return true;
    }

    /**
     * @param mixed $raw
     * @return array<int, array{contracts_id: int, is_recursive: int}>
     */
    private static function decodeEntityDefaults($raw): array
    {
        if (is_array($raw)) {
            $decoded = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $json    = PluginContracthoursCompat::fromCore($raw);
            $decoded = json_decode($json, true);
        } else {
            $decoded = [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $entityId => $entry) {
            $eid = (int) $entityId;
            if ($eid < 0 || !is_array($entry)) {
                continue;
            }
            $cid = (int) ($entry['contracts_id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $normalized[$eid] = [
                'contracts_id' => $cid,
                'is_recursive' => !empty($entry['is_recursive']) ? 1 : 0,
            ];
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readConfigurationValues(): array
    {
        global $DB;

        if (class_exists('Config') && method_exists('Config', 'getConfigurationValues')) {
            $vals = Config::getConfigurationValues(self::CONFIG_CONTEXT);
            if (is_array($vals)) {
                return $vals;
            }
        }

        $out = [];
        if (isset($DB) && method_exists($DB, 'request') && method_exists($DB, 'tableExists') && $DB->tableExists('glpi_configs')) {
            $it = $DB->request([
                'FROM'  => 'glpi_configs',
                'WHERE' => ['context' => self::CONFIG_CONTEXT],
            ]);
            foreach ($it as $row) {
                if (isset($row['name'])) {
                    $out[(string) $row['name']] = $row['value'] ?? null;
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string, string> $values
     */
    private static function writeConfigurationValues(array $values): void
    {
        global $DB;

        if (class_exists('Config') && method_exists('Config', 'setConfigurationValues')) {
            Config::setConfigurationValues(self::CONFIG_CONTEXT, $values);
            return;
        }

        if (!isset($DB) || !method_exists($DB, 'tableExists') || !$DB->tableExists('glpi_configs')) {
            return;
        }

        foreach ($values as $name => $value) {
            $existing = null;
            foreach ($DB->request([
                'FROM'  => 'glpi_configs',
                'WHERE' => ['context' => self::CONFIG_CONTEXT, 'name' => (string) $name],
                'LIMIT' => 1,
            ]) as $row) {
                $existing = $row;
                break;
            }

            $data = PluginContracthoursCompat::dbValues(['value' => (string) $value]);
            if ($existing !== null) {
                $DB->update('glpi_configs', $data, ['id' => (int) $existing['id']]);
            } else {
                $DB->insert('glpi_configs', PluginContracthoursCompat::dbValues([
                    'context' => self::CONFIG_CONTEXT,
                    'name'    => (string) $name,
                    'value'   => (string) $value,
                ]));
            }
        }
    }
}
