<?php
date_default_timezone_set("Asia/Tokyo");

if (session_status() === PHP_SESSION_NONE) {
    $session_lifetime = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime', $session_lifetime);
    ini_set('session.cookie_lifetime', $session_lifetime);
    ini_set('session.cookie_path',     '/');
    ini_set('session.cookie_domain',   'aiknowledgecms.exbridge.jp');
    ini_set('session.cookie_secure',   '1');
    ini_set('session.cookie_httponly',  '1');
    session_cache_expire(60 * 24 * 30);
    session_start();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), session_id(),
            time() + $session_lifetime, '/',
            'aiknowledgecms.exbridge.jp', true, true);
    }
}

$BASE_URL   = 'https://aiknowledgecms.exbridge.jp';
$THIS_FILE  = 'nextpost.php';
$ADMIN      = 'xb_bittensor';
$DATA_DIR   = __DIR__ . '/data';
$LOG_FILE   = $DATA_DIR . '/nextpost_log.json';

$x_keys_file = __DIR__ . '/x_api_keys.sh';
$x_keys = array();
if (file_exists($x_keys_file)) {
    $lines = file($x_keys_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (preg_match('/(?:export\s+)?(\w+)=["\']?([^"\'#\r\n]*)["\']?/', $line, $m)) {
            $x_keys[trim($m[1])] = trim($m[2]);
        }
    }
}
$x_client_id     = isset($x_keys['X_API_KEY'])    ? $x_keys['X_API_KEY']    : '';
$x_client_secret = isset($x_keys['X_API_SECRET']) ? $x_keys['X_API_SECRET'] : '';
$x_redirect_uri  = $BASE_URL . '/' . $THIS_FILE;

function np_b64url($d){return rtrim(strtr(base64_encode($d),'+/','-_'),'=');}
function np_verifier(){$b='';for($i=0;$i<32;$i++){$b.=chr(mt_rand(0,255));}return np_b64url($b);}
function np_challenge($v){return np_b64url(hash('sha256',$v,true));}
function np_http_post($url,$data,$headers){
    $opts=array('http'=>array('method'=>'POST','header'=>implode("\r\n",$headers)."\r\n",'content'=>$data,'timeout'=>15,'ignore_errors'=>true));
    $r=@file_get_contents($url,false,stream_context_create($opts));
    return json_decode($r?$r:'{}',true);
}
function np_http_get($url,$token){
    $opts=array('http'=>array('method'=>'GET','header'=>"Authorization: Bearer $token\r\nUser-Agent: NextPost/1.0\r\n",'timeout'=>12,'ignore_errors'=>true));
    $r=@file_get_contents($url,false,stream_context_create($opts));
    return json_decode($r?$r:'{}',true);
}

if(isset($_GET['np_logout'])){session_destroy();setcookie(session_name(),'',time()-3600,'/','aiknowledgecms.exbridge.jp',true,true);header('Location: '.$x_redirect_uri);exit;}
if(isset($_GET['np_login'])){
    $ver=np_verifier();$chal=np_challenge($ver);$state=md5(uniqid('',true));
    $_SESSION['np_ver']=$ver;$_SESSION['np_state']=$state;
    $p=array('response_type'=>'code','client_id'=>$x_client_id,'redirect_uri'=>$x_redirect_uri,'scope'=>'tweet.read tweet.write users.read offline.access','state'=>$state,'code_challenge'=>$chal,'code_challenge_method'=>'S256');
    header('Location: https://twitter.com/i/oauth2/authorize?'.http_build_query($p));exit;
}
if(isset($_GET['code'],$_GET['state'],$_SESSION['np_state'])&&$_GET['state']===$_SESSION['np_state']){
    $cred=base64_encode($x_client_id.':'.$x_client_secret);
    $data=np_http_post('https://api.twitter.com/2/oauth2/token',http_build_query(array('grant_type'=>'authorization_code','code'=>$_GET['code'],'redirect_uri'=>$x_redirect_uri,'code_verifier'=>$_SESSION['np_ver'],'client_id'=>$x_client_id)),array('Content-Type: application/x-www-form-urlencoded','Authorization: Basic '.$cred));
    if(!empty($data['access_token'])){
        $_SESSION['session_access_token']=$data['access_token'];
        $_SESSION['session_token_expires']=time()+(isset($data['expires_in'])?(int)$data['expires_in']:7200);
        if(!empty($data['refresh_token'])){$_SESSION['session_refresh_token']=$data['refresh_token'];}
        unset($_SESSION['np_state'],$_SESSION['np_ver']);
        $me=np_http_get('https://api.twitter.com/2/users/me',$data['access_token']);
        if(!empty($me['data']['username'])){$_SESSION['session_username']=$me['data']['username'];}
    }
    header('Location: '.$x_redirect_uri);exit;
}
if(!empty($_SESSION['session_refresh_token'])&&!empty($_SESSION['session_token_expires'])&&time()>$_SESSION['session_token_expires']-300){
    $cred_r=base64_encode($x_client_id.':'.$x_client_secret);
    $ref=np_http_post('https://api.twitter.com/2/oauth2/token',http_build_query(array('grant_type'=>'refresh_token','refresh_token'=>$_SESSION['session_refresh_token'],'client_id'=>$x_client_id)),array('Content-Type: application/x-www-form-urlencoded','Authorization: Basic '.$cred_r));
    if(!empty($ref['access_token'])){$_SESSION['session_access_token']=$ref['access_token'];$_SESSION['session_token_expires']=time()+(isset($ref['expires_in'])?(int)$ref['expires_in']:7200);if(!empty($ref['refresh_token'])){$_SESSION['session_refresh_token']=$ref['refresh_token'];}}
    else{unset($_SESSION['session_access_token'],$_SESSION['session_refresh_token'],$_SESSION['session_token_expires'],$_SESSION['session_username']);}
}

