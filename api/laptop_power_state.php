<?php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['error' => 'Manual virtual power controls have been retired. Device availability is reported by the authorized Active Directory monitoring agent.']);
