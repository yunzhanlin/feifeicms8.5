<?php
declare(strict_types=1);
namespace FeifeiUsage;

use PDO;

final class Statistics
{
    public readonly PDO $db;

    public function __construct(string $path, private readonly string $secret)
    {
        $this->db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA busy_timeout=3000; PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
        $this->db->exec('CREATE TABLE IF NOT EXISTS installations (
            id TEXT PRIMARY KEY, version TEXT NOT NULL, php TEXT, source TEXT NOT NULL,
            first_seen INTEGER NOT NULL, last_seen INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS installations_last_seen ON installations(last_seen);
            CREATE TABLE IF NOT EXISTS activity (
                day TEXT NOT NULL, installation_id TEXT NOT NULL REFERENCES installations(id) ON DELETE CASCADE,
                PRIMARY KEY(day,installation_id));
            CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, expires INTEGER NOT NULL);');
        $this->migrateInstallations();
    }

    private function migrateInstallations(): void
    {
        $columns = array_column($this->db->query('PRAGMA table_info(installations)')->fetchAll(), 'name');
        $missing = array_diff(['domain', 'ip'], $columns);
        if ($missing === []) return;
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $columns = array_column($this->db->query('PRAGMA table_info(installations)')->fetchAll(), 'name');
            foreach (array_diff($missing, $columns) as $column) {
                $this->db->exec('ALTER TABLE installations ADD COLUMN ' . $column . ' TEXT');
            }
            $this->db->exec('COMMIT');
        } catch (\Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
    }

