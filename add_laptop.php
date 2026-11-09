<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login(['admin', 'super_admin']);
require_once __DIR__ . '/backend/asset_dates.php';
require_once __DIR__ . '/backend/laptop_inventory.php';

$idInput = $_GET['id'] ?? null;
$laptopId = $idInput === null ? null : filter_var($idInput, FILTER_VALIDATE_INT);
if ($laptopId === false || ($laptopId !== null && $laptopId < 1)) {
    http_response_code(400);
    exit('Invalid laptop identifier.');
}
$isEdit = $laptopId !== null;
$error = '';
$transactionStarted = false;
$generatedAssetTag = $isEdit ? null : next_laptop_asset_tag($conn);
$values = [
    'serial_number' => '',
    'brand' => '',
    'model' => '',
    'department' => '',
    'status' => 'Available',
    'purchase_date' => '',
    'warranty_expiry' => '',
    'weight_kg' => '',
    'screen_size_inches' => '',
];

if ($isEdit) {
    $existingStmt = $conn->prepare(
        'SELECT asset_tag, serial_number, brand, model, department, status, purchase_date, warranty_expiry,
                weight_kg, screen_size_inches
         FROM laptops WHERE id = ?'
    );
    $existingStmt->bind_param('i', $laptopId);
    $existingStmt->execute();
    $existingLaptop = $existingStmt->get_result()->fetch_assoc();
    $existingStmt->close();
    if (!$existingLaptop) {
        http_response_code(404);
        exit('Laptop not found.');
    }
    $generatedAssetTag = (string) $existingLaptop['asset_tag'];
    foreach ($values as $field => $_value) {
        $values[$field] = (string) ($existingLaptop[$field] ?? '');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (!$isEdit) {
        $tagInput = $_POST['asset_tag'] ?? '';
        if (!is_string($tagInput)) {
            $error = 'The assigned asset tag is invalid. Reload the page and try again.';
        } else {
            $generatedAssetTag = trim($tagInput);
        }
    }

    foreach (array_keys($values) as $field) {
        if ($isEdit && $field === 'status') {
            continue;
        }
        $input = $_POST[$field] ?? '';
        if (!is_string($input)) {
            $error = 'Provide valid laptop details.';
            break;
        }
        $values[$field] = trim($input);
    }

    try {
        if ($error !== '') {
            throw new InvalidArgumentException($error);
        }
        if ($values['serial_number'] === '' || mb_strlen($values['serial_number'], 'UTF-8') > 120) {
            throw new InvalidArgumentException('Enter a serial number of 1 to 120 characters.');
        }
        if ($values['brand'] === '' || mb_strlen($values['brand'], 'UTF-8') > 100) {
            throw new InvalidArgumentException('Enter a manufacturer of 1 to 100 characters.');
        }
        if ($values['model'] === '' || mb_strlen($values['model'], 'UTF-8') > 120) {
            throw new InvalidArgumentException('Enter a model of 1 to 120 characters.');
        }
        if (mb_strlen($values['department'], 'UTF-8') > 120) {
            throw new InvalidArgumentException('Department must be 120 characters or fewer.');
        }
        $weightKg = parse_laptop_measurement($values['weight_kg'], 0.5, 8.0, 'Laptop weight');
        $screenSizeInches = parse_laptop_measurement($values['screen_size_inches'], 10.0, 20.0, 'Screen size');
        if (!$isEdit && !in_array($values['status'], ['Available', 'Maintenance', 'Retired', 'Disposed'], true)) {
            throw new InvalidArgumentException('Choose a valid laptop status.');
        }

        $purchaseDate = validate_purchase_date($values['purchase_date']);
        $warrantyExpiry = validate_warranty_expiry($values['warranty_expiry']);
        $conn->begin_transaction();
        $transactionStarted = true;
        if ($isEdit) {
            $stmt = $conn->prepare(
                'UPDATE laptops
                 SET serial_number = ?, brand = ?, model = ?,
                     department = NULLIF(?, \'\'), purchase_date = ?, warranty_expiry = ?,
                     weight_kg = ?, screen_size_inches = ?
                 WHERE id = ?'
            );
            $stmt->bind_param(
                'ssssssddi',
                $values['serial_number'],
                $values['brand'],
                $values['model'],
                $values['department'],
                $purchaseDate,
                $warrantyExpiry,
                $weightKg,
                $screenSizeInches,
                $laptopId
            );
        } else {
            claim_laptop_asset_tag($conn, (string) $generatedAssetTag);
            $stmt = $conn->prepare(
                'INSERT INTO laptops
                    (asset_tag, serial_number, brand, model, department, status, weight_kg,
                     screen_size_inches, purchase_date, warranty_expiry)
                 VALUES (?, ?, ?, ?, NULLIF(?, \'\'), ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'ssssssddss',
                $generatedAssetTag,
                $values['serial_number'],
                $values['brand'],
                $values['model'],
                $values['department'],
                $values['status'],
                $weightKg,
                $screenSizeInches,
                $purchaseDate,
                $warrantyExpiry
            );
        }
        $stmt->execute();
        if (!$isEdit) {
            $laptopId = (int) $stmt->insert_id;
            record_laptop_asset_tag_assignment($conn, (string) $generatedAssetTag, $laptopId);
        }
        $stmt->close();

        audit($conn, $isEdit ? 'laptop_updated' : 'laptop_registered', 'laptop', $laptopId, [
            'asset_tag' => $generatedAssetTag,
        ]);
        $conn->commit();
        $transactionStarted = false;
        flash($isEdit ? 'Laptop details updated.' : 'Laptop registered successfully.');
        header('Location: dashboard.php');
        exit;
    } catch (InvalidArgumentException $exception) {
        if ($transactionStarted) {
            $conn->rollback();
            $transactionStarted = false;
        }
        $error = $exception->getMessage();
        if (!$isEdit) {
            $generatedAssetTag = next_laptop_asset_tag($conn);
        }
    } catch (mysqli_sql_exception $exception) {
        if ($transactionStarted) {
            $conn->rollback();
        }
        if ((int) $exception->getCode() === 1062) {
            $error = $isEdit
                ? 'That serial number is already registered.'
                : 'That serial number is already registered. Please submit again to generate another asset tag.';
        } else {
            error_log('Laptop registration failed: ' . $exception->getMessage());
            $error = 'The laptop could not be registered. Please try again.';
        }
    }
}

layout_start($isEdit ? 'Edit Laptop' : 'Register Laptop');
?>
<section class="panel">
    <div class="panel-header">
        <div>
            <h1><?= $isEdit ? 'Edit laptop' : 'Register laptop' ?></h1>
            <span>Manage the inventory record manually. The virtual display does not connect to or control a physical device.</span>
        </div>
    </div>
    <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <?php if ($isEdit): ?>
            <p><strong>Asset tag:</strong> <code><?= e($generatedAssetTag) ?></code></p>
        <?php else: ?>
            <p><strong>Asset tag:</strong> <code><?= e($generatedAssetTag) ?></code></p>
            <input type="hidden" name="asset_tag" value="<?= e($generatedAssetTag) ?>">
        <?php endif; ?>
        <label>Serial number
            <input name="serial_number" maxlength="120" value="<?= e($values['serial_number']) ?>" required>
        </label>
        <label>Manufacturer
            <input name="brand" maxlength="100" value="<?= e($values['brand']) ?>" required>
        </label>
        <label>Model
            <input name="model" maxlength="120" value="<?= e($values['model']) ?>" required>
        </label>
        <label>Weight (kg)
            <input type="number" name="weight_kg" min="0.5" max="8" step="0.01" value="<?= e($values['weight_kg']) ?>">
        </label>
        <label>Screen size (inches)
            <input type="number" name="screen_size_inches" min="10" max="20" step="0.1" value="<?= e($values['screen_size_inches']) ?>">
        </label>
        <label>Department
            <input name="department" maxlength="120" value="<?= e($values['department']) ?>">
        </label>
        <?php if ($isEdit): ?>
            <p>Current status: <strong><?= e($values['status']) ?></strong>. Change lifecycle status from the dashboard.</p>
        <?php else: ?>
        <label>Status
            <select name="status" required>
                <?php foreach (['Available', 'Maintenance', 'Retired', 'Disposed'] as $status): ?>
                    <option value="<?= e($status) ?>" <?= $values['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <label>Purchase date
            <input type="date" name="purchase_date" value="<?= e($values['purchase_date']) ?>">
        </label>
        <label>Warranty expiry
            <input type="date" name="warranty_expiry" value="<?= e($values['warranty_expiry']) ?>">
        </label>
        <div class="form-actions">
            <button type="submit"><?= $isEdit ? 'Save changes' : 'Register laptop' ?></button>
            <a class="button secondary" href="dashboard.php">Cancel</a>
        </div>
    </form>
</section>
<?php layout_end(); ?>
