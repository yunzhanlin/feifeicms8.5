<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/** Literal, bounded replacements. Identifiers come only from the current schema. */
final class DatabaseFieldReplacer
{
    public const MAX_ROWS = 50000;
    private const TEXT_TYPES = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json'];
    private const PROTECTED_TABLES = [
        'ffx_admins', 'ffx_roles', 'ffx_permissions', 'ffx_admin_roles', 'ffx_role_permissions',
        'ffx_orders', 'ffx_cards', 'ffx_media_entitlements', 'ffx_schema_versions',
        'ffx_site_settings', 'ffx_audit_logs', 'ffx_jobs', 'ffx_collection_jobs',
        'ffx_cron_tasks', 'ffx_search_state', 'ffx_search_outbox', 'ffx_legacy_map',
    ];
    private const LABELS = [
        'ffx_media' => '视频', 'ffx_categories' => '分类', 'ffx_play_sources' => '播放线路',
        'ffx_episodes' => '播放分集与地址', 'ffx_scenarios' => '分集剧情', 'ffx_seasons' => '季',
        'ffx_media_assets' => '视频附件', 'ffx_people' => '人物与角色', 'ffx_media_people' => '演职员',
        'ffx_articles' => '文章', 'ffx_topics' => '专题', 'ffx_tags' => '标签',
        'ffx_users' => '用户', 'ffx_comments' => '评论', 'ffx_danmaku' => '弹幕',
        'ffx_collection_sources' => '采集源', 'ffx_external_refs' => '来源引用',
        'ffx_players' => '播放器', 'ffx_navigation' => '导航', 'ffx_slides' => '轮播',
        'ffx_links' => '友情链接', 'ffx_ads' => '广告',
    ];

    public function tables(PDO $pdo): array
    {
        $tables = $pdo->query("SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME LIKE 'ffx\\_%' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tables as &$table) {
            $name = (string) $table['name'];
            $table['label'] = self::LABELS[$name] ?? $name;
            $table['reason'] = $this->tableReason($name, (string) $table['engine']);
        }
        unset($table);
        return $tables;
    }

