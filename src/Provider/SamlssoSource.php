<?php


/**
 * ---------------------------------------------------------------------------
 * Entity Login Portals - give every GLPI entity its own login page.
 * Copyright (C) 2026 Agiteks Enterprise Solutions.
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

namespace GlpiPlugin\Entitylogin\Provider;

use Plugin;

/**
 * Adapter for the samlSSO plugin (DonutsNL/samlsso).
 *
 * samlSSO renders its buttons as submit buttons inside GLPI's own login form,
 * carrying the chosen IdP id in `samlIdpId`; its `LoginFlow::doAuth()` picks
 * that up on POST. Emitting the same field reuses its entire SAML flow.
 */
final class SamlssoSource implements ProviderSource
{
    public const KEY = 'samlsso';

    /** Mirrors GlpiPlugin\Samlsso\LoginFlow::POSTFIELD. */
    public const POSTFIELD = 'samlIdpId';

    private const TABLE = 'glpi_plugin_samlsso_configs';

    /** samlSSO ignores a config whose domain is still the shipped placeholder. */
    private const PLACEHOLDER_DOMAIN = 'youruserdomain.tld';

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getLabel(): string
    {
        return 'samlSSO';
    }

    public function isAvailable(): bool
    {
        global $DB;

        return Plugin::isPluginActive(self::KEY) && $DB->tableExists(self::TABLE);
    }

    public function getSelectableProviders(): array
    {
        global $DB;

        if (!$this->isAvailable()) {
            return [];
        }

        $providers = [];
        $rows = $DB->request([
            'SELECT' => ['id', 'name', 'is_active', 'conf_domain'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['is_deleted' => 0],
            'ORDER'  => 'name',
        ]);

        foreach ($rows as $row) {
            $domain = (string) $row['conf_domain'];
            $note   = '';
            if (!(bool) $row['is_active']) {
                $note = __('inactive', 'entitylogin');
            } elseif ($domain !== '' && $domain !== self::PLACEHOLDER_DOMAIN) {
                // samlSSO resolves these from the user's e-mail domain instead
                // of showing a button, so it would never appear on a portal.
                $note = sprintf(__('domain-bound: %s', 'entitylogin'), $domain);
            }

            $providers[] = [
                'id'        => (int) $row['id'],
                'name'      => (string) $row['name'],
                'is_active' => (bool) $row['is_active'],
                'note'      => $note,
            ];
        }

        return $providers;
    }

    public function getLoginButtons(array $provider_ids): array
    {
        global $DB;

        if (!$this->isAvailable() || $provider_ids === []) {
            return [];
        }

        $buttons = [];
        $rows = $DB->request([
            'SELECT' => ['id', 'name', 'conf_icon'],
            'FROM'   => self::TABLE,
            'WHERE'  => [
                'id'         => $provider_ids,
                'is_active'  => 1,
                'is_deleted' => 0,
                'OR' => [
                    ['conf_domain' => null],
                    ['conf_domain' => ''],
                    ['conf_domain' => self::PLACEHOLDER_DOMAIN],
                ],
            ],
            'ORDER' => 'name',
        ]);

        foreach ($rows as $row) {
            $buttons[] = [
                'id'      => (int) $row['id'],
                'name'    => (string) $row['name'],
                'kind'    => 'submit',
                'field'   => self::POSTFIELD,
                'icon'    => (string) $row['conf_icon'],
                'href'    => '',
                'picture' => null,
                'style'   => '',
                'popup'   => false,
            ];
        }

        return $buttons;
    }
}
