<?php
declare(strict_types=1);
namespace tests;

use PDO;
use PHPUnit\Framework\TestCase;

/** Real cookie/login/write routes against a disposable audit_* database. */
final class UserSessionHttpTest extends TestCase
{
    public function testRevokedCookiesCannotCommentFavoriteRedeemOrRecord(): void
    {
        $dsn=getenv('FEIFEI_AUDIT_MYSQL_DSN'); if(!$dsn || !extension_loaded('curl')) self::markTestSkipped('Disposable MySQL and curl required');
        if(!preg_match('/;dbname=(audit_[a-z0-9_]+)(?:;|$)/D',$dsn,$db)) self::fail('Refusing non-audit database');
        $pdo=new PDO($dsn,getenv('FEIFEI_AUDIT_MYSQL_USER') ?: 'root',getenv('FEIFEI_AUDIT_MYSQL_PASS') ?: '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $name='http-'.bin2hex(random_bytes(8)); $password='Audit-only-password-123';
        $pdo->prepare("INSERT INTO ffx_users(username,password_hash,status) VALUES (?,?,'active')")->execute([$name,password_hash($password,PASSWORD_BCRYPT)]); $user=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO ffx_media(title,slug,status) VALUES (?,?,'published')")->execute([$name,$name]); $media=(int)$pdo->lastInsertId();
        $socket=stream_socket_server('tcp://127.0.0.1:0'); $address=stream_socket_get_name($socket,false); fclose($socket);
        preg_match('/host=([^;]+)/',$dsn,$host); preg_match('/;port=(\d+)/',$dsn,$port); $env=getenv();
        foreach(['ENV_NAME'=>'isolated-'.$name,'DB_HOST'=>$host[1],'DB_PORT'=>$port[1] ?? '3306','DB_NAME'=>$db[1],'DB_USER'=>getenv('FEIFEI_AUDIT_MYSQL_USER') ?: 'root','DB_PASS'=>getenv('FEIFEI_AUDIT_MYSQL_PASS') ?: '', 'CACHE_DRIVER'=>'file','SEARCH_DRIVER'=>'mysql'] as $key=>$value) $env['PHP_'.$key]=$value;
        $root=dirname(__DIR__); $log=tempnam(sys_get_temp_dir(),'ff-http-');
        $process=proc_open([PHP_BINARY,'-S',$address,'-t',$root.'/public',$root.'/public/router.php'],[0=>['file','/dev/null','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root,$env); self::assertIsResource($process);
        $curl=curl_init(); curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>5]);
        $request=function(string $path,?array $post=null,bool $json=false) use($curl,$address): array {
            curl_setopt($curl,CURLOPT_URL,'http://'.$address.$path); curl_setopt($curl,CURLOPT_HTTPHEADER,['Accept: application/json','Content-Type: '.($json?'application/json':'application/x-www-form-urlencoded')]);
            if($post!==null) { curl_setopt($curl,CURLOPT_POST,true); curl_setopt($curl,CURLOPT_POSTFIELDS,$json?json_encode($post):http_build_query($post)); } else curl_setopt($curl,CURLOPT_HTTPGET,true);
            $body=curl_exec($curl); return [(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),(string)$body];
        };
        try {
            for($i=0;$i<60;$i++) { $probe=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($probe) { fclose($probe); break; } usleep(50000); }
            foreach(["status='disabled',session_version=session_version+1","deleted_at=UTC_TIMESTAMP(),session_version=session_version+1","password_hash='changed',session_version=session_version+1"] as $mutation) {
              foreach(['/user/favorite'=>['id'=>$media],'/forum/post'=>['vod_id'=>$media,'content'=>'audit'],'/guestbook'=>['content'=>'audit'],'/user/history'=>['media_id'=>$media],'/user/redeem'=>['card_number'=>'fake'],'/danmu/send'=>['id'=>'audit-'.$media,'text'=>'audit','time'=>1], '/user/info'=>null] as $path=>$fields) {
                // Each endpoint gets a newly established then revoked session.
                // A rejected request clears server-side authentication, so merely
                // replaying the same cookie would not test subsequent endpoints.
                curl_setopt($curl,CURLOPT_COOKIELIST,'ALL');
                $pdo->prepare("UPDATE ffx_users SET status='active',deleted_at=NULL,password_hash=? WHERE id=?")->execute([password_hash($password,PASSWORD_BCRYPT),$user]);
                [$code,$body]=$request('/user/login'); self::assertSame(200,$code,$body);
                self::assertSame(1,preg_match('/name="_token"[^>]*value="([^"]+)"/',$body,$match),$body); $token=$match[1];
                [$code,$body]=$request('/user/login',['_token'=>$token,'user_name'=>$name,'user_pwd'=>$password]); self::assertSame(302,$code,$body);
                [$code,$body]=$request('/user/info'); self::assertSame(200,$code,$body); self::assertSame(1,json_decode($body,true)['logged']);
                $pdo->exec('UPDATE ffx_users SET '.$mutation.' WHERE id='.$user);
                // Reactivation must not resurrect a revoked session version.
                if ($path === '/user/info') $pdo->exec("UPDATE ffx_users SET status='active',deleted_at=NULL WHERE id=".$user);
                [$code,$body]=$request($path,$fields===null ? null : $fields+['_token'=>$token],$path==='/danmu/send'); self::assertSame(401,$code,$path.':'.$body);
              }
            }
            self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM ffx_favorites WHERE user_id='.$user)->fetchColumn());
            self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM ffx_comments WHERE user_id='.$user)->fetchColumn());
        } finally {
            unset($request,$curl); proc_terminate($process); proc_close($process); if(is_file($log)) unlink($log);
            $pdo->exec('DELETE FROM ffx_media WHERE id='.$media); $pdo->exec('DELETE FROM ffx_users WHERE id='.$user);
        }
    }
}
