<?php
require_once __DIR__ . '/db_config.php';

session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function initial_letter($value): string
{
    return preg_match('/^./u', (string) $value, $match) ? mb_strtoupper_fallback($match[0]) : strtoupper(substr((string) $value, 0, 1));
}

function mb_strtoupper_fallback($value): string
{
    $upper = strtoupper((string) $value);
    return strtr($upper, ['á'=>'Á','é'=>'É','í'=>'Í','ó'=>'Ó','ú'=>'Ú','ñ'=>'Ñ']);
}

function redirect_home(): void
{
    header('Location: index.php');
    exit;
}

$msg = '';
$msg_type = 'info';

// Cerrar sesión mediante POST para evitar cierres accidentales por enlaces externos.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Solicitud no válida. Actualiza la página e inténtalo de nuevo.');
    }
    $_SESSION = [];
    session_destroy();
    redirect_home();
}

// Inicio de sesión.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $msg = 'La sesión del formulario ha caducado. Actualiza la página e inténtalo nuevamente.';
        $msg_type = 'error';
    } else {
        $username = trim($_POST['user'] ?? '');
        $password = $_POST['pass'] ?? '';
        $stmt = $pdo->prepare('SELECT id, usuario, password FROM usuarios WHERE usuario = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Acepta la contraseña inicial del proyecto y la convierte a hash al primer acceso.
        $valid_password = $user && (
            password_verify($password, $user['password']) || hash_equals((string) $user['password'], (string) $password)
        );

        if ($valid_password) {
            if (!password_get_info($user['password'])['algo']) {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $update = $pdo->prepare('UPDATE usuarios SET password = ? WHERE id = ?');
                $update->execute([$hashed, $user['id']]);
            }
            session_regenerate_id(true);
            $_SESSION['usuario'] = $user['usuario'];
            redirect_home();
        } else {
            $msg = 'No pudimos validar tus datos. Revisa tu usuario y contraseña.';
            $msg_type = 'error';
        }
    }
}

// Registro de una nueva cita.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agendar']) && isset($_SESSION['usuario'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $msg = 'La sesión del formulario ha caducado. Actualiza la página e inténtalo nuevamente.';
        $msg_type = 'error';
    } else {
        $paciente = trim($_POST['paciente'] ?? '');
        $especialidad = trim($_POST['especialidad'] ?? '');
        $fecha = trim($_POST['fecha'] ?? '');
        $hora = trim($_POST['hora'] ?? '');
        $especialidades_validas = ['Medicina General', 'Pediatría', 'Odontología'];
        $fecha_obj = DateTime::createFromFormat('Y-m-d', $fecha);
        $fecha_valida = $fecha_obj && $fecha_obj->format('Y-m-d') === $fecha;
        $hora_valida = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $hora) === 1;

        if ($paciente === '' || strlen($paciente) > 120 || !in_array($especialidad, $especialidades_validas, true) || !$fecha_valida || !$hora_valida) {
            $msg = 'Completa todos los campos con información válida para registrar la cita.';
            $msg_type = 'error';
        } elseif ($fecha < date('Y-m-d')) {
            $msg = 'La fecha de la cita no puede ser anterior al día de hoy.';
            $msg_type = 'error';
        } else {
            $sql = 'INSERT INTO citas (paciente_nombre, especialidad, fecha, hora) VALUES (?, ?, ?, ?)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$paciente, $especialidad, $fecha, $hora]);
            redirect_home_with_message();
        }
    }
}

function redirect_home_with_message(): void
{
    $_SESSION['flash_message'] = 'La cita se registró correctamente.';
    header('Location: index.php');
    exit;
}

if (!empty($_SESSION['flash_message'])) {
    $msg = $_SESSION['flash_message'];
    $msg_type = 'success';
    unset($_SESSION['flash_message']);
}

$logged_in = isset($_SESSION['usuario']);
$citas = [];
$total_citas = 0;
$citas_hoy = 0;
$proximas_citas = 0;

