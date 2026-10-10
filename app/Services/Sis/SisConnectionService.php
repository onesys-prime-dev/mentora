<?php

declare(strict_types=1);

namespace App\Services\Sis;

use App\Models\Academic\InstitutionModel;
use App\Services\Flags\FeatureFlags;
use CodeIgniter\Shield\Entities\User;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * An institution's SIS connection: what is configured, saving it (with
 * credentials sealed and never returned), and testing it.
 *
 * The connection can only be switched on while the `sis.sync` feature is on
 * for the institution; it can be configured and tested before that.
 */
class SisConnectionService
{
    public const FLAG = 'sis.sync';

    public function __construct(
        private readonly ?FeatureFlags $flags = null,
        private readonly ?SisRunLog $runs = null,
    ) {
    }

    /**
     * What a page may show. The credentials themselves are not in here, only
     * whether they are set and when they were last changed.
     *
     * @return array{connector: string, enabled: bool, hasCredentials: bool, credentialsSetAt: ?string, flagOn: bool, folders: array{inbound: string, outbound: string}}
     */
    public function view(int $institutionId): array
    {
        $row = $this->row($institutionId);

        return [
            'connector'        => (string) ($row['connector'] ?? SisConnectors::FILE),
            'enabled'          => (int) ($row['enabled'] ?? 0) === 1,
            'hasCredentials'   => ($row['credentials'] ?? null) !== null,
            'credentialsSetAt' => $row['credentials_set_at'] ?? null,
            'flagOn'           => $this->flags()->isEnabled(self::FLAG, $institutionId),
            'folders'          => SisConnectors::folders($institutionId),
        ];
    }

    /**
     * @param array<string, mixed> $input credential field => new value; blank keeps what is saved
     *
     * @throws InvalidArgumentException when the settings are refused
     * @throws SisException             when credentials cannot be sealed
     */
    public function save(int $institutionId, bool $enabled, array $input, bool $clearCredentials, User $actor, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();

        if ((new InstitutionModel())->find($institutionId) === null) {
            throw new InvalidArgumentException('That institution does not exist.');
        }

        if ($enabled && ! $this->flags()->isEnabled(self::FLAG, $institutionId)) {
            throw new InvalidArgumentException('Turn on the "' . self::FLAG . '" feature for this institution first (Features tab), then enable the connection.');
        }

        $existing = $this->row($institutionId);
        $type     = (string) ($existing['connector'] ?? SisConnectors::FILE);
        $fields   = SisConnectors::types()[$type]['fields'];

        $changes = [];

        foreach ($fields as $name => $definition) {
            $value = $input[$name] ?? '';
            $value = is_string($value) ? trim($value) : '';

            if ($value === '') {
                continue;
            }

            if (mb_strlen($value) < $definition['min'] || mb_strlen($value) > 255) {
                throw new InvalidArgumentException("{$definition['label']} must be {$definition['min']} to 255 characters.");
            }

            $changes[$name] = $value;
        }

        $values = [
            'connector'          => $type,
            'enabled'            => $enabled ? 1 : 0,
            'updated_by_user_id' => $actor->id,
            'updated_at'         => $now->format('Y-m-d H:i:s'),
        ];

        $vault = service('credentialVault');

        if ($clearCredentials) {
            $values['credentials']        = null;
            $values['credentials_set_at'] = null;
        } elseif ($changes !== []) {
            $current                      = ($existing['credentials'] ?? null) === null ? [] : $vault->open((string) $existing['credentials']);
            $values['credentials']        = $vault->seal(array_merge($current, $changes));
            $values['credentials_set_at'] = $now->format('Y-m-d H:i:s');
        }

        $db = db_connect();
        $db->transBegin();

        try {
            if ($existing === null) {
                $db->table('sis_connections')->insert($values + ['institution_id' => $institutionId, 'created_at' => $now->format('Y-m-d H:i:s')]);
            } else {
                $db->table('sis_connections')->where('institution_id', $institutionId)->update($values);
            }

            service('auditLogger')->log('sis.connection_saved', $actor, [
                'target_type'    => 'institution',
                'target_id'      => $institutionId,
                'institution_id' => $institutionId,
                'old'            => ['enabled' => (int) ($existing['enabled'] ?? 0) === 1, 'login_saved' => ($existing['credentials'] ?? null) !== null],
                'new'            => ['enabled' => $enabled, 'login_saved' => array_key_exists('credentials', $values) ? $values['credentials'] !== null : ($existing['credentials'] ?? null) !== null, 'login_replaced' => $clearCredentials || $changes !== []],
            ]);
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        $db->transCommit();
    }

    /**
     * A connector for the institution, as saved. For the sync and grade
     * export; not for pages.
     *
     * @throws SisException when it is not configured or its credentials cannot be opened
     */
    public function connector(int $institutionId): SisConnector
    {
        $row  = $this->row($institutionId);
        $type = (string) ($row['connector'] ?? SisConnectors::FILE);

        $credentials = ($row['credentials'] ?? null) === null ? [] : service('credentialVault')->open((string) $row['credentials']);

        return SisConnectors::make($type, $institutionId, $credentials);
    }

    /** Tests the saved connection, records the run and audits it. */
    public function test(int $institutionId, User $actor, ?DateTimeImmutable $now = null): ConnectionResult
    {
        $now ??= new DateTimeImmutable();
        $runs  = $this->runs ?? new SisRunLog();
        $runId = $runs->start($institutionId, SisRunLog::INBOUND, SisRunLog::TEST, (int) $actor->id, false, $now);

        try {
            $result = $this->connector($institutionId)->testConnection();
        } catch (SisException $e) {
            $result = new ConnectionResult(false, $e->getMessage());
        }

        $runs->finish($runId, $result->ok, [], $result->message, $now);

        service('auditLogger')->log('sis.connection_tested', $actor, [
            'target_type'    => 'institution',
            'target_id'      => $institutionId,
            'institution_id' => $institutionId,
            'new'            => ['ok' => $result->ok],
        ]);

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function row(int $institutionId): ?array
    {
        return db_connect()->table('sis_connections')->where('institution_id', $institutionId)->get()->getRowArray();
    }

    private function flags(): FeatureFlags
    {
        return $this->flags ?? new FeatureFlags();
    }
}