    public static function validate(mixed $input): array
    {
        if (!is_array($input)) throw new \InvalidArgumentException('Invalid fields');
        $schema = $input['schema'] ?? null;
        $allowed = ['schema', 'installation_id', 'version', 'source', 'php'];
        if ($schema === 2) $allowed[] = 'domain';
        if (array_diff(array_keys($input), $allowed) !== []) throw new \InvalidArgumentException('Invalid fields');
        if (!in_array($schema, [1, 2], true)
            || !is_string($input['installation_id'] ?? null)
            || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $input['installation_id'])
            || !is_string($input['version'] ?? null) || strlen($input['version']) > 64
            || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]{6}(?:\.[0-9]+)?(?:-[A-Za-z0-9.-]+)?$/D', $input['version'])
            || !in_array($input['source'] ?? null, ['web','cron'], true)
            || (isset($input['php']) && (!is_string($input['php']) || !preg_match('/^[0-9]{1,2}\.[0-9]{1,2}$/D', $input['php'])))) {
            throw new \InvalidArgumentException('Invalid heartbeat');
        }
        if (array_key_exists('domain', $input)) {
            $domain = is_string($input['domain']) ? strtolower($input['domain']) : '';
            if (!str_contains($domain, '.') || filter_var($domain, FILTER_VALIDATE_IP)
                || !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                throw new \InvalidArgumentException('Invalid domain');
            }
            $input['domain'] = $domain;
        }
        return $input;
    }

    public function receive(array $payload, string $address, ?int $now = null): bool
    {
        $payload = self::validate($payload);
        $now ??= time();
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->allow('heartbeat', $address, 1200, $now)) { $this->db->exec('COMMIT'); return false; }
            $exists = $this->db->prepare('SELECT 1 FROM installations WHERE id=?');
            $exists->execute([$payload['installation_id']]);
            if (!$exists->fetchColumn() && !$this->allow('new', $address, 30, $now)) { $this->db->exec('COMMIT'); return false; }
            $ip = $payload['schema'] === 2 && filter_var($address, FILTER_VALIDATE_IP) ? $address : null;
            $this->db->prepare('INSERT INTO installations(id,version,php,source,first_seen,last_seen,domain,ip) VALUES (?,?,?,?,?,?,?,?)
                ON CONFLICT(id) DO UPDATE SET version=excluded.version,php=excluded.php,source=excluded.source,last_seen=excluded.last_seen,
                    domain=COALESCE(excluded.domain,installations.domain),ip=COALESCE(excluded.ip,installations.ip)')
                ->execute([$payload['installation_id'], $payload['version'], $payload['php'] ?? null, $payload['source'], $now, $now,
                    $payload['domain'] ?? null, $ip]);
            $this->db->prepare('INSERT OR IGNORE INTO activity(day,installation_id) VALUES (?,?)')->execute([gmdate('Y-m-d', $now), $payload['installation_id']]);
            $this->db->prepare('DELETE FROM activity WHERE day<?')->execute([gmdate('Y-m-d', $now - 90 * 86400)]);
            $this->db->prepare('DELETE FROM rate_limits WHERE expires<?')->execute([$now]);
            $this->db->exec('COMMIT');
            return true;
        } catch (\Throwable $error) { $this->db->exec('ROLLBACK'); throw $error; }
    }

    /** Rate limits use short-lived hourly HMAC buckets. */
    public function allow(string $scope, string $address, int $limit, ?int $now = null): bool
    {
        $now ??= time();
        $bucket = hash_hmac('sha256', $scope . '|' . gmdate('Y-m-d-H', $now) . '|' . $address, $this->secret);
        $this->db->prepare('INSERT INTO rate_limits(bucket,hits,expires) VALUES (?,1,?) ON CONFLICT(bucket) DO UPDATE SET hits=hits+1')
            ->execute([$bucket, $now + 86400]);
        $query = $this->db->prepare('SELECT hits FROM rate_limits WHERE bucket=?');
        $query->execute([$bucket]);
        return (int) $query->fetchColumn() <= $limit;
    }

    public function summary(?int $now = null): array
    {
        $now ??= time();
        $query = $this->db->prepare('SELECT COUNT(*) AS total,
            COALESCE(SUM(last_seen>=?),0) AS active_7d,COALESCE(SUM(last_seen>=?),0) AS active_30d,
            COALESCE(SUM(first_seen>=?),0) AS new_7d,MAX(last_seen) AS last_report FROM installations');
        $query->execute([$now - 7 * 86400, $now - 30 * 86400, $now - 7 * 86400]);
        $counts = $query->fetch();
        foreach (['total','active_7d','active_30d','new_7d'] as $key) $counts[$key] = (int) $counts[$key];
        $groups = [];
        foreach (['version','php'] as $field) {
            $query = $this->db->prepare("SELECT COALESCE($field,'未提供') AS label,COUNT(*) AS count FROM installations WHERE last_seen>=? GROUP BY $field ORDER BY count DESC,label LIMIT 100");
            $query->execute([$now - 30 * 86400]);
            $groups[$field] = $query->fetchAll();
        }
        $query = $this->db->prepare('SELECT day,COUNT(*) AS count FROM activity WHERE day>=? GROUP BY day ORDER BY day');
        $query->execute([gmdate('Y-m-d', $now - 29 * 86400)]);
        $activity = array_column($query->fetchAll(), 'count', 'day');
        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', $now - $i * 86400);
            $days[] = ['day' => $day, 'count' => (int) ($activity[$day] ?? 0)];
        }
        return ['counts' => $counts, 'versions' => $groups['version'], 'php' => $groups['php'], 'days' => $days, 'generated_at' => gmdate('c', $now)];
    }

    public function installations(int $page, string $search): array
    {
        $page = max(1, min(1000000, $page));
        $filter = '';
        $params = [];
        if ($search !== '') {
            $filter = " WHERE (instr(domain, ?) > 0 OR instr(ip, ?) > 0 OR instr(id, ?) > 0)";
            $params = array_fill(0, 3, strtolower(substr($search, 0, 253)));
        }
        $count = $this->db->prepare('SELECT COUNT(*) FROM installations' . $filter);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $query = $this->db->prepare('SELECT id,domain,ip,version,first_seen,last_seen FROM installations' . $filter
            . ' ORDER BY last_seen DESC,id LIMIT 50 OFFSET ' . (($page - 1) * 50));
        $query->execute($params);
        return ['total' => $total, 'items' => $query->fetchAll(), 'page' => $page];
    }
}