if ($logged_in) {
    $citas = $pdo->query('SELECT id, paciente_nombre, especialidad, fecha, hora, estado FROM citas ORDER BY fecha DESC, hora DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
    $total_citas = count($citas);
    $today = date('Y-m-d');
    foreach ($citas as $cita) {
        if ($cita['fecha'] === $today) {
            $citas_hoy++;
        }
        if ($cita['fecha'] >= $today) {
            $proximas_citas++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f4f7fb">
    <title><?= $logged_in ? 'Panel de citas | MediCita' : 'Acceso | MediCita' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="<?= $logged_in ? 'dashboard-page' : 'login-page' ?>">
<?php if (!$logged_in): ?>
    <main class="login-shell">
        <section class="login-visual" aria-label="Presentación del sistema">
            <div class="visual-brand"><span class="brand-mark">✚</span><span>Medi<span>Cita</span></span></div>
            <div class="visual-copy">
                <span class="eyebrow light">GESTIÓN DE SALUD</span>
                <h1>Tu atención médica, organizada en un solo lugar.</h1>
                <p>Una forma sencilla y clara de administrar las citas de tu centro médico.</p>
            </div>
            <div class="visual-footer"><span class="pulse-dot"></span> Sistema local de gestión de citas</div>
            <div class="visual-orb orb-one"></div><div class="visual-orb orb-two"></div>
        </section>
        <section class="login-panel">
            <div class="login-card">
                <div class="mobile-brand"><span class="brand-mark">✚</span> Medi<span>Cita</span></div>
                <div class="login-icon">♙</div>
                <span class="eyebrow">BIENVENIDO DE NUEVO</span>
                <h2>Iniciar sesión</h2>
                <p class="muted intro">Ingresa tus credenciales para acceder al panel de administración.</p>
                <?php if ($msg !== ''): ?><div class="alert alert-<?= e($msg_type) ?>" role="alert"><span>!</span><?= e($msg) ?></div><?php endif; ?>
                <form method="POST" class="form-stack" autocomplete="on">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <label for="user">Usuario</label>
                    <div class="input-wrap"><span class="input-icon">♙</span><input id="user" type="text" name="user" placeholder="Escribe tu usuario" autocomplete="username" required autofocus></div>
                    <label for="pass">Contraseña</label>
                    <div class="input-wrap"><span class="input-icon">⌑</span><input id="pass" type="password" name="pass" placeholder="Escribe tu contraseña" autocomplete="current-password" required><button class="password-toggle" type="button" aria-label="Mostrar contraseña" onclick="togglePassword()">◉</button></div>
                    <button type="submit" name="login" class="btn btn-primary btn-login">Ingresar al sistema <span>→</span></button>
                </form>
                <p class="login-note"><span>▣</span> Acceso protegido para personal autorizado</p>
            </div>
            <footer class="panel-footer">MediCita · Sistema de gestión de citas médicas</footer>
        </section>
    </main>
<?php else: ?>
    <div class="app-layout">
        <aside class="sidebar">
            <a class="brand" href="index.php"><span class="brand-mark">✚</span><span>Medi<span>Cita</span></span></a>
            <div class="side-caption">MENÚ PRINCIPAL</div>
            <a class="nav-link active" href="#resumen"><span class="nav-icon">▦</span>Resumen</a>
            <a class="nav-link" href="#nueva-cita"><span class="nav-icon">＋</span>Agendar cita</a>
            <a class="nav-link" href="#listado-citas"><span class="nav-icon">▤</span>Listado de citas</a>
            <div class="sidebar-bottom">
                <div class="help-card"><div class="help-symbol">✦</div><strong>Organiza tu atención</strong><p>Administra las citas de forma rápida y sencilla.</p></div>
                <div class="sidebar-user"><div class="avatar"><?= e(initial_letter($_SESSION['usuario'])) ?></div><div class="user-info"><strong><?= e($_SESSION['usuario']) ?></strong><small>Administrador</small></div><form method="POST"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><button type="submit" name="logout" class="logout-icon" title="Cerrar sesión" aria-label="Cerrar sesión">⇥</button></form></div>
            </div>
        </aside>
        <main class="main-content">
            <header class="topbar"><div><span class="breadcrumb">Panel de administración</span><h1>Resumen general</h1></div><div class="topbar-right"><span class="today-chip">◷ <?= e(date('d/m/Y')) ?></span><div class="top-avatar"><?= e(initial_letter($_SESSION['usuario'])) ?></div></div></header>
            <section class="welcome-banner" id="resumen"><div><span class="eyebrow light">PANEL DE CONTROL</span><h2>¡Hola, <?= e($_SESSION['usuario']) ?>! 👋</h2><p>Este es el resumen de la actividad de tu sistema médico.</p></div><div class="banner-art" aria-hidden="true"><span class="banner-cross">✚</span><span class="banner-ring"></span></div></section>
            <?php if ($msg !== ''): ?><div class="alert alert-<?= e($msg_type) ?> dashboard-alert" role="alert"><span><?= $msg_type === 'success' ? '✓' : '!' ?></span><?= e($msg) ?></div><?php endif; ?>
            <section class="stats-grid" aria-label="Estadísticas de citas">
                <article class="stat-card"><div class="stat-top"><span>Total de citas</span><span class="stat-icon icon-blue">▤</span></div><strong><?= $total_citas ?></strong><small>Registros almacenados</small></article>
                <article class="stat-card"><div class="stat-top"><span>Citas para hoy</span><span class="stat-icon icon-green">◷</span></div><strong><?= $citas_hoy ?></strong><small>Programadas para hoy</small></article>
                <article class="stat-card"><div class="stat-top"><span>Próximas citas</span><span class="stat-icon icon-purple">▣</span></div><strong><?= $proximas_citas ?></strong><small>Hoy y fechas futuras</small></article>
            </section>
            <div class="content-grid">
                <section class="section-card booking-card" id="nueva-cita">
                    <div class="section-heading"><div><span class="eyebrow">NUEVO REGISTRO</span><h2>Agendar una cita</h2><p>Completa los datos del paciente para programar su atención.</p></div><span class="heading-icon">＋</span></div>
                    <form method="POST" class="booking-form">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <div class="field full"><label for="paciente">Nombre del paciente <span>*</span></label><input id="paciente" type="text" name="paciente" maxlength="120" placeholder="Ej. María Fernanda López" required></div>
                        <div class="field full"><label for="especialidad">Especialidad médica <span>*</span></label><select id="especialidad" name="especialidad" required><option value="" disabled selected>Selecciona una especialidad</option><option value="Medicina General">Medicina General</option><option value="Pediatría">Pediatría</option><option value="Odontología">Odontología</option></select></div>
                        <div class="field"><label for="fecha">Fecha de la cita <span>*</span></label><input id="fecha" type="date" name="fecha" min="<?= e(date('Y-m-d')) ?>" required></div>
                        <div class="field"><label for="hora">Hora de la cita <span>*</span></label><input id="hora" type="time" name="hora" required></div>
                        <button type="submit" name="agendar" class="btn btn-primary submit-booking">Guardar cita <span>→</span></button>
                    </form>
                </section>
                <section class="section-card tips-card"><div class="tips-icon">✦</div><span class="eyebrow">RECUERDA</span><h3>Una buena organización empieza aquí.</h3><p>Verifica que el nombre, la especialidad y el horario sean correctos antes de guardar cada cita.</p><div class="tips-divider"></div><div class="tips-meta"><span class="pulse-dot"></span> Datos guardados localmente</div></section>
            </div>
            <section class="section-card appointments-card" id="listado-citas">
                <div class="section-heading list-heading"><div><span class="eyebrow">CONTROL DE AGENDA</span><h2>Citas registradas</h2><p>Consulta los turnos almacenados en la base de datos.</p></div><div class="search-box"><span>⌕</span><input id="searchAppointments" type="search" placeholder="Buscar paciente o especialidad..." aria-label="Buscar citas"></div></div>
                <?php if (count($citas) > 0): ?>
                    <div class="table-wrap"><table><thead><tr><th>Paciente</th><th>Especialidad</th><th>Fecha</th><th>Hora</th><th>Estado</th></tr></thead><tbody id="appointmentsBody">
                    <?php foreach ($citas as $cita): ?>
                        <tr><td><div class="patient-cell"><span class="patient-avatar"><?= e(initial_letter($cita['paciente_nombre'])) ?></span><strong><?= e($cita['paciente_nombre']) ?></strong></div></td><td><?= e($cita['especialidad']) ?></td><td><?= e(date('d/m/Y', strtotime($cita['fecha']))) ?></td><td><?= e(substr($cita['hora'], 0, 5)) ?></td><td><span class="status-pill">● <?= e($cita['estado'] ?: 'Registrada') ?></span></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <div class="table-empty" id="noSearchResults" hidden>No se encontraron citas que coincidan con tu búsqueda.</div>
                <?php else: ?>
                    <div class="empty-state"><div class="empty-icon">▤</div><strong>Aún no hay citas registradas</strong><p>Cuando agendes una cita, aparecerá en este listado.</p><a href="#nueva-cita">Agendar la primera cita <span>→</span></a></div>
                <?php endif; ?>
                <div class="table-foot"><span>Mostrando <?= $total_citas ?> <?= $total_citas === 1 ? 'registro' : 'registros' ?></span><span>Base de datos SQLite · Local</span></div>
            </section>
            <footer class="main-footer">© <?= e(date('Y')) ?> MediCita · Sistema de gestión de citas médicas</footer>
        </main>
    </div>
<?php endif; ?>
<script>
function togglePassword() {
    const field = document.getElementById('pass');
    const button = document.querySelector('.password-toggle');
    if (!field || !button) return;
    field.type = field.type === 'password' ? 'text' : 'password';
    button.setAttribute('aria-label', field.type === 'password' ? 'Mostrar contraseña' : 'Ocultar contraseña');
}
const searchInput = document.getElementById('searchAppointments');
if (searchInput) {
    searchInput.addEventListener('input', function () {
        const query = this.value.toLocaleLowerCase('es');
        const rows = document.querySelectorAll('#appointmentsBody tr');
        let visible = 0;
        rows.forEach(row => {
            const match = row.textContent.toLocaleLowerCase('es').includes(query);
            row.hidden = !match;
            if (match) visible++;
        });
        const empty = document.getElementById('noSearchResults');
        if (empty) empty.hidden = visible !== 0;
    });
}
</script>
</body>
</html>