$logged_in    = !empty($_SESSION['session_access_token']);
$session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
$is_admin     = ($session_user === $ADMIN);

function h($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}

function np_post_tweet($text,$reply_id,$quote_url,$bearer_token){
    $url='https://api.twitter.com/2/tweets';
    $payload=array('text'=>$text);
    if($reply_id!==''){$payload['reply']=array('in_reply_to_tweet_id'=>$reply_id);}
    if($quote_url!==''&&preg_match('/(\d{10,20})/',$quote_url,$m)){$payload['quote_tweet_id']=$m[1];}
    $opts=array('http'=>array('method'=>'POST','header'=>"Authorization: Bearer $bearer_token\r\nContent-Type: application/json\r\nUser-Agent: NextPost/1.0\r\n",'content'=>json_encode($payload),'timeout'=>20,'ignore_errors'=>true));
    $r=@file_get_contents($url,false,stream_context_create($opts));
    return json_decode($r?$r:'{}',true);
}

/* 手動ネタ操作 */
function np_item_file($id){global $DATA_DIR;return $DATA_DIR.'/nextpost_'.preg_replace('/[^a-zA-Z0-9_\-]/','',  $id).'.json';}
function np_load_item($id){$f=np_item_file($id);if(!file_exists($f))return null;return json_decode(file_get_contents($f),true);}
function np_save_item($item){file_put_contents(np_item_file($item['id']),json_encode($item,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));}
function np_load_manual_items(){
    global $DATA_DIR;
    $files=glob($DATA_DIR.'/nextpost_*.json');if(!$files)return array();
    $items=array();
    foreach($files as $f){
        if(strpos($f,'nextpost_log')!==false)continue;
        $d=json_decode(file_get_contents($f),true);
        if(is_array($d)&&isset($d['id'])){$items[]=$d;}
    }
    usort($items,function($a,$b){return strcmp($b['created_at'],$a['created_at']);});
    return $items;
}

/* url2aiシステム定義 */
$URL2AI_SYSTEMS=array(
    'xinsight'=>array('name'=>'XInsight', 'key'=>'insight',           'src'=>'xi','tpl'=>'/xinsightv.php?id={id}'),
    'ustory'  =>array('name'=>'UStory',   'key'=>'story',             'src'=>'xi','tpl'=>'/ustoryv.php?id={id}'),
    'usong'   =>array('name'=>'USong',    'key'=>'lyrics',            'src'=>'xi','tpl'=>'/usongv.php?id={id}'),
    'udebate' =>array('name'=>'UDebate',  'key'=>'debate_conclusion', 'src'=>'xi','tpl'=>'/udebatev.php?id={id}'),
    'umedia'  =>array('name'=>'UMedia',   'key'=>'media_insight',     'src'=>'xi','tpl'=>'/umediav.php?id={id}'),
    'uparse'  =>array('name'=>'UParse',   'key'=>'parse_result',      'src'=>'xi','tpl'=>'/uparsev.php?id={id}'),
    'aitech'  =>array('name'=>'AITech',   'key'=>'summary',  'src'=>'json','file'=>'/data/aitech_posts.json','id_key'=>'id','url_key'=>'url',       'tpl'=>'/aitech.php?id={id}'),
    'ainews'  =>array('name'=>'AINwsRdr', 'key'=>'summary',  'src'=>'json','file'=>'/data/ainews_posts.json','id_key'=>'id','url_key'=>'tweet_url', 'tpl'=>'/ainews.php?id={id}'),
    'oss'     =>array('name'=>'OSSTmln',  'key'=>'post_text','src'=>'json','file'=>'/data/oss_posts.json',   'id_key'=>'id','url_key'=>'github_url', 'tpl'=>'/oss.php?id={id}'),
    'osszenn' =>array('name'=>'OSSZenn',  'key'=>'post_text','src'=>'json','file'=>'/data/oss_posts.json',   'id_key'=>'id','url_key'=>'github_url', 'tpl'=>'/osszenn.php?id={id}'),
);

