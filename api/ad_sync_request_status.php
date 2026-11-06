<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php'; require_login(['admin','super_admin']);
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT); if(!$id){http_response_code(400);echo json_encode(['error'=>'Valid request id required.']);exit;}
$stmt=$conn->prepare('SELECT id,status,requested_at,started_at,finished_at,run_id,summary,error_message FROM ad_sync_requests WHERE id=?');$stmt->bind_param('i',$id);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();
if(!$row){http_response_code(404);echo json_encode(['error'=>'Request not found.']);exit;}
$row['summary']=$row['summary']?json_decode($row['summary'],true):null; echo json_encode($row,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
