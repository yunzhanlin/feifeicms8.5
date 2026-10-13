<?php
declare(strict_types=1);

namespace app\db;

use app\service\MediaSearchOutbox;
use PDOStatement;
use think\db\BaseQuery;

/** Keep the journal atomic with ORM writes without requiring MySQL TRIGGER/SUPER. */
final class Mysql extends \think\db\connector\Mysql
{
    private ?bool $databaseTriggers = null;
    private ?string $journalInsertId = null;
    private int $journalSuspended = 0;

    /** Restoration already holds the search lock and a durable full-rebuild marker. */
    public function withoutSearchJournal(callable $operation): mixed
    {
        $this->journalSuspended++;
        try { return $operation(); }
        finally { $this->journalSuspended--; $this->databaseTriggers = null; }
    }

    public function getBuilderClass(): string { return \think\db\builder\Mysql::class; }

    private function track(BaseQuery $query, bool $update = false): bool
    {
        if ($this->journalSuspended > 0 || $query->getTable() !== 'ffx_media') return false;
        if ($update && array_intersect(array_keys((array) $query->getOption('data', [])), MediaSearchOutbox::FIELDS) === []) return false;
        $this->initConnect(true);
        return !($this->databaseTriggers ??= MediaSearchOutbox::hasTriggers($this->getPdo()));
    }

    public function insert(BaseQuery $query, bool $getLastInsID = false)
    {
        if (!$this->track($query)) return parent::insert($query, $getLastInsID);
        return $this->transaction(function () use ($query, $getLastInsID) {
            $result = parent::insert($query, $getLastInsID);
            if ($result) {
                $autoId = (string) $this->getAutoID();
                $id = (int) ($query->getOption('data', [])['id'] ?? $autoId);
                MediaSearchOutbox::enqueue($this->getPdo(), $id);
                // Outbox INSERT must not overwrite the caller's last insert ID.
                $this->journalInsertId = $autoId;
            }
            return $result;
        });
    }

    public function update(BaseQuery $query): int
    {
        if (!$this->track($query, true)) return parent::update($query);
        return $this->transaction(function () use ($query): int {
            $this->queueMatches($query);
            return parent::update($query);
        });
    }

    public function delete(BaseQuery $query): int
    {
        if (!$this->track($query)) return parent::delete($query);
        return $this->transaction(function () use ($query): int {
            $this->queueMatches($query);
            return parent::delete($query);
        });
    }

    private function queueMatches(BaseQuery $query): void
    {
        $selection = clone $query;
        $selection->removeOption('data')->removeOption('field')->removeOption('cache');
        $selection->field($query->getTable(true) . '.id')->lock(true);
        $selection->parseOptions();
        $sql = $this->builder->select($selection);
        // Preserve ORM bindings and execute the ID selection with constant memory.
        $this->getPDOStatement('INSERT INTO ffx_search_outbox (media_id) ' . $sql
            . ' ON DUPLICATE KEY UPDATE revision=revision+1', $selection->getBind(), true)->closeCursor();
    }

    public function insertAll(BaseQuery $query, array $dataSet = []): int
    {
        if (!$this->track($query)) return parent::insertAll($query, $dataSet);
        return $this->transaction(function () use ($query, $dataSet): int {
            $count = parent::insertAll($query, $dataSet);
            if ($count > 0) {
                $autoId = (string) $this->getAutoID();
                MediaSearchOutbox::enqueue($this->getPdo(), 0);
                $this->journalInsertId = $autoId;
            }
            return $count;
        });
    }

    public function insertAllByKeys(BaseQuery $query, array $keys, array $values): int
    {
        if (!$this->track($query)) return parent::insertAllByKeys($query, $keys, $values);
        return $this->transaction(function () use ($query, $keys, $values): int {
            $count = parent::insertAllByKeys($query, $keys, $values);
            if ($count > 0) {
                $autoId = (string) $this->getAutoID();
                MediaSearchOutbox::enqueue($this->getPdo(), 0);
                $this->journalInsertId = $autoId;
            }
            return $count;
        });
    }

    public function execute(string $sql, array $bind = []): int
    {
        // Unknown raw SQL is safely journalled as a full-rebuild request, not
        // parsed for guessed IDs. DDL and writes to other tables are unchanged.
        $mediaWrite = preg_match('~^\s*(?:UPDATE|DELETE\s+FROM|REPLACE\s+INTO|INSERT(?:\s+IGNORE)?\s+INTO)\s+`?ffx_media`?(?=\s|\()~i', $sql) === 1;
        if (!$mediaWrite || $this->journalSuspended > 0) return parent::execute($sql, $bind);
        $this->initConnect(true);
        if ($this->databaseTriggers ??= MediaSearchOutbox::hasTriggers($this->getPdo())) return parent::execute($sql, $bind);
        return $this->transaction(function () use ($sql, $bind): int {
            $count = parent::execute($sql, $bind);
            if ($count > 0) {
                $autoId = (string) $this->getAutoID();
                MediaSearchOutbox::enqueue($this->getPdo(), 0);
                $this->journalInsertId = $autoId;
            }
            return $count;
        });
    }

    public function getAutoID(?string $sequence = null)
    {
        return $sequence === null && $this->journalInsertId !== null ? $this->journalInsertId : parent::getAutoID($sequence);
    }

    public function getPDOStatement(string $sql, array $bind = [], bool $master = false, bool $procedure = false): PDOStatement
    {
        if (preg_match('~^\s*(?:INSERT|REPLACE|UPDATE|DELETE)\b~i', $sql)
            && !preg_match('~^\s*INSERT\s+INTO\s+`?ffx_search_outbox`?\b~i', $sql)) {
            $this->journalInsertId = null;
        }
        return parent::getPDOStatement($sql, $bind, $master, $procedure);
    }
}
