<?php
declare(strict_types=1);
require_once __DIR__ . '/../backend/ad_sync_security.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
function agent_reply(int $status,array $data): never { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { header('Allow: POST'); agent_reply(405,['error'=>'POST required']); }
$token=getenv('AD_SYNC_TOKEN'); $auth=trim((string)($_SERVER['HTTP_AUTHORIZATION']??''));
if ($token===false || strlen($token)<32 || !ad_sync_is_secure_transport() || !preg_match('/^Bearer ([^\s]+)$/',$auth,$m) || !hash_equals($token,$m[1])) agent_reply(401,['error'=>'Authorized HTTPS connector required.']);
if (!ad_sync_rate_limit((string)($_SERVER['REMOTE_ADDR']??'unknown'),60,60)) agent_reply(429,['error'=>'Rate limit exceeded.']);
$body=file_get_contents('php://input',false,null,0,8193); if ($body===false || strlen($body)>8192) agent_reply(413,['error'=>'Request too large.']);
try{$data=json_decode($body,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){agent_reply(400,['error'=>'Invalid JSON.']);}
if (!is_array($data) || !in_array($data['action']??'', ['claim','fail','run_fail'],true)) agent_reply(422,['error'=>'Invalid agent action.']);
require_once __DIR__.'/../db.php';
try {
    $conn->query("INSERT INTO ad_sync_agent_state(id,agent_name,last_poll_at) VALUES(1,'Windows AD connector',UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE agent_name=VALUES(agent_name),last_poll_at=UTC_TIMESTAMP(6)");
    if ($data['action']==='claim') {
        $conn->begin_transaction();
        $result=$conn->query("SELECT id,scope_id FROM ad_sync_requests WHERE status='queued' ORDER BY requested_at,id LIMIT 1 FOR UPDATE");
        $job=$result->fetch_assoc();
        if ($job) { $id=(int)$job['id']; $stmt=$conn->prepare("UPDATE ad_sync_requests SET status='running',started_at=UTC_TIMESTAMP(6) WHERE id=? AND status='queued'");$stmt->bind_param('i',$id);$stmt->execute();$stmt->close(); }
        $conn->commit(); agent_reply(200,['request'=>$job?['id'=>(int)$job['id'],'scope_id'=>$job['scope_id']]:null]);
    }
    $reason=trim((string)($data['reason']??'Synchronization process failed.'));
    if ($reason==='') agent_reply(422,['error'=>'reason is required.']);
    $reason=substr($reason,0,500);
    if ($data['action']==='run_fail') { $runId=$data['run_id']??''; if(!is_string($runId)||!preg_match('/^[0-9a-f-]{36}$/i',$runId)) agent_reply(422,['error'=>'Valid run_id required.']); $stmt=$conn->prepare("UPDATE ad_sync_runs SET status='failed',completed_at=UTC_TIMESTAMP(6) WHERE run_id=? AND status='running'");$stmt->bind_param('s',$runId);$stmt->execute();$stmt->close(); }
    else { $id=filter_var($data['request_id']??null,FILTER_VALIDATE_INT); if(!$id) agent_reply(422,['error'=>'request_id is required.']); $stmt=$conn->prepare("UPDATE ad_sync_runs SET status='failed',completed_at=UTC_TIMESTAMP(6) WHERE run_id=(SELECT run_id FROM ad_sync_requests WHERE id=?) AND status='running'");$stmt->bind_param('i',$id);$stmt->execute();$stmt->close(); $stmt=$conn->prepare("UPDATE ad_sync_requests SET status='failed',finished_at=UTC_TIMESTAMP(6),error_message=? WHERE id=? AND status IN ('queued','running')");$stmt->bind_param('si',$reason,$id);$stmt->execute();$stmt->close(); }
    $error=$conn->prepare('UPDATE ad_sync_agent_state SET last_error=? WHERE id=1');$error->bind_param('s',$reason);$error->execute();$error->close();agent_reply(200,['status'=>'failed']);
} catch(Throwable $e) { if($conn->errno===0){} error_log('AD agent request failed: '.$e->getMessage());agent_reply(503,['error'=>'Agent request could not be processed.']); }
