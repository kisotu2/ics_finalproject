<?php
require __DIR__.'/bootstrap.php';
if(!empty($_SESSION['user_id'])) audit($conn,'logout','user',(int)$_SESSION['user_id']);
$_SESSION=[]; if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',['expires'=>time()-42000,'path'=>$p['path'],'domain'=>$p['domain'],'secure'=>$p['secure'],'httponly'=>$p['httponly'],'samesite'=>'Lax']);} session_destroy();header('Location: login.php');exit;
