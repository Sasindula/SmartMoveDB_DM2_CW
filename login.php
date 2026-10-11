<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

$error = '';

function loginHtml($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$dashboardPaths = [
    'PASSENGER' => '/SmartMoveDB/views/passenger_dashboard.php',
    'DRIVER' => '/SmartMoveDB/views/driver_dashboard.php',
    'ADMIN' => '/SmartMoveDB/views/admin_dashboard.php'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = is_string($_POST['username'] ?? null)
        ? trim($_POST['username'])
        : '';

    $password = is_string($_POST['password'] ?? null)
        ? $_POST['password']
        : '';

    $token = is_string($_POST['csrf_token'] ?? null)
        ? $_POST['csrf_token']
        : '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Please reload the page and try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } else {
        try {
            $stmt = $oracle_conn->prepare(
                'SELECT USER_ID, USERNAME, PASSWORD_HASH, ROLE, REFERENCE_ID
                 FROM SYSTEM_USERS
                 WHERE LOWER(USERNAME) = LOWER(:username)'
            );

            $stmt->execute([':username' => $username]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                !$user ||
                !password_verify($password, $user['password_hash'])
            ) {
                $error = 'Invalid username or password.';
            } else {
                $role = strtoupper((string)$user['role']);

                if (!isset($dashboardPaths[$role])) {
                    $error = 'This account has an invalid role.';
                } elseif (
                    $role !== 'ADMIN' &&
                    (int)$user['reference_id'] < 1
                ) {
                    $error = 'This account is missing its profile reference.';
                } else {
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = (int)$user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $role;
                    $_SESSION['reference_id'] = $user['reference_id'];
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    try {
                        if (function_exists('log_activity')) {
                            log_activity('User logged in', $user['username']);
                        }
                    } catch (Throwable $e) {
                        error_log('Login logging: ' . $e->getMessage());
                    }

                    header('Location: ' . $dashboardPaths[$role]);
                    exit;
                }
            }
        } catch (PDOException $e) {
            error_log('Login: ' . $e->getMessage());
            $error = 'Unable to sign in. Please try again.';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<main class="container">
    <section class="panel auth-panel">
        <span class="eyebrow">WELCOME TO SMARTMOVE</span>
        <h2>Sign in to your account</h2>
        <p class="muted">Access your bookings, schedules or admin tools.</p>

        <?php if ($error): ?>
            <div class="alert"><?= loginHtml($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="auth-form">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= loginHtml($_SESSION['csrf_token']) ?>"
            >

            <label>
                Username
                <input
                    type="text"
                    name="username"
                    autocomplete="username"
                    maxlength="50"
                    value="<?= loginHtml($username ?? '') ?>"
                    required
                >
            </label>

            <label>
                Password
                <input
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >
            </label>

            <button type="submit" class="button">Sign In →</button>
        </form>

        <p class="muted">
            New to SmartMove?
            <a href="register.php" class="teal">Passenger Registration</a> &nbsp;|&nbsp; <a href="driver_register.php" class="teal">Driver Registration (Admin Approval)</a>
        </p>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>