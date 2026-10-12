<?php

declare(strict_types=1);

namespace App\Services\Sis;

/**
 * Lets SisImporter run on files an administrator uploaded instead of a SIS
 * connection: it answers the importer's questions (is the feature on, is it
 * configured and enabled, which connector) as "yes, the uploaded folder".
 *
 * The importer is used unchanged; this only stands in for the connection.
 * Whether an upload is allowed at all (never while a real SIS connection is
 * enabled) is decided by CsvUploadService before it gets here.
 */
class UploadConnections extends SisConnectionService
{
    public function __construct(private readonly string $folder)
    {
        parent::__construct();
    }

    public function view(int $institutionId): array
    {
        return [
            'configured'       => true,
            'connector'        => SisConnectors::FILE,
            'enabled'          => true,
            'hasCredentials'   => false,
            'credentialsSetAt' => null,
            'flagOn'           => true,
            'folders'          => ['inbound' => $this->folder, 'outbound' => $this->folder],
        ];
    }

    public function connector(int $institutionId): SisConnector
    {
        return new FileConnector($this->folder, $this->folder);
    }
}