function np_load_url2ai(){
    global $DATA_DIR,$BASE_URL,$URL2AI_SYSTEMS;
    $items=array();
    foreach($URL2AI_SYSTEMS as $sk=>$sys){
        if($sys['src']==='xi'){
            $files=glob($DATA_DIR.'/xinsight_*.json');if(!$files)continue;
            foreach($files as $f){
                $d=json_decode(file_get_contents($f),true);if(!is_array($d))continue;
                $val=isset($d[$sys['key']])?trim($d[$sys['key']]):'';if($val==='')continue;
                $tid=isset($d['tweet_id'])?$d['tweet_id']:'';if($tid==='')continue;
                $items[]=array('id'=>'u2a_'.$sk.'_'.$tid,'sys_key'=>$sk,'sys_name'=>$sys['name'],
                    'body'=>$val,'src_url'=>isset($d['tweet_url'])?$d['tweet_url']:'',
                    'view_url'=>$BASE_URL.str_replace('{id}',urlencode($tid),$sys['tpl']),
                    'date'=>isset($d['saved_at'])?$d['saved_at']:'','type'=>'url2ai',
                    'x_post_ids'=>isset($d['nextpost_x_ids'])?$d['nextpost_x_ids']:array(),
                    'data_file'=>$f);
            }
        } elseif($sys['src']==='json'){
            $fpath=__DIR__.$sys['file'];if(!file_exists($fpath))continue;
            $posts=json_decode(file_get_contents($fpath),true);if(!is_array($posts))continue;
            foreach($posts as $d){
                if(!is_array($d))continue;
                $val=isset($d[$sys['key']])?trim($d[$sys['key']]):'';if($val==='')continue;
                $rid=isset($d[$sys['id_key']])?(string)$d[$sys['id_key']]:'';if($rid==='')continue;
                $rid_s=preg_replace('/[^a-zA-Z0-9_\-]/','_',$rid);
                $items[]=array('id'=>'u2a_'.$sk.'_'.$rid_s,'sys_key'=>$sk,'sys_name'=>$sys['name'],
                    'body'=>$val,'src_url'=>isset($d[$sys['url_key']])?$d[$sys['url_key']]:'',
                    'view_url'=>$BASE_URL.str_replace('{id}',urlencode($rid),$sys['tpl']),
                    'date'=>isset($d['created_at'])?$d['created_at']:'','type'=>'url2ai',
                    'x_post_ids'=>array(),'data_file'=>$fpath,'record_id'=>$rid);
            }
        }
    }
    usort($items,function($a,$b){return strcmp($b['date'],$a['date']);});
    return $items;
}

