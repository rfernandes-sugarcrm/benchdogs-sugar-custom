<?php

namespace Sugarcrm\Sugarcrm\custom\Erp;

/** Who is saving: the platform the request signed in with. */
class ErpConnectorSession
{
    /** The ERP connector's platform (Ext/Platforms). */
    public const CONNECTOR = 'sugarai_erp_connector';

    /** The initial-load seed loader's platform. */
    public const SEED_LOADER = 'epicor_seed';

    /** The writers that copy the ERP's own values rather than choose them. */
    public const ERP_WRITERS = array(self::CONNECTOR, self::SEED_LOADER);

    /** The request's platform, or null outside a signed-in request (a job, the command line). */
    public static function platform(): ?string
    {
        return isset($_SESSION['platform']) ? (string) $_SESSION['platform'] : null;
    }

    /** Whether this save comes from the connector itself. */
    public static function isConnector(): bool
    {
        return self::platform() === self::CONNECTOR;
    }

    /** Whether a platform is one of the ERP's own writers. */
    public static function isErpWriter(?string $platform): bool
    {
        return $platform !== null && in_array($platform, self::ERP_WRITERS, true);
    }
}
