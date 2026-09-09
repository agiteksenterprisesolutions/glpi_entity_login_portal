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
 * Adapter for the Single Sign-On plugin (edgardmessias/glpi-singlesignon).
 *
 * Unlike samlSSO, this plugin renders its buttons as plain links to its OAuth
 * callback, and it replaces GLPI's login template with its own. Emitting the
 * same callback URL reuses its entire OAuth flow; suppressing its unfiltered
 * button list is handled separately in \GlpiPlugin\Entitylogin\LoginRenderer.
 */
final class SinglesignonSource implements ProviderSource
{
    public const KEY = 'singlesignon';

    private const TABLE = 'glpi_plugin_singlesignon_providers';

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getLabel(): string
    {
        return 'Single Sign-On (OAuth)';
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
            'SELECT' => ['id', 'name', 'is_active'],
            'FROM'   => self::TABLE,
            'ORDER'  => 'name',
        ]);

        foreach ($rows as $row) {
            $providers[] = [
                'id'        => (int) $row['id'],
                'name'      => (string) $row['name'],
                'is_active' => (bool) $row['is_active'],
                'note'      => (bool) $row['is_active'] ? '' : __('inactive', 'entitylogin'),
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
            'SELECT' => ['id', 'name', 'picture', 'bgcolor', 'color', 'popup'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['id' => $provider_ids, 'is_active' => 1],
            'ORDER'  => 'name',
        ]);

        foreach ($rows as $row) {
            $buttons[] = [
                'id'      => (int) $row['id'],
                'name'    => (string) $row['name'],
                'kind'    => 'link',
                'field'   => '',
                'icon'    => 'ti ti-lock',
                'href'    => $this->getCallbackUrl((int) $row['id']),
                'picture' => !empty($row['picture']) ? $this->getPictureUrl((string) $row['picture']) : null,
                'style'   => $this->buildStyle($row),
                'popup'   => (bool) $row['popup'],
            ];
        }

        return $buttons;
    }

    /**
     * Delegates to the SSO plugin's own URL builder so the OAuth callback stays
     * byte-identical to the one it would have produced itself. The literal is
     * only a fallback for the unlikely case that the class moves.
     */
    private function getCallbackUrl(int $provider_id): string
    {
        global $CFG_GLPI;

        $toolbox = 'GlpiPlugin\\Singlesignon\\ToolboxPlugin';
        if (class_exists($toolbox) && method_exists($toolbox, 'getCallbackUrl')) {
            try {
                return (string) $toolbox::getCallbackUrl($provider_id);
            } catch (\Throwable) {
                // Fall through to the literal below.
            }
        }

        return $CFG_GLPI['root_doc'] . '/plugins/singlesignon/front/callback.php/provider/' . $provider_id;
    }

    /**
     * Delegates to the SSO plugin's own picture URL builder, as above.
     */
    private function getPictureUrl(string $picture): ?string
    {
        $toolbox = 'GlpiPlugin\\Singlesignon\\ToolboxPlugin';
        if (class_exists($toolbox) && method_exists($toolbox, 'getPictureUrl')) {
            try {
                return $toolbox::getPictureUrl($picture);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function buildStyle(array $row): string
    {
        $styles = [];
        if (!empty($row['bgcolor'])) {
            $styles[] = 'background-color: ' . $row['bgcolor'];
        }
        if (!empty($row['color'])) {
            $styles[] = 'color: ' . $row['color'];
        }

        return implode(';', $styles);
    }
}
