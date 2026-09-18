
<?php
// ডিবাগ মোড
header('Content-Type: application/json');
echo json_encode([
    'status' => 'debug',
    'message' => 'radius.php is working',
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI']
]);
exit;
