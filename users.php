<?php

require __DIR__ . '/bootstrap.php';

require_login([
    'super_admin'
]);


/*
 * =========================================================
 * USERS
 * =========================================================
 */

$users = $conn->query(
    "SELECT
        u.id,
        u.full_name,
        u.email,
        u.role,
        u.status,
        u.created_at,

        COUNT(l.id) AS assigned_assets

     FROM users u

     LEFT JOIN laptops l
        ON l.assigned_to = u.id
        AND l.status = 'Assigned'

     GROUP BY
        u.id,
        u.full_name,
        u.email,
        u.role,
        u.status,
        u.created_at

     ORDER BY
        FIELD(
            u.role,
            'super_admin',
            'admin',
            'user'
        ),
        u.full_name"
);


$totalUsers = $conn->query(
    "SELECT COUNT(*) AS total
     FROM users"
)->fetch_assoc()['total'];


$activeUsers = $conn->query(
    "SELECT COUNT(*) AS total
     FROM users
     WHERE status = 'active'"
)->fetch_assoc()['total'];


$admins = $conn->query(
    "SELECT COUNT(*) AS total
     FROM users
     WHERE role IN ('admin','super_admin')"
)->fetch_assoc()['total'];


layout_start('Users');

?>


<!-- =========================================================
     HERO
     ========================================================= -->

<section class="hero">

    <div>

        <div class="eyebrow">
            USER MANAGEMENT
        </div>

        <h2>
            Registered Users
        </h2>

        <p>
            Manage and monitor accounts registered
            within the IRA Asset Management System.
        </p>

    </div>


    <a
        class="button"
        href="register.php"
    >
        + Register User
    </a>

</section>


<!-- =========================================================
     USER METRICS
     ========================================================= -->

<section class="metrics-grid">

    <article class="metric-card">

        <div class="metric-top">

            <span>
                Total Users
            </span>

            <div class="metric-icon">
                ♙
            </div>

        </div>

        <strong class="metric-number">
            <?= e((string)$totalUsers) ?>
        </strong>

        <span class="metric-label">
            Registered accounts
        </span>

    </article>


    <article class="metric-card">

        <div class="metric-top">

            <span>
                Active Users
            </span>

            <div class="metric-icon">
                ✓
            </div>

        </div>

        <strong class="metric-number">
            <?= e((string)$activeUsers) ?>
        </strong>

        <span class="metric-label">
            Currently active
        </span>

    </article>


    <article class="metric-card">

        <div class="metric-top">

            <span>
                Administrators
            </span>

            <div class="metric-icon">
                ★
            </div>

        </div>

        <strong class="metric-number">
            <?= e((string)$admins) ?>
        </strong>

        <span class="metric-label">
            Admin and super admin accounts
        </span>

    </article>

</section>


<!-- =========================================================
     USER REGISTER
     ========================================================= -->

<section class="panel">

    <div class="panel-header">

        <div>

            <h2>
                Account Register
            </h2>

            <span>
                All registered users and their assigned assets.
            </span>

        </div>

    </div>


    <div class="table-wrapper">

        <table class="asset-table">

            <thead>

                <tr>

                    <th>
                        User
                    </th>

                    <th>
                        Email
                    </th>

                    <th>
                        Role
                    </th>

                    <th>
                        Status
                    </th>

                    <th>
                        Assigned Assets
                    </th>

                    <th>
                        Created
                    </th>

                </tr>

            </thead>


            <tbody>

            <?php

            $hasUsers = false;

            while (
                $user = $users->fetch_assoc()
            ):

                $hasUsers = true;

            ?>

                <tr>

                    <td>

                        <div class="user-table-cell">

                            <div class="table-avatar">

                                <?= e(
                                    strtoupper(
                                        substr(
                                            $user['full_name'],
                                            0,
                                            1
                                        )
                                    )
                                ) ?>

                            </div>

                            <strong>
                                <?= e(
                                    $user['full_name']
                                ) ?>
                            </strong>

                        </div>

                    </td>


                    <td>

                        <?= e(
                            $user['email']
                        ) ?>

                    </td>


                    <td>

                        <span
                            class="role-table-badge
                            role-<?= e(
                                $user['role']
                            ) ?>"
                        >

                            <?= e(
                                ucwords(
                                    str_replace(
                                        '_',
                                        ' ',
                                        $user['role']
                                    )
                                )
                            ) ?>

                        </span>

                    </td>


                    <td>

                        <?php if (
                            $user['status'] === 'active'
                        ): ?>

                            <span
                                class="status-badge status-active"
                            >
                                Active
                            </span>

                        <?php else: ?>

                            <span
                                class="status-badge status-inactive"
                            >
                                <?= e(
                                    ucfirst(
                                        $user['status']
                                    )
                                ) ?>
                            </span>

                        <?php endif; ?>

                    </td>


                    <td>

                        <strong>
                            <?= e(
                                (string)
                                $user['assigned_assets']
                            ) ?>
                        </strong>

                    </td>


                    <td>

                        <span class="date-text">

                            <?= e(
                                $user['created_at']
                            ) ?>

                        </span>

                    </td>

                </tr>

            <?php endwhile; ?>


            <?php if (!$hasUsers): ?>

                <tr>

                    <td
                        colspan="6"
                        class="empty-table"
                    >
                        No users registered.
                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>


<?php

layout_end();

?>