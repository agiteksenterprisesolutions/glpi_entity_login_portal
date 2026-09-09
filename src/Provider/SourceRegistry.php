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

/**
 * The SSO plugins this plugin knows how to read.
 *
 * To support another SSO plugin, implement {@see ProviderSource} and add it to
 * {@see SourceRegistry::all()}. Nothing else needs to change.
 */
final class SourceRegistry
{
    /** @var ProviderSource[]|null */
    private static ?array $sources = null;

    /**
     * @return ProviderSource[] keyed by source key
     */
    public static function all(): array
    {
        if (self::$sources === null) {
            self::$sources = [];
            foreach ([new SamlssoSource(), new SinglesignonSource()] as $source) {
                self::$sources[$source->getKey()] = $source;
            }
        }

        return self::$sources;
    }

    /**
     * Only the sources whose SSO plugin is actually installed and active.
     *
     * @return ProviderSource[] keyed by source key
     */
    public static function available(): array
    {
        return array_filter(self::all(), static fn(ProviderSource $s) => $s->isAvailable());
    }

    public static function get(string $key): ?ProviderSource
    {
        return self::all()[$key] ?? null;
    }
}
