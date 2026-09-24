<?php
declare(strict_types=1);
header('Location: software_details.php?id=' . (int) ($_GET['software_id'] ?? 0));
exit;