function np_load_log(){
    global $LOG_FILE;if(!file_exists($LOG_FILE))return array();
    $d=json_decode(file_get_contents($LOG_FILE),true);return is_array($d)?$d:array();
}
function np_append_log($e){
    global $LOG_FILE;$log=np_load_log();array_unshift($log,$e);
    file_put_contents($LOG_FILE,json_encode($log,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}

/* POST処理 */
$msg_ok=$msg_err='';
if($_SERVER['REQUEST_METHOD']==='POST'&&$is_admin){
    $act=isset($_POST['action'])?$_POST['action']:'';

    if($act==='new_item'){
        $body=trim(isset($_POST['body'])?$_POST['body']:'');
        if($body===''){$msg_err='empty';}
        else{
            $id=date('YmdHis').'_'.substr(md5(uniqid('',true)),0,6);
            np_save_item(array('id'=>$id,'body'=>$body,'tags'=>trim(isset($_POST['tags'])?$_POST['tags']:''),'memo'=>trim(isset($_POST['memo'])?$_POST['memo']:''),'created_at'=>date('Y-m-d H:i:s'),'posted'=>false,'x_post_ids'=>array(),'type'=>'manual'));
            $msg_ok='registered: '.$id;
        }
    }
    if($act==='edit_item'){
        $id=trim(isset($_POST['item_id'])?$_POST['item_id']:'');
        $body=trim(isset($_POST['body'])?$_POST['body']:'');
        $item=np_load_item($id);
        if($item&&$body!==''){$item['body']=$body;$item['tags']=trim(isset($_POST['tags'])?$_POST['tags']:'');$item['memo']=trim(isset($_POST['memo'])?$_POST['memo']:'');np_save_item($item);$msg_ok='updated';}
    }
    if($act==='post_tweet'){
        $item_id  =trim(isset($_POST['item_id'])    ?$_POST['item_id']    :'');
        $post_type=trim(isset($_POST['post_type'])  ?$_POST['post_type']  :'tweet');
        $reply_id =trim(isset($_POST['reply_to_id'])?$_POST['reply_to_id']:'');
        $quote_url=trim(isset($_POST['quote_url'])  ?$_POST['quote_url']  :'');
        $post_text=trim(isset($_POST['post_text'])  ?$_POST['post_text']  :'');
        $src_type =trim(isset($_POST['src_type'])   ?$_POST['src_type']   :'manual');
        $data_file=trim(isset($_POST['data_file'])  ?$_POST['data_file']  :'');
        if($post_text===''){$msg_err='empty text';}
        else{
            $res=np_post_tweet($post_text,$post_type==='reply'?$reply_id:'',$post_type==='quote'?$quote_url:'',$_SESSION['session_access_token']);
            if(!empty($res['data']['id'])){
                $xid=$res['data']['id'];
                $xp=array('x_post_id'=>$xid,'post_type'=>$post_type,'username'=>$session_user,'posted_at'=>date('Y-m-d H:i:s'));
                if($src_type==='manual'){$item=np_load_item($item_id);if($item){if(!isset($item['x_post_ids'])){$item['x_post_ids']=array();}$item['x_post_ids'][]=$xp;$item['posted']=true;np_save_item($item);}}
                if($src_type==='url2ai'&&$data_file!==''){
                    $df=realpath($data_file);
                    if($df&&strpos($df,realpath($DATA_DIR))===0&&file_exists($df)){
                        $fd=json_decode(file_get_contents($df),true);
                        if(is_array($fd)){if(!isset($fd['nextpost_x_ids'])){$fd['nextpost_x_ids']=array();}$fd['nextpost_x_ids'][]=$xp;file_put_contents($df,json_encode($fd,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));}
                    }
                }
                np_append_log(array('item_id'=>$item_id,'x_post_id'=>$xid,'post_type'=>$post_type,'username'=>$session_user,'text'=>mb_substr($post_text,0,50),'posted_at'=>date('Y-m-d H:i:s')));
                $msg_ok='posted: '.$xid;
            } else {
                $detail=isset($res['detail'])?$res['detail']:(isset($res['title'])?$res['title']:json_encode($res));
                $msg_err='error: '.$detail;
            }
        }
    }
}

$manual_items = np_load_manual_items();
$url2ai_items = np_load_url2ai();
$log          = np_load_log();
$tab          = isset($_GET['tab'])?$_GET['tab']:'url2ai';
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NextPost</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#f1f5f9;--surface:#fff;--border:#e2e8f0;--border2:#cbd5e1;--accent:#1d9bf0;--accent-h:#1a8cd8;--green:#059669;--red:#dc2626;--text:#0f172a;--muted:#64748b;--mono:'JetBrains Mono',monospace;--sans:'Inter',sans-serif;}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh;font-size:14px}
header{background:var(--surface);border-bottom:1px solid var(--border);padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.logo{font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:8px}
.logo svg{width:18px;height:18px;fill:var(--accent)}
.logo span{color:var(--accent)}
.userbar{display:flex;align-items:center;gap:.75rem;font-size:.8rem;color:var(--muted)}
.userbar strong{color:var(--green)}
.btn-sm{background:none;border:1px solid var(--border2);color:var(--muted);padding:.2rem .7rem;border-radius:4px;font-size:.75rem;cursor:pointer;text-decoration:none;transition:all .15s}
.btn-sm:hover{border-color:var(--red);color:var(--red)}
.wrap{max-width:1180px;margin:0 auto;padding:1.5rem;display:grid;grid-template-columns:1fr 260px;gap:1.5rem;align-items:start}
@media(max-width:860px){.wrap{grid-template-columns:1fr}}
.tabs-bar{display:flex;border-bottom:2px solid var(--border);margin-bottom:1rem}
.tab-btn{padding:.5rem 1.2rem;font-size:.82rem;font-weight:600;cursor:pointer;border:none;background:none;color:var(--muted);border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .15s}
.tab-btn.on{color:var(--accent);border-bottom-color:var(--accent)}
.section{background:var(--surface);border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:1.25rem}
.section-header{padding:.65rem 1rem;border-bottom:1px solid var(--border);background:#f8fafc;display:flex;align-items:center;justify-content:space-between}
.section-title{font-weight:600;font-size:.82rem;color:var(--text);display:flex;align-items:center;gap:.4rem}
.tweet-card{border-bottom:1px solid #f0f0f0;padding:16px;transition:background .1s}
.tweet-card:last-child{border-bottom:none}
.tweet-card:hover{background:#fafafa}
.tweet-card.posted{border-left:3px solid var(--green)}
.tweet-card.not-posted{border-left:3px solid var(--accent)}
.card-top{display:flex;gap:10px;margin-bottom:10px}
.avatar{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:10px;color:#fff;flex-shrink:0;text-align:center;line-height:1.2}
.av-u2a{background:linear-gradient(135deg,#6366f1,#0891b2)}
.av-man{background:linear-gradient(135deg,#1d9bf0,#7c3aed)}
.card-info{flex:1;min-width:0}
.card-name{font-weight:700;font-size:13px;display:flex;align-items:center;gap:6px}
.sys-badge{font-size:10px;font-weight:700;padding:1px 6px;border-radius:4px;background:#eef2ff;color:#6366f1;border:1px solid #c7d2fe}
.card-date{font-size:11px;color:var(--muted);font-family:var(--mono)}
.card-body{font-size:13px;line-height:1.8;white-space:pre-wrap;word-break:break-word;max-height:110px;overflow:hidden;position:relative;margin-bottom:8px}
.card-body::after{content:'';position:absolute;bottom:0;left:0;right:0;height:24px;background:linear-gradient(transparent,#fff)}
.tweet-card:hover .card-body::after{background:linear-gradient(transparent,#fafafa)}
.card-links{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:6px}
.chip{display:inline-flex;align-items:center;gap:3px;font-size:10px;padding:2px 8px;border-radius:10px;text-decoration:none;border:1px solid var(--border2);color:var(--muted);background:#f8fafc;transition:all .15s}
.chip:hover{border-color:var(--accent);color:var(--accent)}
.chip-ok{background:#dcfce7;border-color:#bbf7d0;color:var(--green)}
.xchip{display:inline-flex;align-items:center;gap:3px;font-size:10px;color:var(--accent);background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:2px 7px;font-family:var(--mono);margin:1px}
.xchip a{color:var(--accent);text-decoration:none}
.xchip a:hover{text-decoration:underline}
.card-actions{display:flex;gap:6px;flex-wrap:wrap;padding-top:10px;border-top:1px solid var(--border);margin-top:4px}
.ba{display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:none;transition:all .15s;font-family:var(--sans)}
.ba-t{background:var(--accent);color:#fff}.ba-t:hover{background:var(--accent-h)}
.ba-r{background:#f0f9ff;color:var(--accent);border:1px solid #bfdbfe}.ba-r:hover{background:#dbeafe}
.ba-q{background:#fdf4ff;color:#7c3aed;border:1px solid #e9d5ff}.ba-q:hover{background:#f3e8ff}
.ba-e{background:#f8fafc;color:var(--muted);border:1px solid var(--border2)}.ba-e:hover{color:var(--text);background:#f1f5f9}
.form-row{margin-bottom:.75rem}
.form-label{display:block;font-size:.75rem;font-weight:600;color:var(--muted);margin-bottom:.3rem;text-transform:uppercase;letter-spacing:.04em}
textarea,input[type=text]{width:100%;border:1px solid var(--border2);border-radius:6px;padding:.5rem .75rem;font-size:.85rem;font-family:var(--sans);outline:none;color:var(--text);resize:vertical;transition:border .15s}
textarea:focus,input[type=text]:focus{border-color:var(--accent)}
.cnt{font-size:.72rem;color:var(--muted);text-align:right;margin-top:.2rem;font-family:var(--mono)}
.cnt.over{color:var(--red);font-weight:700}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1.2rem;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;font-family:var(--sans)}
.btn-p{background:var(--accent);color:#fff}.btn-p:hover{background:var(--accent-h)}
.btn-s{background:#f1f5f9;color:var(--text);border:1px solid var(--border2)}.btn-s:hover{background:#e2e8f0}
.btn-g{background:var(--green);color:#fff}.btn-g:hover{background:#047857}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center}
.overlay.open{display:flex}
.modal{background:#fff;border-radius:14px;width:min(520px,96vw);padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.2);position:relative;max-height:90vh;overflow-y:auto}
.modal-title{font-size:15px;font-weight:700;margin-bottom:16px}
.modal-x{position:absolute;top:12px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:var(--muted);line-height:1}
.ptabs{display:flex;gap:3px;background:#f1f5f9;border-radius:8px;padding:3px;margin-bottom:14px}
.ptab{flex:1;padding:6px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:none;background:none;color:var(--muted);transition:all .15s;text-align:center}
.ptab.on{background:#fff;color:var(--text);box-shadow:0 1px 3px rgba(0,0,0,.1)}
.msg{padding:.6rem 1rem;border-radius:6px;font-size:.82rem;font-weight:600;margin-bottom:1rem}
.msg-ok{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
.msg-err{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.log-tbl{width:100%;border-collapse:collapse;font-size:11px}
.log-tbl th{background:#f8fafc;padding:6px 8px;text-align:left;border-bottom:1px solid var(--border);color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.log-tbl td{padding:5px 8px;border-bottom:1px solid var(--border);vertical-align:top}
.log-tbl tr:last-child td{border-bottom:none}
.tbadge{display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase}
.tb-tweet{background:#eff6ff;color:var(--accent)}.tb-reply{background:#f0fdf4;color:var(--green)}.tb-quote{background:#fdf4ff;color:#7c3aed}
.empty{text-align:center;color:var(--muted);padding:32px;font-size:.85rem}
</style>
</head>
<body>
<header>
    <div class="logo">
        <svg viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
        Next<span>Post</span>
    </div>
    <div class="userbar">
        <?php if($logged_in): ?>
        <span>@<strong><?php echo h($session_user);?></strong></span>
        <a href="?np_logout=1" class="btn-sm">logout</a>
        <?php else: ?>
        <a href="?np_login=1" class="btn-sm">X login</a>
        <?php endif; ?>
    </div>
</header>
<div class="wrap">
<div>
<?php if($msg_ok): ?><div class="msg msg-ok">+ <?php echo h($msg_ok);?></div><?php endif; ?>
<?php if($msg_err): ?><div class="msg msg-err">x <?php echo h($msg_err);?></div><?php endif; ?>

<div class="tabs-bar">
    <button class="tab-btn <?php echo $tab==='url2ai'?'on':'';?>" onclick="swTab('url2ai',this)">url2ai (<?php echo count($url2ai_items);?>)</button>
    <button class="tab-btn <?php echo $tab==='manual'?'on':'';?>" onclick="swTab('manual',this)">manual (<?php echo count($manual_items);?>)</button>
</div>

<!-- url2ai tab -->
<div id="tab-url2ai" <?php echo $tab!=='url2ai'?'style="display:none"':'';?>>
<div class="section">
    <div class="section-header">
        <div class="section-title">url2ai</div>
        <select id="sys-filter" onchange="filterSys()" style="font-size:.75rem;padding:3px 6px;border:1px solid var(--border2);border-radius:4px;background:#fff">
            <option value="">all systems</option>
            <?php foreach($URL2AI_SYSTEMS as $sk=>$sys): ?>
            <option value="<?php echo h($sk);?>"><?php echo h($sys['name']);?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if(empty($url2ai_items)): ?><div class="empty">No data.</div>
    <?php else: foreach($url2ai_items as $it): $hp=!empty($it['x_post_ids']); ?>
    <div class="tweet-card <?php echo $hp?'posted':'not-posted';?>" data-sys="<?php echo h($it['sys_key']);?>">
        <div class="card-top">
            <div class="avatar av-u2a"><?php echo h($it['sys_name']);?></div>
            <div class="card-info">
                <div class="card-name"><span class="sys-badge"><?php echo h($it['sys_name']);?></span></div>
                <div class="card-date"><?php echo h($it['date']);?></div>
            </div>
        </div>
        <div class="card-body"><?php echo h($it['body']);?></div>
        <div class="card-links">
            <?php if($it['src_url']): ?><a href="<?php echo h($it['src_url']);?>" target="_blank" class="chip">src</a><?php endif; ?>
            <a href="<?php echo h($it['view_url']);?>" target="_blank" class="chip">view</a>
            <?php if($hp): ?><span class="chip chip-ok">posted</span>
                <?php foreach($it['x_post_ids'] as $xp): ?>
                <span class="xchip"><?php echo h($xp['post_type']);?>&nbsp;<a href="https://x.com/<?php echo h($xp['username']);?>/status/<?php echo h($xp['x_post_id']);?>" target="_blank"><?php echo h(substr($xp['x_post_id'],-8));?>...</a></span>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php if($is_admin): ?>
        <div class="card-actions">
            <button class="ba ba-t" onclick='openPost(<?php echo json_encode($it["id"]);?>,<?php echo json_encode(mb_substr($it["body"],0,200));?>,"tweet","url2ai",<?php echo json_encode($it["data_file"]);?>)'><svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>post</button>
            <button class="ba ba-r" onclick='openPost(<?php echo json_encode($it["id"]);?>,<?php echo json_encode(mb_substr($it["body"],0,200));?>,"reply","url2ai",<?php echo json_encode($it["data_file"]);?>)'>reply</button>
            <button class="ba ba-q" onclick='openPost(<?php echo json_encode($it["id"]);?>,<?php echo json_encode(mb_substr($it["body"],0,200));?>,"quote","url2ai",<?php echo json_encode($it["data_file"]);?>)'>quote</button>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
</div>
</div>

<!-- manual tab -->
<div id="tab-manual" <?php echo $tab!=='manual'?'style="display:none"':'';?>>
<div class="section">
    <div class="section-header">
        <div class="section-title">manual (<?php echo count($manual_items);?>)</div>
        <?php if($is_admin): ?><button class="btn btn-p" style="font-size:.75rem;padding:.3rem .9rem" onclick="openModal('m-new')">+ new</button><?php endif; ?>
    </div>
    <?php if(empty($manual_items)): ?><div class="empty">No items.</div>
    <?php else: foreach($manual_items as $it): $hp=!empty($it['x_post_ids']); ?>
    <div class="tweet-card <?php echo $hp?'posted':'not-posted';?>">
        <div class="card-top">
            <div class="avatar av-man">M</div>
            <div class="card-info">
                <div class="card-name">manual</div>
                <div class="card-date"><?php echo h($it['created_at']);?> #<?php echo h($it['id']);?></div>
            </div>
        </div>
        <div class="card-body" style="max-height:none"><?php echo h($it['body']);?></div>
        <?php if(!empty($it['tags'])): ?><div style="margin-bottom:6px;font-size:11px;color:var(--accent)"><?php echo h($it['tags']);?></div><?php endif; ?>
        <?php if(!empty($it['memo'])): ?><div style="font-size:11px;color:var(--muted);font-style:italic;margin-bottom:6px">memo: <?php echo h($it['memo']);?></div><?php endif; ?>
        <?php if($hp): foreach($it['x_post_ids'] as $xp): ?>
        <span class="xchip"><?php echo h($xp['post_type']);?>&nbsp;<a href="https://x.com/<?php echo h($xp['username']);?>/status/<?php echo h($xp['x_post_id']);?>" target="_blank"><?php echo h($xp['x_post_id']);?></a>&nbsp;<?php echo h(substr($xp['posted_at'],0,16));?></span>
        <?php endforeach; endif; ?>
        <?php if($is_admin): ?>
        <div class="card-actions">
            <button class="ba ba-t" onclick='openPost(<?php echo json_encode($it["id"]);?>,<?php echo json_encode($it["body"]);?>,"tweet","manual","")'>post</button>
            <button class="ba ba-r" onclick='openPost(<?php echo json_encode($it["id"]);?>,<?php echo json_encode($it["body"]);?>,"reply","manual","")'>reply</button>
            <button class="ba ba-q" onclick='openPost(<?php echo json_encode($it["id"]);?>,<?php echo json_encode($it["body"]);?>,"quote","manual","")'>quote</button>
            <button class="ba ba-e" onclick='openEdit(<?php echo json_encode($it["id"]);?>,<?php echo json_encode($it["body"]);?>,<?php echo json_encode(isset($it["tags"])?$it["tags"]:"");?>,<?php echo json_encode(isset($it["memo"])?$it["memo"]:"");?>)'>edit</button>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
</div>
</div>
</div>

<!-- side -->
<div>
<div class="section">
    <div class="section-header"><div class="section-title">log</div></div>
    <?php if(empty($log)): ?><div class="empty">no log</div>
    <?php else: ?>
    <div style="overflow-x:auto"><table class="log-tbl">
    <thead><tr><th>date</th><th>type</th><th>id</th></tr></thead>
    <tbody>
    <?php foreach(array_slice($log,0,50) as $e): ?>
    <tr>
        <td style="white-space:nowrap"><?php echo h(substr($e['posted_at'],5,11));?></td>
        <td><span class="tbadge tb-<?php echo h($e['post_type']);?>"><?php echo h($e['post_type']);?></span></td>
        <td><a href="https://x.com/<?php echo h($e['username']);?>/status/<?php echo h($e['x_post_id']);?>" target="_blank" style="color:var(--accent);font-family:var(--mono);font-size:10px"><?php echo h(substr($e['x_post_id'],-8));?>...</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>
</div>
</div>

<!-- new modal -->
<div class="overlay" id="m-new">
<div class="modal">
<button class="modal-x" onclick="closeModal('m-new')">x</button>
<div class="modal-title">+ new item</div>
<form method="POST"><input type="hidden" name="action" value="new_item">
<div class="form-row"><label class="form-label">body</label><textarea name="body" id="new-body" rows="5" oninput="upC('new-body','new-c')"></textarea><div class="cnt" id="new-c">0 / 280</div></div>
<div class="form-row"><label class="form-label">tags</label><input type="text" name="tags" placeholder="#AI #URL2AI"></div>
<div style="display:flex;gap:8px;justify-content:flex-end"><button type="button" class="btn btn-s" onclick="closeModal('m-new')">cancel</button><button type="submit" class="btn btn-p">save</button></div>
</form></div></div>

<!-- post modal -->
<div class="overlay" id="m-post">
<div class="modal">
<button class="modal-x" onclick="closeModal('m-post')">x</button>
<div class="modal-title" id="ptitle">post to X</div>
<form method="POST"><input type="hidden" name="action" value="post_tweet">
<input type="hidden" name="item_id" id="p-item-id">
<input type="hidden" name="post_type" id="p-type">
<input type="hidden" name="src_type" id="p-src">
<input type="hidden" name="data_file" id="p-file">
<div class="ptabs">
<button type="button" class="ptab on" id="ptab-tweet" onclick="setPT('tweet')">post</button>
<button type="button" class="ptab" id="ptab-reply" onclick="setPT('reply')">reply</button>
<button type="button" class="ptab" id="ptab-quote" onclick="setPT('quote')">quote</button>
</div>
<div id="g-reply" class="form-row" style="display:none"><label class="form-label">reply to id</label><input type="text" id="p-reply-id" name="reply_to_id" placeholder="1234567890123456789"></div>
<div id="g-quote" class="form-row" style="display:none"><label class="form-label">quote url</label><input type="text" id="p-quote-url" name="quote_url" placeholder="https://x.com/..."></div>
<div class="form-row"><label class="form-label">text</label><textarea name="post_text" id="p-text" rows="6" oninput="upC('p-text','p-c')"></textarea><div class="cnt" id="p-c">0 / 280</div></div>
<div style="display:flex;gap:8px;justify-content:flex-end"><button type="button" class="btn btn-s" onclick="closeModal('m-post')">cancel</button><button type="button" class="btn btn-g" onclick="postViaIntent()">post to X</button></div>
</form></div></div>

<!-- edit modal -->
<div class="overlay" id="m-edit">
<div class="modal">
<button class="modal-x" onclick="closeModal('m-edit')">x</button>
<div class="modal-title">edit</div>
<form method="POST"><input type="hidden" name="action" value="edit_item">
<input type="hidden" name="item_id" id="e-id">
<div class="form-row"><label class="form-label">body</label><textarea name="body" id="e-body" rows="5" oninput="upC('e-body','e-c')"></textarea><div class="cnt" id="e-c">0 / 280</div></div>
<div class="form-row"><label class="form-label">tags</label><input type="text" name="tags" id="e-tags"></div>
<div class="form-row"><label class="form-label">memo</label><input type="text" name="memo" id="e-memo"></div>
<div style="display:flex;gap:8px;justify-content:flex-end"><button type="button" class="btn btn-s" onclick="closeModal('m-edit')">cancel</button><button type="submit" class="btn btn-p">update</button></div>
</form></div></div>

<script>
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}
document.querySelectorAll('.overlay').forEach(function(el){el.addEventListener('click',function(e){if(e.target===el)el.classList.remove('open');});});
function upC(tid,cid){var ta=document.getElementById(tid),c=document.getElementById(cid);if(!ta||!c)return;var n=ta.value.length;c.textContent=n+' / 280';c.className='cnt'+(n>280?' over':'');}
function swTab(t,el){document.getElementById('tab-url2ai').style.display=t==='url2ai'?'':'none';document.getElementById('tab-manual').style.display=t==='manual'?'':'none';document.querySelectorAll('.tab-btn').forEach(function(b){b.classList.remove('on');});el.classList.add('on');}
function filterSys(){var v=document.getElementById('sys-filter').value;document.querySelectorAll('#tab-url2ai .tweet-card').forEach(function(c){c.style.display=(v===''||c.dataset.sys===v)?'':'none';});}
function openPost(id,body,type,src,df){document.getElementById('p-item-id').value=id;document.getElementById('p-src').value=src;document.getElementById('p-file').value=df;document.getElementById('p-text').value=body;upC('p-text','p-c');setPT(type);openModal('m-post');}
function setPT(t){document.getElementById('p-type').value=t;['tweet','reply','quote'].forEach(function(x){document.getElementById('ptab-'+x).className='ptab'+(x===t?' on':'');});document.getElementById('g-reply').style.display=t==='reply'?'':'none';document.getElementById('g-quote').style.display=t==='quote'?'':'none';var ti={tweet:'post to X',reply:'reply to X',quote:'quote post to X'};document.getElementById('ptitle').textContent=ti[t]||'post to X';}
function openEdit(id,body,tags,memo){document.getElementById('e-id').value=id;document.getElementById('e-body').value=body;document.getElementById('e-tags').value=tags;document.getElementById('e-memo').value=memo;upC('e-body','e-c');openModal('m-edit');}
function postViaIntent(){
    var text=document.getElementById('p-text').value.trim();
    if(!text){alert('text is empty');return;}
    var type=document.getElementById('p-type').value;
    var replyId=document.getElementById('p-reply-id').value.trim();
    var quoteUrl=document.getElementById('p-quote-url').value.trim();
    var url='https://twitter.com/intent/tweet?text='+encodeURIComponent(text);
    if(type==='reply'&&replyId){url+='&in_reply_to='+encodeURIComponent(replyId);}
    if(type==='quote'&&quoteUrl){url+='&url='+encodeURIComponent(quoteUrl);}
    window.open(url,'_blank','width=600,height=500,noopener,noreferrer');
    closeModal('m-post');
}
</script>
</body>
</html>
