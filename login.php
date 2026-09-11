<?php
require_once "config/database.php";
require_once "includes/functions.php";
require_once "includes/pet_qr.php";

if (is_logged_in()) {
    if (in_array($_SESSION['role'] ?? '', ['admin','veterinarian','staff'], true)) {
        redirect_to(pet_qr_login_destination($_SESSION['role']));
    }
    $_SESSION = [];
    session_destroy();
}

$error = "";
$notice = '';
$fieldErrors = [];
$submittedEmail = '';
$appEnvironment = strtolower(trim((string)(getenv('VETRIX_APP_ENV') ?: 'production')));
$requestHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
$requestHost = preg_replace('/:\\d+$/', '', $requestHost);
$isLocalRequest = in_array($requestHost, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
$showDemoAccounts = $isLocalRequest || in_array($appEnvironment, ['development', 'dev', 'local', 'testing', 'test'], true);
if (isset($_GET['expired'])) $notice = 'Your session expired after two hours of inactivity. Sign in again.';
if (isset($_GET['inactive'])) $notice = 'Your account is no longer active. Contact the clinic administrator.';
if (isset($_GET['signed_out'])) $notice = 'Signed out successfully. Your session has ended.';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_or_fail();
    $email = strtolower(trim($_POST["email"] ?? ""));
    $password = $_POST["password"] ?? "";
    $submittedEmail = $email;

    if ($email === '') {
        $fieldErrors['email'] = 'Enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fieldErrors['email'] = 'Enter a valid email address.';
    }
    if ($password === '') {
        $fieldErrors['password'] = 'Enter your password.';
    }

    if (!$fieldErrors) {
        $stmt = $conn->prepare("SELECT id, full_name, email, password, role, status, otp_verified_at, failed_login_attempts, locked_until, deleted_at FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user && !empty($user['deleted_at'])) {
            $error = "This account is no longer available. Contact the clinic administrator.";
        } elseif ($user && !empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            $error = "Too many unsuccessful attempts. Try again after " . date('h:i A', strtotime($user['locked_until'])) . ".";
        } else {
            $valid = $user && password_verify($password, $user['password']);

            if ($user && $valid) {
                $conn->query("UPDATE users SET failed_login_attempts=0, locked_until=NULL, last_login_at=NOW() WHERE id=" . (int)$user['id']);
                if ($user['role'] === 'client') {
                    $error = "This account is not authorized for clinic staff access.";
                } elseif (!in_array($user['status'], ['active','approved'], true)) {
                    $error = "Your account is not active. Contact the administrator.";
                } else {
                    $conn->query("UPDATE users SET status='active' WHERE id=" . (int)$user['id']);
                    $user['status'] = 'active';
                }

                if ($error === '') {
                    session_regenerate_id(true);
                    unset($_SESSION['csrf_token']);
                    $_SESSION['last_activity_at'] = time();
                    $_SESSION["user_id"] = $user["id"];
                    $_SESSION["full_name"] = $user["full_name"];
                    $_SESSION["email"] = $user["email"];
                    $_SESSION["role"] = $user["role"];
                    redirect_to(pet_qr_login_destination($user["role"]));
                }
            } elseif ($user) {
                $attempts = (int)$user['failed_login_attempts'] + 1;
                $locked = $attempts >= 5 ? date('Y-m-d H:i:s', strtotime('+15 minutes')) : null;
                $stmt = $conn->prepare("UPDATE users SET failed_login_attempts=?, locked_until=? WHERE id=?");
                $stmt->bind_param('isi', $attempts, $locked, $user['id']);
                $stmt->execute();
                if ($attempts >= 5) {
                    $error = "Too many unsuccessful attempts. This account is locked for 15 minutes.";
                } else {
                    $fieldErrors['credentials'] = 'The email or password is incorrect.';
                }
            } else {
                $fieldErrors['credentials'] = 'The email or password is incorrect.';
            }
        }
    }
}

$emailHasError = isset($fieldErrors['email']) || isset($fieldErrors['credentials']);
$passwordHasError = isset($fieldErrors['password']) || isset($fieldErrors['credentials']);

