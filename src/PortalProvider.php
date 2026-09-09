<?php


/**
 * ---------------------------------------------------------------------------
 * Entity Login Portals - give every GLPI entity its own login page.
 * Copyright (C) 2026 Agiteks.
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program. If not, see <https://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------------
 */

namespace GlpiPlugin\Entitylogin;

use CommonDBTM;
use Migration;
use GlpiPlugin\Entitylogin\Provider\ProviderSource;
use GlpiPlugin\Entitylogin\Provider\SamlssoSource;
use GlpiPlugin\Entitylogin\Provider\SourceRegistry;

/**
 * Mapping between a login portal and the SSO providers offered on it.
 *
 * A provider is identified by the pair (source, provider_id), because ids are
 * only unique within one SSO plugin.
 *
 * The SSO plugins' own tables are only ever READ, never written, so they can be
 * upgraded, reconfigured or removed independently of this plugin.
 */
class PortalProvider extends CommonDBTM
{
    public static $rightname = 'config';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_entitylogin_portals_providers';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Portal provider', 'Portal providers', $nb, 'entitylogin');
    }

    /**
     * True when at least one supported SSO plugin is active.
     */
    public static function hasAnySource(): bool
    {
        return SourceRegistry::available() !== [];
    }

    /**
     * Everything an admin may pick, grouped per SSO plugin.
     *
     * @return array<string, array{label:string, providers:array}>
     */
    public static function getSelectableProvidersBySource(): array
    {
        $grouped = [];

        foreach (SourceRegistry::available() as $key => $source) {
            $providers = $source->getSelectableProviders();
            if ($providers === []) {
                continue;
            }
            $grouped[$key] = [
                'label'     => $source->getLabel(),
                'providers' => $providers,
            ];
        }

        return $grouped;
    }

    /**
     * Mapped providers of a portal as "source:id" tokens, which is how the form
     * round-trips them.
     *
     * @return string[]
     */
    public static function getSelectionForPortal(int $portals_id): array
    {
        global $DB;

        $selection = [];
        $rows = $DB->request([
            'SELECT' => ['source', 'provider_id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_entitylogin_portals_id' => $portals_id],
        ]);

        foreach ($rows as $row) {
            $selection[] = $row['source'] . ':' . (int) $row['provider_id'];
        }

        return $selection;
    }

    /**
     * Replace the provider set of a portal.
     *
     * @param string[]|null $tokens "source:id" values, or null when the form did
     *                              not carry the field at all - which must not be
     *                              confused with the admin clearing every entry.
     */
    public static function saveForPortal(int $portals_id, ?array $tokens): void
    {
        global $DB;

        if ($tokens === null) {
            return;
        }

        $DB->delete(self::getTable(), ['plugin_entitylogin_portals_id' => $portals_id]);

        $seen = [];
        foreach ($tokens as $token) {
            $parts = explode(':', (string) $token, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$source_key, $provider_id] = [$parts[0], (int) $parts[1]];

            if ($provider_id <= 0 || SourceRegistry::get($source_key) === null) {
                // Ignore anything that does not name a source we support.
                continue;
            }

            $dedupe = $source_key . ':' . $provider_id;
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;

            $DB->insert(self::getTable(), [
                'plugin_entitylogin_portals_id' => $portals_id,
                'source'                        => $source_key,
                'provider_id'                   => $provider_id,
            ]);
        }
    }

    public static function purgeForPortal(int $portals_id): void
    {
        global $DB;

        $DB->delete(self::getTable(), ['plugin_entitylogin_portals_id' => $portals_id]);
    }

    /**
     * The buttons to display on a portal, across every available source.
     *
     * @return array<int, array>
     */
    public static function getLoginButtonsForPortal(int $portals_id): array
    {
        $by_source = [];
        foreach (self::getSelectionForPortal($portals_id) as $token) {
            [$source_key, $provider_id] = explode(':', $token, 2);
            $by_source[$source_key][] = (int) $provider_id;
        }

        $buttons = [];
        foreach ($by_source as $source_key => $provider_ids) {
            $source = SourceRegistry::get($source_key);
            if ($source === null || !$source->isAvailable()) {
                continue;
            }
            foreach ($source->getLoginButtons($provider_ids) as $button) {
                $button['source'] = $source_key;
                $buttons[] = $button;
            }
        }

        return $buttons;
    }

    /**
     * Provider ids mapped to a portal for one source.
     *
     * @return int[]
     */
    public static function getProviderIdsForPortal(int $portals_id, string $source_key): array
    {
        global $DB;

        $ids = [];
        $rows = $DB->request([
            'SELECT' => ['provider_id'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'plugin_entitylogin_portals_id' => $portals_id,
                'source'                        => $source_key,
            ],
        ]);

        foreach ($rows as $row) {
            $ids[] = (int) $row['provider_id'];
        }

        return $ids;
    }

    public static function install(Migration $migration): void
    {
        global $DB;

        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");

            $query = <<<SQL
            CREATE TABLE `$table` (
                `id`                            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_entitylogin_portals_id` INT UNSIGNED NOT NULL,
                `source`                        VARCHAR(32) NOT NULL DEFAULT 'samlsso',
                `provider_id`                   INT UNSIGNED NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_entitylogin_portals_id`, `source`, `provider_id`),
                KEY `source_provider` (`source`, `provider_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL;

            $DB->doQuery($query) or die($DB->error());

            return;
        }

        // Upgrade from 1.0.0, where a mapping could only ever point at samlSSO.
        if (!$DB->fieldExists($table, 'source', false)) {
            $migration->displayMessage("Upgrading $table to multi-source mappings");
            $DB->doQuery("ALTER TABLE `$table` ADD COLUMN `source` VARCHAR(32) NOT NULL DEFAULT '" . SamlssoSource::KEY . "'") or die($DB->error());
        }

        if ($DB->fieldExists($table, 'plugin_samlsso_configs_id', false)) {
            $DB->doQuery("ALTER TABLE `$table` CHANGE `plugin_samlsso_configs_id` `provider_id` INT UNSIGNED NOT NULL") or die($DB->error());
            $DB->doQuery("ALTER TABLE `$table` DROP INDEX `unicity`") or die($DB->error());
            $DB->doQuery("ALTER TABLE `$table` ADD UNIQUE KEY `unicity` (`plugin_entitylogin_portals_id`, `source`, `provider_id`)") or die($DB->error());
            $DB->doQuery("ALTER TABLE `$table` ADD KEY `source_provider` (`source`, `provider_id`)") or die($DB->error());

            // The 1.0.0 index kept the old column name; `source_provider`
            // replaces it.
            if (isIndex($table, 'plugin_samlsso_configs_id')) {
                $DB->doQuery("ALTER TABLE `$table` DROP INDEX `plugin_samlsso_configs_id`") or die($DB->error());
            }
        }
    }

    public static function uninstall(Migration $migration): void
    {
        global $DB;

        $table = self::getTable();
        if ($DB->tableExists($table)) {
            $migration->displayMessage("Removing $table");
            $DB->doQuery("DROP TABLE `$table`") or die($DB->error());
        }
    }
}
