<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

$host = '127.0.0.1';
$dbname = 'ira_assets_recovered';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    exit(
        'Database connection failed. Check $dbname, $dbUser and $dbPass. ' .
        'Details: ' . htmlspecialchars($e->getMessage())
    );
}

$users = [
    ['Brian', 'Mwangi', 'ICT', 'IT Officer'],
    ['Grace', 'Wanjiku', 'Finance', 'Accountant'],
    ['Kevin', 'Otieno', 'Human Resources', 'HR Officer'],
    ['Faith', 'Njeri', 'ICT', 'Software Developer'],
    ['Daniel', 'Kiptoo', 'Operations', 'Operations Officer'],
    ['Mary', 'Achieng', 'Finance', 'Finance Officer'],
    ['Peter', 'Kamau', 'Procurement', 'Procurement Officer'],
    ['Esther', 'Chebet', 'Human Resources', 'HR Assistant'],
    ['David', 'Maina', 'ICT', 'Systems Analyst'],
    ['Ann', 'Muthoni', 'Administration', 'Administrator'],
    ['Samuel', 'Omondi', 'Operations', 'Operations Assistant'],
    ['Lucy', 'Wambui', 'Finance', 'Accounts Assistant'],
    ['Joseph', 'Kariuki', 'ICT', 'Network Technician'],
    ['Janet', 'Atieno', 'Procurement', 'Procurement Assistant'],
    ['Michael', 'Mutua', 'Administration', 'Office Assistant'],
    ['Ruth', 'Nyambura', 'Human Resources', 'HR Officer'],
    ['Patrick', 'Barasa', 'Operations', 'Field Officer'],
    ['Alice', 'Jebet', 'ICT', 'Support Technician'],
    ['Dennis', 'Macharia', 'Finance', 'Finance Assistant'],
    ['Mercy', 'Adhiambo', 'Administration', 'Records Officer'],
];

$laptops = [
    ['HP', 'ProBook 440 G8', 'Standard', 8, 256],
    ['Dell', 'Latitude 5420', 'Standard', 8, 256],
    ['Lenovo', 'ThinkPad E14', 'Standard', 16, 512],
    ['HP', 'EliteBook 840 G8', 'Performance', 16, 512],
    ['Dell', 'Latitude 5430', 'Standard', 8, 512],
    ['Lenovo', 'ThinkPad T14', 'Performance', 16, 512],
    ['Apple', 'MacBook Air M1', 'Performance', 8, 256],
    ['HP', 'ProBook 450 G9', 'Standard', 8, 512],
    ['Dell', 'Latitude 5440', 'Performance', 16, 512],
    ['Lenovo', 'ThinkPad L14', 'Standard', 8, 256],
    ['HP', 'ZBook Firefly 15', 'Workstation', 32, 1024],
    ['Dell', 'Precision 3581', 'Workstation', 32, 1024],
    ['Lenovo', 'ThinkPad E15', 'Standard', 8, 256],
    ['HP', 'EliteBook 850 G7', 'Performance', 16, 512],
    ['Dell', 'Latitude 5410', 'Standard', 8, 256],
    ['Lenovo', 'ThinkPad X1 Carbon', 'Performance', 16, 512],
    ['Apple', 'MacBook Pro M1', 'Workstation', 16, 512],
    ['HP', 'ProBook 440 G9', 'Standard', 8, 256],
    ['Dell', 'Latitude 7420', 'Performance', 16, 512],
    ['Lenovo', 'ThinkPad T15', 'Performance', 16, 512],
];