$title = "Login - Vetrix";
include "includes/header.php";
?>

<div class="auth-shell">
    <section class="auth-visual" aria-label="Vetrix welcome area">
        <a class="auth-brand" href="index.php">
            <span class="auth-brand-logo"><img src="<?= e(app_url(vetrix_brand_logo_path())) ?>" alt="Vetrix logo"></span>
            <b>Vetrix</b>
        </a>
        <div class="auth-visual-copy">
            <span class="auth-kicker">Veterinary clinic system</span>
            <h1>Welcome to Vetrix.</h1>
            <p>Sign in to continue to the clinic tools and records available to your account.</p>
        </div>

        <div class="auth-artwork" aria-label="Friendly golden retriever welcoming you to Vetrix">
            <span class="auth-artwork-orbit auth-artwork-orbit-one" aria-hidden="true"></span>
            <span class="auth-artwork-orbit auth-artwork-orbit-two" aria-hidden="true"></span>
            <img src="<?= e(app_url('assets/images/vetrix-login-dog.jpg')) ?>" alt="Friendly golden retriever waving hello">
            <div class="auth-care-note">
                <span class="auth-care-note-icon" aria-hidden="true"><?= ui_icon('paw') ?></span>
                <span><b>Connected clinic care</b><small>Schedules, records, and daily workflows in one calm workspace.</small></span>
            </div>
        </div>
    </section>

    <main class="auth-panel" id="mainContent">
        <div class="auth-card">
            <div class="auth-mobile-brand"><span class="auth-brand-logo"><img src="<?= e(app_url(vetrix_brand_logo_path())) ?>" alt="Vetrix logo"></span><b>Vetrix</b></div>
            <div class="auth-heading">
                <span>Welcome back</span>
                <h2>Sign in to your account</h2>
                <p>Use the email and password assigned to your role.</p>
            </div>

            <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
            <?php if ($notice): ?><div class="auth-note<?= isset($_GET['signed_out']) ? ' signed-out-note' : '' ?>" role="status"><?php if(isset($_GET['signed_out'])): ?><span class="auth-note-icon"><?=ui_icon('check')?></span><?php endif; ?><span><?= e($notice) ?></span></div><?php endif; ?>

            <?php if ($showDemoAccounts): ?>
            <details class="demo-accounts" open>
                <summary><span>Quick demo access</span><small>Select a role to fill the form</small></summary>
                <div class="demo-account-list">
                    <button type="button" data-demo-account="admin@vetclinic.test" data-demo-password="VetrixDemo!2026" aria-pressed="false"><span>Admin</span><code>admin@vetclinic.test</code><em>Use</em></button>
                    <button type="button" data-demo-account="vet@vetclinic.test" data-demo-password="VetrixDemo!2026" aria-pressed="false"><span>Veterinarian</span><code>vet@vetclinic.test</code><em>Use</em></button>
                    <button type="button" data-demo-account="staff@vetclinic.test" data-demo-password="VetrixDemo!2026" aria-pressed="false"><span>Staff</span><code>staff@vetclinic.test</code><em>Use</em></button>
                </div>
            </details>
            <?php endif; ?>

            <form method="POST" class="auth-form" id="loginForm" data-login-form novalidate>
                <?= csrf_field() ?>
                <div class="auth-field<?= $emailHasError ? ' has-error' : '' ?>" data-auth-field>
                    <label for="loginEmail">Email address</label>
                    <div class="auth-input-wrap"><?= ui_icon('mail') ?><input id="loginEmail" class="form-control form-control-lg login-input" name="email" type="email" autocomplete="email" inputmode="email" placeholder="name@clinic.com" required value="<?= e($submittedEmail) ?>" aria-describedby="loginEmailError loginPasswordError"<?= $emailHasError ? ' aria-invalid="true"' : '' ?>></div>
                    <p class="auth-field-error" id="loginEmailError" role="alert"<?= isset($fieldErrors['email']) ? '' : ' hidden' ?>><?= ui_icon('alert') ?><span><?= e($fieldErrors['email'] ?? '') ?></span></p>
                </div>

                <div class="auth-field<?= $passwordHasError ? ' has-error' : '' ?>" data-auth-field>
                    <label for="loginPassword">Password</label>
                    <div class="auth-input-wrap"><?= ui_icon('lock') ?><input id="loginPassword" class="form-control form-control-lg login-input" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required aria-describedby="loginPasswordError"<?= $passwordHasError ? ' aria-invalid="true"' : '' ?>><button class="password-toggle" type="button" data-password-toggle="loginPassword" aria-label="Show password"><span data-password-show-icon><?= ui_icon('eye') ?></span><span data-password-hide-icon hidden><?= ui_icon('eye-off') ?></span><span data-password-toggle-label>Show</span></button></div>
                    <p class="auth-field-error" id="loginPasswordError" role="alert"<?= $passwordHasError ? '' : ' hidden' ?>><?= ui_icon('alert') ?><span><?= e($fieldErrors['password'] ?? $fieldErrors['credentials'] ?? '') ?></span></p>
                </div>

                <p class="auth-recovery-help"><?= ui_icon('help-circle') ?><span><b>Forgot your password?</b> Contact your clinic administrator for account recovery.</span></p>

                <button class="btn btn-primary btn-lg w-100 login-btn" type="submit" data-login-submit>
                    <span class="login-btn-label">Sign in</span>
                    <span class="login-btn-loading" aria-hidden="true"><span class="auth-loading-spinner"></span>Signing in&hellip;</span>
                </button>
            </form>
        </div>
    </main>
