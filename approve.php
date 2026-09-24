// approve.php
require 'db.php';
$token = $_GET['token'] ?? '';
$action = $_GET['action'] ?? '';

if($token && in_array($action, ['approve','decline'])){
    $status = ($action == 'approve') ? 'approved' : 'declined';

    $stmt = $conn->prepare("UPDATE asset_approvals SET status=? WHERE token=?");
    $stmt->bind_param("ss", $status, $token);
    $stmt->execute();

    // If approved → activate laptop
    if($status == 'approved'){
        $stmt2 = $conn->prepare("UPDATE laptops l
            JOIN asset_approvals a ON l.id = a.laptop_id
            SET l.status='issued'
            WHERE a.token=?");
        $stmt2->bind_param("s",$token);
        $stmt2->execute();
    } elseif($status == 'declined'){
        $stmt2 = $conn->prepare("UPDATE laptops l
            JOIN asset_approvals a ON l.id = a.laptop_id
            SET l.status='active', assigned_to=NULL
            WHERE a.token=?");
        $stmt2->bind_param("s",$token);
        $stmt2->execute();
    }

    echo "You have {$status} this assignment.";
} else {
    echo "Invalid request.";
}