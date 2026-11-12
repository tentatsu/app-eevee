<?php

namespace App\Services\IpAccess;

use App\Models\IpAllowEntry;
use App\Models\Organization;
use App\Support\Ip\IpRange;
use Illuminate\Support\Facades\Log;

/**
 * 管理画面のIP許可リストを判定する。
 *
 * - 組織: ip_allow_entries.organization_id = 組織ID の行
 * - システム管理画面: .env の SYSTEM_IP_ALLOWLIST と ip_allow_entries.organization_id IS NULL の行の合算
 * - リストが空なら制限なし
 */
class IpAllowlistService
{
    /**
     * @return list<string>
     */
    public function rangesFor(?Organization $organization): array
    {
        if ($organization === null) {
            return array_values(array_unique(array_merge(
                $this->envSystemRanges(),
                $this->dbRanges(null),
            )));
        }

        return $this->dbRanges($organization);
    }

    /**
     * @return list<string>
     */
    public function envSystemRanges(): array
    {
        return IpRange::parseList((string) config('eevee.system_ip_allowlist'));
    }

    /**
     * @return list<string>
     */
    public function dbRanges(?Organization $organization): array
    {
        $query = IpAllowEntry::query();
        $organization === null
            ? $query->whereNull('organization_id')
            : $query->where('organization_id', $organization->id);

        /** @var list<string> $ranges */
        $ranges = $query->pluck('cidr')->all();

        return $ranges;
    }

    public function isAllowed(?Organization $organization, ?string $ip): bool
    {
        $ranges = $this->rangesFor($organization);

        return $ranges === [] || IpRange::matches($ip, $ranges);
    }

    /**
     * 新しいリストを保存したときに、現在のIPが締め出されるか。
     *
     * @param  list<string>  $ranges
     */
    public function wouldLockOut(array $ranges, ?string $currentIp): bool
    {
        return $ranges !== [] && ! IpRange::matches($currentIp, $ranges);
    }

    /**
     * 許可リストを置き換える。
     *
     * @param  list<array{cidr: string, note?: string|null}>  $entries
     */
    public function replace(?Organization $organization, array $entries): void
    {
        $query = IpAllowEntry::query();
        $organization === null
            ? $query->whereNull('organization_id')
            : $query->where('organization_id', $organization->id);
        $query->delete();

        foreach ($entries as $entry) {
            IpAllowEntry::query()->create([
                'organization_id' => $organization?->id,
                'cidr' => $entry['cidr'],
                'note' => $entry['note'] ?? null,
            ]);
        }
    }

    public function clear(?Organization $organization): int
    {
        $query = IpAllowEntry::query();
        $organization === null
            ? $query->whereNull('organization_id')
            : $query->where('organization_id', $organization->id);

        return $query->delete();
    }

    /**
     * 管理画面のテキストエリア（1行1件。「CIDR メモ」または「CIDR # メモ」）を解析する。
     *
     * @return array{entries: list<array{cidr: string, note: string|null}>, errors: list<string>}
     */
    public function parseInput(?string $input): array
    {
        $entries = [];
        $errors = [];
        $seen = [];
        foreach (preg_split('/\R/', (string) $input) ?: [] as $i => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 2) ?: [];
            $raw = $parts[0] ?? '';
            $note = isset($parts[1]) ? trim(ltrim(trim($parts[1]), '#')) : null;
            $cidr = IpRange::normalize($raw);
            if ($cidr === null) {
                $errors[] = ($i + 1).'行目: 「'.$raw.'」は IP アドレスまたは CIDR として正しくありません。';

                continue;
            }
            if (isset($seen[$cidr])) {
                continue;
            }
            $seen[$cidr] = true;
            $entries[] = ['cidr' => $cidr, 'note' => $note !== '' ? mb_substr((string) $note, 0, 191) : null];
        }

        return ['entries' => $entries, 'errors' => $errors];
    }

    /**
     * @param  iterable<IpAllowEntry>  $entries
     */
    public static function toInput(iterable $entries): string
    {
        $lines = [];
        foreach ($entries as $entry) {
            $lines[] = $entry->cidr.($entry->note ? ' # '.$entry->note : '');
        }

        return implode("\n", $lines);
    }

    public function logDenied(?Organization $organization, ?string $ip, string $path): void
    {
        Log::channel('security')->warning('Admin access denied by IP allowlist', [
            'scope' => $organization ? 'organization' : 'system',
            'organization' => $organization?->slug,
            'ip' => $ip,
            'path' => $path,
        ]);
    }
}