</div>

<script>
document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle);
        if (!input) return;
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.querySelector('[data-password-show-icon]').hidden = show;
        button.querySelector('[data-password-hide-icon]').hidden = !show;
        button.querySelector('[data-password-toggle-label]').textContent = show ? 'Hide' : 'Show';
        button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
});

const loginForm = document.querySelector('[data-login-form]');
if (loginForm) {
    const emailInput = loginForm.querySelector('#loginEmail');
    const passwordInput = loginForm.querySelector('#loginPassword');
    const submitButton = loginForm.querySelector('[data-login-submit]');

    document.querySelectorAll('[data-demo-account]').forEach((button) => {
        button.addEventListener('click', () => {
            emailInput.value = button.dataset.demoAccount || '';
            passwordInput.value = button.dataset.demoPassword || '';
            emailInput.dispatchEvent(new Event('input', { bubbles: true }));
            passwordInput.dispatchEvent(new Event('input', { bubbles: true }));
            document.querySelectorAll('[data-demo-account]').forEach((option) => option.setAttribute('aria-pressed', option === button ? 'true' : 'false'));
            submitButton.focus();
        });
    });

    const setFieldError = (input, message) => {
        const field = input.closest('[data-auth-field]');
        const errorElement = field?.querySelector('.auth-field-error');
        field?.classList.toggle('has-error', Boolean(message));
        if (message) input.setAttribute('aria-invalid', 'true');
        else input.removeAttribute('aria-invalid');
        if (errorElement) {
            errorElement.hidden = !message;
            const copy = errorElement.querySelector('span');
            if (copy) copy.textContent = message;
        }
    };

    emailInput?.addEventListener('input', () => setFieldError(emailInput, ''));
    passwordInput?.addEventListener('input', () => setFieldError(passwordInput, ''));

    loginForm.addEventListener('submit', (event) => {
        let isValid = true;
        const email = emailInput?.value.trim() || '';
        if (!email) {
            setFieldError(emailInput, 'Enter your email address.');
            isValid = false;
        } else if (!emailInput.validity.valid) {
            setFieldError(emailInput, 'Enter a valid email address.');
            isValid = false;
        }
        if (!passwordInput?.value) {
            setFieldError(passwordInput, 'Enter your password.');
            isValid = false;
        }
        if (!isValid) {
            event.preventDefault();
            loginForm.querySelector('[aria-invalid="true"]')?.focus();
            return;
        }
        submitButton.disabled = true;
        submitButton.classList.add('is-loading');
        submitButton.setAttribute('aria-busy', 'true');
    });
}
</script>

<?php include "includes/footer.php"; ?>
