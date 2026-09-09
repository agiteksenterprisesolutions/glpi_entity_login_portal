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

/**
 * Holds the portal being rendered for the current request.
 *
 * The portal is resolved by the controller before the login template is
 * rendered; the DISPLAY_LOGIN hook then reads it back. It is deliberately
 * request-scoped rather than stored in the session: a portal is a property of
 * the URL being visited, not a sticky property of the visitor.
 *
 * @license GPLv3+
 */

namespace GlpiPlugin\Entitylogin;

class PortalContext
{
    private static ?Portal $portal = null;

    public static function set(?Portal $portal): void
    {
        self::$portal = $portal;
    }

    public static function get(): ?Portal
    {
        return self::$portal;
    }

    public static function clear(): void
    {
        self::$portal = null;
    }
}
