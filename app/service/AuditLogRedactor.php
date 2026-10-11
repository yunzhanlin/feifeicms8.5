<?php
declare(strict_types=1);
namespace app\service;

use think\facade\Db;

/** Historical logs only; credentials themselves and backup files are untouched. */
final class AuditLogRedactor
{
    public function run(bool $apply = false): array
    {
        $scanned=$changed=0;
        Db::table('ffx_audit_logs')->field('id,before_data,after_data')->order('id')->chunk(200,function($rows) use ($apply,&$scanned,&$changed): void {
            foreach($rows as $row) {
                $scanned++; $updates=[];
                foreach(['before_data','after_data'] as $field) {
                    if(!is_string($row[$field] ?? null)) continue;
                    $original=json_decode($row[$field],true,64,JSON_THROW_ON_ERROR);
                    if(!is_array($original)) continue;
                    $safe=AuditLogger::redact($original);
                    if($safe!==$original) $updates[$field]=json_encode($safe,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                }
                if($updates===[]) continue;
                $changed++;
                if($apply) {
                    // A concurrent change to a log must not be overwritten.
                    $query=Db::table('ffx_audit_logs')->where('id',(int)$row['id']);
                    foreach(array_keys($updates) as $field) $query->where($field,$row[$field]);
                    $query->update($updates);
                }
            }
        },'id');
        return ['mode'=>$apply?'apply':'dry-run','scanned'=>$scanned,'changed'=>$changed];
    }
}