try {
    $pdo->beginTransaction();

    /*
     * Insert users. Existing email addresses are left untouched.
     */
    $insertUser = $pdo->prepare(
        "INSERT INTO users
            (full_name, username, employee_number, email, department,
             organisation_rank, directory_source, password, role, status)
         VALUES
            (:full_name, :username, :employee_number, :email, :department,
             :organisation_rank, 'demo_seed', :password, 'user', 'active')"
    );

    $findUser = $pdo->prepare(
        "SELECT id FROM users WHERE email = :email LIMIT 1"
    );

    $userIds = [];
    $usersCreated = 0;

    foreach ($users as $index => $person) {
        [$first, $last, $department, $rank] = $person;

        $username = strtolower($first . '.' . $last);
        $email = $username . '@example.test';
        $employeeNumber = 'DEMO' . str_pad(
            (string) ($index + 1),
            3,
            '0',
            STR_PAD_LEFT
        );

        $findUser->execute(['email' => $email]);
        $existing = $findUser->fetch();

        if ($existing) {
            $userIds[] = (int) $existing['id'];
            continue;
        }

        $insertUser->execute([
            'full_name' => "$first $last",
            'username' => $username,
            'employee_number' => $employeeNumber,
            'email' => $email,
            'department' => $department,
            'organisation_rank' => $rank,
            'password' => password_hash(
                $first . '@123',
                PASSWORD_DEFAULT
            ),
        ]);

        $userIds[] = (int) $pdo->lastInsertId();
        $usersCreated++;
    }

    /*
     * Insert laptops. Existing asset tags are left untouched.
     * The first 10 laptops are assigned to 10 sample users.
     * The remaining 10 are available.
     */
    $insertLaptop = $pdo->prepare(
        "INSERT INTO laptops
            (asset_tag, serial_number, brand, model, department,
             processor_tier, ram_gb, storage_gb, assigned_to, status,
             purchase_date, warranty_expiry)
         VALUES
            (:asset_tag, :serial_number, :brand, :model, :department,
             :processor_tier, :ram_gb, :storage_gb, :assigned_to, :status,
             :purchase_date, :warranty_expiry)"
    );

    $findLaptop = $pdo->prepare(
        "SELECT id FROM laptops WHERE asset_tag = :asset_tag LIMIT 1"
    );

    $laptopsCreated = 0;

    foreach ($laptops as $index => $device) {
        [$brand, $model, $tier, $ram, $storage] = $device;

        $number = $index + 1;
        $assetTag = 'DEMO-LAP-' . str_pad(
            (string) $number, 3, '0', STR_PAD_LEFT
        );
        $serialNumber = 'DEMO-SN-' . str_pad(
            (string) $number, 5, '0', STR_PAD_LEFT
        );

        $findLaptop->execute(['asset_tag' => $assetTag]);

        if ($findLaptop->fetch()) {
            continue;
        }

        $assigned = $index < 10;
        $assignedTo = $assigned ? $userIds[$index] : null;
        $department = $assigned ? $users[$index][2] : null;

        $insertLaptop->execute([
            'asset_tag' => $assetTag,
            'serial_number' => $serialNumber,
            'brand' => $brand,
            'model' => $model,
            'department' => $department,
            'processor_tier' => $tier,
            'ram_gb' => $ram,
            'storage_gb' => $storage,
            'assigned_to' => $assignedTo,
            'status' => $assigned ? 'Assigned' : 'Available',
            'purchase_date' => sprintf(
                '%04d-%02d-15',
                2023 + intdiv($index, 8),
                ($index % 12) + 1
            ),
            'warranty_expiry' => sprintf(
                '%04d-%02d-15',
                2027 + intdiv($index, 12),
                ($index % 12) + 1
            ),
        ]);

        $laptopsCreated++;
    }

    $pdo->commit();

    echo '<h2>Demo data seeding completed</h2>';
    echo '<p>New users created: ' . $usersCreated . '</p>';
    echo '<p>New laptops created: ' . $laptopsCreated . '</p>';
    echo '<p>Existing matching records were preserved.</p>';
    echo '<p>All demo users have the role <strong>user</strong>.</p>';
    echo '<p>Demo passwords follow the format FirstName@123.</p>';
    echo '<p>Demo emails use the reserved example.test domain.</p>';
    echo '<p><strong>Delete seed_demo_data.php after running it.</strong></p>';

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo '<h2>Seeding failed</h2>';
    echo '<pre>' .
        htmlspecialchars($e->getMessage()) .
        '</pre>';
}
