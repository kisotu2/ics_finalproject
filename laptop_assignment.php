<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
require __DIR__ . '/bootstrap.php';
require_login(['admin', 'super_admin']);

function profile_for(string $rank, string $workType): array {
    $highDemand = in_array($workType, ['Development', 'Design', 'Data analysis'], true);
    if ($highDemand || in_array($rank, ['Director', 'Senior manager'], true)) {
        return ['tier' => 'Performance', 'ram' => 16, 'storage' => 512];
    }
    if (in_array($rank, ['Manager', 'Officer'], true)) {
        return ['tier' => 'Standard', 'ram' => 8, 'storage' => 256];
    }
    return ['tier' => 'Standard', 'ram' => 8, 'storage' => 256];
}

function recommendation_score(array $laptop, array $need, string $department): int {
    $tierPoints = ['Standard' => 1, 'Performance' => 2, 'Workstation' => 3];
    $score = 0;
    $score += ($laptop['department'] === '' || $laptop['department'] === $department) ? 25 : 10;
    $score += min(35, max(0, ((int)$laptop['ram_gb'] - $need['ram'] + 8) * 4));
    $score += min(25, max(0, ((int)$laptop['storage_gb'] - $need['storage'] + 128) / 16));
    $score += (($tierPoints[$laptop['processor_tier']] ?? 1) >= ($tierPoints[$need['tier']] ?? 1)) ? 15 : 0;
    return min(100, (int)$score);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $userId = (int) ($_POST['user_id'] ?? 0);
    $laptopId = (int) ($_POST['laptop_id'] ?? 0);
    $department = trim($_POST['department'] ?? '');
    $rank = trim($_POST['organisation_rank'] ?? '');
    $workType = trim($_POST['work_type'] ?? '');
    if (!$userId || !$laptopId || !$department || !$rank || !$workType) {
        flash('Complete the user, department, rank and work type fields.', 'error');
    } else {
        $conn->begin_transaction();
        try {
            $user = $conn->prepare('UPDATE users SET department = ?, organisation_rank = ? WHERE id = ? AND status = \'active\'');
            $user->bind_param('ssi', $department, $rank, $userId); $user->execute();
            $asset = $conn->prepare("UPDATE laptops SET assigned_to = ?, status = 'Assigned', department = ? WHERE id = ? AND status = 'Available'");
            $asset->bind_param('isi', $userId, $department, $laptopId); $asset->execute();
            if (!$asset->affected_rows) { throw new RuntimeException('The selected laptop is no longer available.'); }
            $history = $conn->prepare("INSERT INTO laptop_history (laptop_id,user_id,admin_id,action_type,action_date) VALUES (?,?,?,'Needs-based assignment',NOW())");
            $history->bind_param('iii', $laptopId, $userId, $_SESSION['user_id']); $history->execute();
            audit($conn, 'needs_based_assignment', 'laptop', $laptopId, ['user_id'=>$userId, 'department'=>$department, 'rank'=>$rank, 'work_type'=>$workType]);
            $conn->commit(); flash('Laptop assigned using the needs-based recommendation.');
        } catch (Throwable $e) { $conn->rollback(); flash($e->getMessage(), 'error'); }
    }
    header('Location: laptop_assignment.php'); exit;
}

$users = $conn->query("SELECT id, full_name, department, organisation_rank FROM users WHERE role = 'user' AND status = 'active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
$laptops = $conn->query("SELECT id, asset_tag, brand, model, department, processor_tier, ram_gb, storage_gb FROM laptops WHERE status = 'Available' ORDER BY created_at ASC")->fetch_all(MYSQLI_ASSOC);
$selectedUser = (int) ($_GET['user_id'] ?? 0);
$department = trim($_GET['department'] ?? ''); $rank = trim($_GET['rank'] ?? ''); $workType = trim($_GET['work_type'] ?? '');
$recommendations = [];
if ($selectedUser && $department && $rank && $workType) {
    $need = profile_for($rank, $workType);
    foreach ($laptops as $laptop) { $laptop['score'] = recommendation_score($laptop, $need, $department); $recommendations[] = $laptop; }
    usort($recommendations, fn($a, $b) => $b['score'] <=> $a['score']);
}
layout_start('Needs-based laptop assignment');
?>
<section class="hero"><div><div class="eyebrow">ASSIGNMENT RECOMMENDATION</div><h1>Match a laptop to the work</h1><p>Record the employee’s department, organisational rank and work type. The recommendation model ranks available laptops by suitability.</p></div></section>
<section class="panel"><form method="get" class="grid">
<label>Employee<select name="user_id" required><option value="">Select employee</option><?php foreach ($users as $user): ?><option value="<?= $user['id'] ?>" <?= $selectedUser===$user['id']?'selected':'' ?>><?= e($user['full_name']) ?></option><?php endforeach; ?></select></label>
<label>Department<input name="department" required value="<?= e($department) ?>" placeholder="e.g. Finance"></label>
<label>Organisational rank<select name="rank" required><option value="">Select rank</option><?php foreach (['Staff','Officer','Manager','Senior manager','Director'] as $option): ?><option <?= $rank===$option?'selected':'' ?>><?= e($option) ?></option><?php endforeach; ?></select></label>
<label>Primary work<select name="work_type" required><option value="">Select work</option><?php foreach (['Administration','Finance','Development','Design','Data analysis'] as $option): ?><option <?= $workType===$option?'selected':'' ?>><?= e($option) ?></option><?php endforeach; ?></select></label>
<div><button>Find suitable laptops</button></div></form></section>
<?php if ($recommendations): $need = profile_for($rank, $workType); ?><section class="panel" style="margin-top:24px"><h2>Recommended profile</h2><p class="muted">Minimum target: <?= e($need['tier']) ?> processor, <?= $need['ram'] ?> GB RAM and <?= $need['storage'] ?> GB storage. Scores favour adequate specifications and a department match.</p><table><thead><tr><th>Suitability</th><th>Asset</th><th>Specification</th><th>Department</th><th>Assign</th></tr></thead><tbody><?php foreach ($recommendations as $laptop): ?><tr><td><strong><?= $laptop['score'] ?>%</strong></td><td><?= e($laptop['asset_tag']) ?><br><small><?= e($laptop['brand'].' '.$laptop['model']) ?></small></td><td><?= e($laptop['processor_tier']) ?> · <?= (int)$laptop['ram_gb'] ?> GB RAM · <?= (int)$laptop['storage_gb'] ?> GB</td><td><?= e($laptop['department'] ?: 'Unallocated') ?></td><td><form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="user_id" value="<?= $selectedUser ?>"><input type="hidden" name="laptop_id" value="<?= $laptop['id'] ?>"><input type="hidden" name="department" value="<?= e($department) ?>"><input type="hidden" name="organisation_rank" value="<?= e($rank) ?>"><input type="hidden" name="work_type" value="<?= e($workType) ?>"><button>Assign this laptop</button></form></td></tr><?php endforeach; ?></tbody></table></section><?php endif; layout_end();