    public function fields(PDO $pdo, string $table): array
    {
        $this->identifier($table);
        $found = array_values(array_filter($this->tables($pdo), static fn (array $item): bool => $item['name'] === $table));
        if ($found === []) throw new InvalidArgumentException('数据表不存在，或不是当前站点的 ffx_* 数据表');
        $query = $pdo->prepare('SELECT COLUMN_NAME AS name, DATA_TYPE AS type, COLUMN_TYPE AS definition, COLUMN_KEY AS key_type, GENERATION_EXPRESSION AS expression FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $query->execute([$table]);
        $fields = $query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($fields as &$field) {
            $name = (string) $field['name'];
            $this->identifier($name);
            $field['reason'] = $found[0]['reason'];
            if ($field['reason'] === '') {
                $field['reason'] = $this->sensitive($name) ? '敏感字段不可批量替换'
                    : (($field['expression'] ?? '') !== '' ? '生成字段不可直接修改'
                    : ($field['key_type'] === 'PRI' ? '主键不可批量替换'
                    : (!in_array($field['type'], self::TEXT_TYPES, true) ? '仅支持文本和 JSON 内容字段' : '')));
            }
            $field['editable'] = $field['reason'] === '';
        }
        unset($field);
        return $fields;
    }

    /** Parse a small SQL-looking condition language; NEVER pass user SQL through. */
    public function condition(string $condition, array $fields): array
    {
        if (strlen($condition) > 4096) throw new InvalidArgumentException('替换条件过长');
        $condition = trim($condition);
        if ($condition === '') return ['sql' => '', 'values' => []];
        $allowed = array_column($fields, null, 'name');
        $clauses = []; $values = []; $offset = 0;
        $pattern = '~\G\s*([a-z][a-z0-9_]*)\s*(?:(IS\s+NOT\s+NULL|IS\s+NULL)|(>=|<=|<>|!=|=|>|<)\s*(?:\'((?:[^\']|\'\')*)\'|"((?:[^"]|"")*)"|(-?[0-9]+(?:\.[0-9]+)?)))\s*~i';
        while ($offset < strlen($condition)) {
            if (count($clauses) >= 8 || preg_match($pattern, $condition, $match, PREG_UNMATCHED_AS_NULL, $offset) !== 1) {
                throw new InvalidArgumentException('条件仅支持字段比较和 AND，例如 id>=100 AND status=\'published\'；不支持原始 SQL');
            }
            $name = $match[1];
            if (!isset($allowed[$name]) || $this->sensitive($name)) throw new InvalidArgumentException('条件字段不存在或属于敏感字段：' . $name);
            $quoted = $this->identifier($name);
            if ($match[2] !== null) {
                $clauses[] = $quoted . ' ' . strtoupper((string) preg_replace('/\s+/', ' ', $match[2]));
            } else {
                $clauses[] = $quoted . ' ' . $match[3] . ' ?';
                $values[] = $match[4] !== null ? str_replace("''", "'", $match[4])
                    : ($match[5] !== null ? str_replace('""', '"', $match[5]) : $match[6]);
            }
            $offset += strlen($match[0]);
            if ($offset === strlen($condition)) break;
            if (preg_match('/\GAND\s+/i', $condition, $separator, 0, $offset) !== 1) throw new InvalidArgumentException('多个条件只能使用 AND 连接');
            $offset += strlen($separator[0]);
            if ($offset === strlen($condition)) throw new InvalidArgumentException('AND 后缺少条件');
        }
        return ['sql' => ' AND (' . implode(' AND ', $clauses) . ')', 'values' => $values];
    }

    public function preview(PDO $pdo, array $input): array
    {
        $plan = $this->plan($pdo, $input);
        $count = $this->count($pdo, $plan);
        $keys = array_column(array_filter($plan['fields'], static fn (array $field): bool => $field['key_type'] === 'PRI'), 'name');
        $quotedKeys = array_map($this->identifier(...), $keys);
        $rowKey = $keys === [] ? "''" : "LEFT(CONCAT_WS(','," . implode(',', $quotedKeys) . '),200)';
        $order = $keys === [] ? '' : ' ORDER BY ' . implode(',', $quotedKeys);
        // Retrieve only a bounded window around the first literal match, not entire LONGTEXTs.
        $query = $pdo->prepare('SELECT /*+ MAX_EXECUTION_TIME(10000) */ ' . $rowKey . ' AS row_key, SUBSTRING(' . $plan['value'] . ',GREATEST(1,LOCATE(CONVERT(? USING utf8mb4) COLLATE utf8mb4_bin,' . $plan['value'] . ' COLLATE utf8mb4_bin)-80),800) AS original FROM ' . $plan['table'] . ' WHERE ' . $plan['where'] . $order . ' LIMIT 10');
        $query->execute([$input['search'], ...$plan['params']]);
        $samples = $query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($samples as &$sample) $sample['replacement'] = str_replace($input['search'], $input['replacement'], $sample['original']);
        unset($sample);
        return ['matched' => $count, 'samples' => $samples];
    }

    /** One transaction also covers the idempotency receipt and the caller's audit entry. */
    public function replace(PDO $pdo, array $input, int $expected, string $receipt, ?callable $audit = null): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $receipt)) throw new InvalidArgumentException('替换确认无效，请重新预览');
        if ($expected < 1 || $expected > self::MAX_ROWS) throw new InvalidArgumentException('没有可替换的记录，或匹配数量超过限制');
        if ($pdo->inTransaction()) throw new InvalidArgumentException('不能在其他事务中执行字段替换');
        $plan = $this->plan($pdo, $input);
        $fingerprint = hash('sha256', json_encode([$input, $expected], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $key = 'database.replace:' . $receipt;
        $session = $pdo->query('SELECT @@SESSION.sql_mode AS mode, @@SESSION.innodb_lock_wait_timeout AS wait_time')->fetch(PDO::FETCH_ASSOC);
        $setMode = $pdo->prepare('SET SESSION sql_mode=?');
        try {
            $setMode->execute([trim($session['mode'] . ',STRICT_ALL_TABLES', ',')]);
            $pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
            $pdo->beginTransaction();
            try {
                $insert = $pdo->prepare("INSERT INTO ffx_jobs (job_type,idempotency_key,payload,state,attempts,reserved_at) VALUES ('database.field_replace',?,?,'running',1,UTC_TIMESTAMP(6))");
                $insert->execute([$key, json_encode(['fingerprint' => $fingerprint], JSON_THROW_ON_ERROR)]);
            } catch (PDOException $error) {
                if ((int) ($error->errorInfo[1] ?? 0) !== 1062) throw $error;
                // A parallel retry waits on the unique key, then observes the committed receipt.
                $existing = $pdo->prepare('SELECT state,payload FROM ffx_jobs WHERE idempotency_key=? FOR UPDATE');
                $existing->execute([$key]);
                $row = $existing->fetch(PDO::FETCH_ASSOC);
                $data = json_decode((string) ($row['payload'] ?? ''), true);
                if (($row['state'] ?? '') !== 'completed' || !is_array($data) || ($data['fingerprint'] ?? '') !== $fingerprint) {
                    throw new InvalidArgumentException('这次确认已被使用，请重新预览');
                }
                $pdo->commit();
                return ['matched' => (int) $data['matched'], 'changed' => (int) $data['changed'], 'replayed' => true];
            }
            if ($this->count($pdo, $plan) !== $expected) throw new InvalidArgumentException('匹配数量已变化，请重新预览后再确认');
            $updatedAt = array_column($plan['fields'], 'type', 'name')['updated_at'] ?? '';
            $timestamp = in_array($updatedAt, ['datetime', 'timestamp'], true) ? ',`updated_at`=UTC_TIMESTAMP(6)' : '';
            $update = $pdo->prepare('UPDATE ' . $plan['table'] . ' SET ' . $plan['field'] . '=REPLACE(' . $plan['value'] . ',?,?)' . $timestamp . ' WHERE ' . $plan['where'] . ' LIMIT ' . (self::MAX_ROWS + 1));
            $update->execute([$input['search'], $input['replacement'], ...$plan['params']]);
            $changed = $update->rowCount();
            if ($changed > $expected) throw new InvalidArgumentException('替换期间匹配范围已变化，已回滚，请重新预览');
            if ($audit !== null) $audit($changed);
            $data = ['fingerprint' => $fingerprint, 'table' => $input['table'], 'field' => $input['field'], 'matched' => $expected, 'changed' => $changed];
            $complete = $pdo->prepare("UPDATE ffx_jobs SET state='completed',payload=?,finished_at=UTC_TIMESTAMP(6) WHERE idempotency_key=?");
            $complete->execute([json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $key]);
            $pdo->commit();
            return ['matched' => $expected, 'changed' => $changed, 'replayed' => false];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        } finally {
            try {
                $setMode->execute([$session['mode']]);
                $pdo->exec('SET SESSION innodb_lock_wait_timeout=' . (int) $session['wait_time']);
            } catch (PDOException) {
                // A disconnected session cannot be reused. Do not obscure a
                // committed receipt, or replace the original transaction error.
                error_log('FeiFeiCMS: field replacement connection cleanup failed');
            }
        }
    }

    private function plan(PDO $pdo, array $input): array
    {
        foreach (['table', 'field', 'search', 'replacement', 'condition'] as $key) {
            if (!isset($input[$key]) || !is_string($input[$key]) || !mb_check_encoding($input[$key], 'UTF-8')) throw new InvalidArgumentException('替换参数无效');
        }
        if ($input['search'] === '' || mb_strlen($input['search']) > 500 || mb_strlen($input['replacement']) > 5000) throw new InvalidArgumentException('被替换内容不能为空且不超过 500 字，新内容不超过 5000 字');
        if ($input['search'] === $input['replacement']) throw new InvalidArgumentException('原内容与新内容相同，无需替换');
        $fields = $this->fields($pdo, $input['table']);
        $columns = array_column($fields, null, 'name');
        if (!isset($columns[$input['field']])) throw new InvalidArgumentException('要替换的字段不存在');
        $selected = $columns[$input['field']];
        if (!$selected['editable']) throw new InvalidArgumentException($selected['reason']);
        $filter = $this->condition($input['condition'], $fields);
        $field = $this->identifier($input['field']);
        $value = 'CONVERT(' . $field . ' USING utf8mb4)';
        return [
            'table' => $this->identifier($input['table']), 'field' => $field, 'value' => $value, 'fields' => $fields,
            'where' => 'INSTR(CAST(' . $value . ' AS BINARY),CAST(? AS BINARY))>0' . $filter['sql'],
            'params' => [$input['search'], ...$filter['values']],
        ];
    }

    private function count(PDO $pdo, array $plan): int
    {
        $query = $pdo->prepare('SELECT /*+ MAX_EXECUTION_TIME(10000) */ COUNT(*) FROM (SELECT 1 FROM ' . $plan['table'] . ' WHERE ' . $plan['where'] . ' LIMIT ' . (self::MAX_ROWS + 1) . ') AS matches');
        $query->execute($plan['params']);
        $count = (int) $query->fetchColumn();
        if ($count > self::MAX_ROWS) throw new InvalidArgumentException('匹配超过 50000 条，请通过替换条件缩小范围后分批处理');
        return $count;
    }

    private function tableReason(string $table, string $engine): string
    {
        if (!preg_match('/^ffx_[a-z0-9_]+$/D', $table)) return '不是可维护的站点数据表';
        if (in_array($table, self::PROTECTED_TABLES, true)) return '系统、权限、账务或任务表，请使用对应管理功能修改';
        return strtoupper($engine) !== 'INNODB' ? '仅支持可事务回滚的 InnoDB 表' : '';
    }

    private function sensitive(string $field): bool
    {
        return preg_match('/(?:password|passwd|pwd|secret|token|credential|api_?key|checksum|(?:^|_)hash(?:_|$))/i', $field) === 1;
    }

    private function identifier(string $name): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name)) throw new InvalidArgumentException('数据表或字段名称无效');
        return '`' . $name . '`';
    }
}
