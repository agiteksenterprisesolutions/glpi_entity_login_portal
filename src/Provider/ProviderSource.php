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
 * Adapter over one SSO plugin.
 *
 * A source reports the identity providers that plugin offers and describes how
 * a login button for one of them must be rendered, so this plugin can present a
 * filtered button list without the SSO plugin needing to know about portals.
 *
 * Implementations must only ever READ from the SSO plugin's tables.
 */
interface ProviderSource
{
    /**
     * Stable key stored alongside each mapping. Must match the SSO plugin's
     * directory name, and must never change once released.
     */
    public function getKey(): string;

    /**
     * Human readable name of the SSO plugin, for the admin UI.
     */
    public function getLabel(): string;

    /**
     * Whether the SSO plugin is installed and active on this instance.
     */
    public function isAvailable(): bool;

    /**
     * Every provider the SSO plugin knows about, for the admin UI.
     *
     * @return array<int, array{id:int, name:string, is_active:bool, note:string}>
     *         `note` explains why a provider would not be displayed, or ''.
     */
    public function getSelectableProviders(): array;

    /**
     * The providers among $provider_ids that should actually be shown, in
     * display order.
     *
     * @param int[] $provider_ids
     * @return array<int, array{id:int, name:string, kind:string, icon:string, href:string, picture:?string, style:string, popup:bool}>
     *         `kind` is 'submit' (posts the login form) or 'link'.
     */
    public function getLoginButtons(array $provider_ids): array;
}
